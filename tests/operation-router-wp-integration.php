<?php

/**
 * Clean-WordPress integration test for the deterministic operation router.
 * Run with: wp eval-file tests/operation-router-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function op_integration_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$secret = str_repeat('a', 64);
update_option('takka_bridge_secret', $secret, false);
update_option('takka_bridge_user_id', 1, false);

function op_integration_call(string $operation, array $params = []): array
{
    $secret = (string) get_option('takka_bridge_secret');
    $inner = [
        'request_id' => 'integration-' . substr(hash('sha256', $operation . '|' . wp_json_encode($params)), 0, 32),
        'action' => 'rest.call',
        'params' => [
            'method' => 'POST',
            'route' => '/takka-v099/v1/operate',
            'query' => [],
            'body' => ['operation' => $operation, 'params' => $params],
        ],
    ];
    $transport = [
        'action' => 'envelope',
        'params' => ['payload_b64' => base64_encode(wp_json_encode($inner, JSON_UNESCAPED_SLASHES))],
    ];
    $body = wp_json_encode($transport, JSON_UNESCAPED_SLASHES);
    $timestamp = (string) time();
    $outer = '/takka-bridge/v1/execute';
    $signature = hash_hmac('sha256', $timestamp . "\nPOST\n" . $outer . "\n" . hash('sha256', $body), $secret);

    $request = new WP_REST_Request('POST', $outer);
    $request->set_header('content-type', 'application/json');
    $request->set_header('X-TakKa-Timestamp', $timestamp);
    $request->set_header('X-TakKa-Signature', $signature);
    $request->set_body($body);
    $response = rest_do_request($request);
    if (is_wp_error($response)) {
        op_integration_fail('Outer operation request failed: ' . $response->get_error_message());
    }
    $response = rest_ensure_response($response);
    if ($response->get_status() >= 500) {
        op_integration_fail('Outer operation HTTP ' . $response->get_status());
    }
    $data = $response->get_data();
    if (!is_array($data)) {
        op_integration_fail('Outer operation response was not an object.');
    }
    return $data;
}

function op_integration_v099_payload(array $outer): array
{
    $payload = $outer['data'] ?? null;
    if (!is_array($payload)) {
        op_integration_fail('Missing v0.9.9 payload.');
    }
    return $payload;
}

function op_integration_core_payload(array $v099): array
{
    $wrapper = $v099['result']['data'] ?? null;
    if (!is_array($wrapper)) {
        op_integration_fail('Missing nested core REST wrapper.');
    }
    $payload = $wrapper['data'] ?? null;
    if (!is_array($payload)) {
        op_integration_fail('Missing nested core REST payload.');
    }
    return $payload;
}

$post_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'WPAB operation integration',
    'post_content' => "line one\nline two target\nline three",
], true);
if (is_wp_error($post_id) || (int) $post_id < 1) {
    op_integration_fail('Could not create integration test post.');
}
$post_id = (int) $post_id;

try {
    // Exercise the real Direct Runtime command adapter, not just a hand-built
    // signed envelope. One operation command must cause one outer dispatch.
    $runtime_execute = new ReflectionMethod(TakKa_WordPress_Bridge_Direct_Runtime::class, 'execute_command');
    $runtime_execute->setAccessible(true);
    $unexpected_dispatches = 0;
    $observe_dispatch = static function ($response) use (&$unexpected_dispatches) {
        $unexpected_dispatches++;
        return $response;
    };
    add_filter('rest_pre_dispatch', $observe_dispatch, -999, 1);
    foreach (['/takka-bridge/v1/execute', '/wp/v2/posts/1?context=edit', '/wp/v2/posts/1#fragment'] as $bad_route) {
        $bad = $runtime_execute->invoke(null, ['type' => 'rest', 'method' => 'POST', 'route' => $bad_route], 'invalid-route');
        if (!empty($bad['ok']) || ($bad['status'] ?? null) !== 400) {
            op_integration_fail('Invalid/recursive route did not fail before dispatch.');
        }
    }
    remove_filter('rest_pre_dispatch', $observe_dispatch, -999);
    if ($unexpected_dispatches !== 0) op_integration_fail('Malformed route caused a needless REST roundtrip.');
    $runtime_call = static function (string $id, string $operation, array $params = []) use ($runtime_execute): array {
        return $runtime_execute->invoke(null, ['type' => 'operation', 'operation' => $operation, 'params' => $params], $id);
    };
    // A supplied user URL must determine the object without an ID lookup by the client.
    $target_url = home_url('/?p=' . $post_id);
    $target_get = $runtime_call('runtime-target-url', 'post.get', ['target_url' => $target_url]);
    $target_data = $target_get['data']['data']['result']['data']['data'] ?? [];
    if (empty($target_get['ok']) || (int) ($target_data['id'] ?? 0) !== $post_id) {
        op_integration_fail('URL-only metadata read selected the wrong post.');
    }
    $title_before = get_post($post_id)->post_title;
    foreach ([
        ['target_url' => $target_url, 'post_id' => $post_id + 1000000],
        ['target_url' => 'https://wrong-site.invalid/?p=' . $post_id, 'post_id' => $post_id],
    ] as $index => $wrong_target) {
        $wrong_target['fields'] = ['title' => 'Must not apply'];
        $rejected = $runtime_call('runtime-target-conflict-' . $index, 'post.update', $wrong_target);
        if (!empty($rejected['ok']) || (int) ($rejected['status'] ?? 0) !== 409
            || get_post($post_id)->post_title !== $title_before) {
            op_integration_fail('Conflicting target must be rejected without mutation.');
        }
    }
    $target_update = $runtime_call('runtime-target-update', 'post.update', [
        'target_url' => $target_url, 'fields' => ['title' => $title_before . ' URL verified'],
    ]);
    if (empty($target_update['ok']) || get_post($post_id)->post_title !== $title_before . ' URL verified') {
        op_integration_fail('URL-only update failed.');
    }
    $target_range = $runtime_call('runtime-target-range', 'post.content.read_range', [
        'target_url' => $target_url, 'start_line' => 2, 'max_lines' => 1,
    ]);
    if (empty($target_range['ok'])) op_integration_fail('Content operations must resolve the same URL.');
    $missing_target = $runtime_call('runtime-target-missing', 'post.update', [
        'target_url' => home_url('/?p=2147483647'), 'fields' => ['title' => 'Must not apply'],
    ]);
    if (!empty($missing_target['ok']) || (int) ($missing_target['status'] ?? 0) !== 404) {
        op_integration_fail('Missing URL target must fail without falling back to another ID.');
    }
    $runtime_get = $runtime_call('runtime-metadata', 'post.get', ['post_id' => $post_id]);
    if (empty($runtime_get['ok']) || ($runtime_get['data']['data']['operation'] ?? '') !== 'post.get') {
        op_integration_fail('Native operation command did not reach the guarded router.');
    }
    $missing_id = 2147483647;
    foreach ([$post_id . 'junk', [$post_id], (float) $post_id + 0.5, true] as $index => $invalid_id) {
        $invalid = $runtime_call('runtime-invalid-id-' . $index, 'post.update', [
            'post_id' => $invalid_id, 'fields' => ['title' => 'Wrong post must not be edited'],
        ]);
        if (!empty($invalid['ok']) || (int) ($invalid['status'] ?? 0) !== 400) {
            op_integration_fail('Malformed post ID was coerced into a valid mutation target.');
        }
    }
    $unknown = $runtime_call('runtime-unknown', 'definitely.unknown');
    if (!empty($unknown['ok']) || (int) ($unknown['status'] ?? 0) !== 400) {
        op_integration_fail('Unknown operation was not rejected by Direct Runtime.');
    }
    $missing = $runtime_call('runtime-missing', 'post.get', ['post_id' => $missing_id]);
    if (!empty($missing['ok']) || (int) ($missing['status'] ?? 0) !== 404) {
        op_integration_fail('Missing post was falsely reported as successful by Direct Runtime.');
    }
    $partial = $runtime_call('runtime-partial', 'readonly.batch', ['operations' => [
        ['operation' => 'post.content.read_range', 'params' => ['post_id' => $post_id, 'start_line' => 1, 'max_lines' => 1]],
        ['operation' => 'post.content.read_range', 'params' => ['post_id' => $missing_id, 'start_line' => 1]],
    ]]);
    if (!empty($partial['ok']) || (int) ($partial['status'] ?? 0) !== 207) {
        op_integration_fail('Partial read batch was falsely reported as complete success.');
    }
    $partial_payload = $partial['data']['data']['result']['data']['data'] ?? [];
    if (($partial_payload['failed_count'] ?? null) !== 1
        || empty($partial_payload['results'][0]['ok'])
        || !empty($partial_payload['results'][1]['ok'])) {
        op_integration_fail('Partial batch did not isolate the failed read.');
    }
    foreach (['', []] as $index => $selector) {
        $bounded = $runtime_call('runtime-empty-fields-' . $index, 'post.get', [
            'post_id' => $post_id, 'query' => ['context' => 'edit', '_fields' => $selector],
        ]);
        $bounded_post = $bounded['data']['data']['result']['data']['data'] ?? null;
        if (empty($bounded['ok']) || !is_array($bounded_post) || array_key_exists('content', $bounded_post)) {
            op_integration_fail('Empty _fields leaked the full post through Direct Runtime.');
        }
    }
    $nested_content = $runtime_call('runtime-nested-content', 'post.get', [
        'post_id' => $post_id, 'query' => ['context' => 'edit', '_fields' => 'id,content.raw'],
    ]);
    if (!empty($nested_content['ok']) || (int) ($nested_content['status'] ?? 0) !== 400) {
        op_integration_fail('Nested content selector was not rejected by Direct Runtime.');
    }
    // Distinct long parent IDs must not collapse to the same child request ID.
    $long_id = str_repeat('r', 72);
    $title_a = $runtime_call($long_id . '-a', 'post.update', ['post_id' => $post_id, 'fields' => ['title' => 'Replay target']]);
    $update_payload = $title_a['data']['data']['result']['data']['data'] ?? [];
    if (array_key_exists('content', $update_payload) || !isset($update_payload['id'], $update_payload['title'])) {
        op_integration_fail('Metadata update echoed full content or omitted updated fields.');
    }
    wp_update_post(['ID' => $post_id, 'post_title' => 'Intervening edit']);
    $replay = $runtime_call($long_id . '-a', 'post.update', ['post_id' => $post_id, 'fields' => ['title' => 'Replay target']]);
    if (empty($title_a['ok']) || empty($replay['ok']) || get_post($post_id)->post_title !== 'Intervening edit') {
        op_integration_fail('Replaying the same command repeated a mutation.');
    }
    $title_b = $runtime_call($long_id . '-b', 'post.update', ['post_id' => $post_id, 'fields' => ['title' => 'Replay target']]);
    if (empty($title_b['ok']) || get_post($post_id)->post_title !== 'Replay target') {
        op_integration_fail('Distinct long command IDs incorrectly shared a child replay.');
    }

    // Catalog is one deterministic entry point rather than a collection of
    // versioned routes the caller must rediscover.
    $catalog_outer = op_integration_call('catalog');
    $catalog = op_integration_v099_payload($catalog_outer);
    if (($catalog['route'] ?? null) !== '/takka-v099/v1/operate'
        || empty($catalog['query_must_be_object'])
        || ($catalog['arbitrary_route_allowed'] ?? null) !== false
        || ($catalog['arbitrary_action_allowed'] ?? null) !== false
        || !in_array('post.get', $catalog['operations'] ?? [], true)) {
        op_integration_fail('Operation catalog contract mismatch.');
    }

    // post.get keeps query parameters separate and returns metadata only by
    // default, even in edit context. Full content has a dedicated reader.
    $get_outer = op_integration_call('post.get', [
        'post_id' => $post_id,
        'query' => ['context' => 'edit'],
    ]);
    $get = op_integration_v099_payload($get_outer);
    if (empty($get['ok']) || ($get['operation'] ?? '') !== 'post.get') {
        op_integration_fail('post.get failed.');
    }
    $post_payload = op_integration_core_payload($get);
    if ((int) ($post_payload['id'] ?? 0) !== $post_id
        || !array_key_exists('featured_media', $post_payload)
        || array_key_exists('content', $post_payload)) {
        op_integration_fail('post.get was not bounded to metadata fields.');
    }

    // Explicit attempts to pull full post content through post.get are rejected.
    $get_content_outer = op_integration_call('post.get', [
        'post_id' => $post_id,
        'query' => ['context' => 'edit', '_fields' => 'id,title,content'],
    ]);
    if ((int) ($get_content_outer['status'] ?? 0) !== 400) {
        op_integration_fail('post.get content request was not rejected.');
    }

    // Generic post.update cannot bypass guarded content tooling.
    $rewrite_outer = op_integration_call('post.update', [
        'post_id' => $post_id,
        'fields' => ['content' => '<p>unguarded rewrite</p>'],
    ]);
    if ((int) ($rewrite_outer['status'] ?? 0) !== 400
        || strpos((string) wp_json_encode($rewrite_outer), 'wpab_v099_content_requires_guarded_patch') === false) {
        op_integration_fail('post.update content rewrite was not rejected by policy.');
    }
    if ((string) get_post($post_id)->post_content !== "line one\nline two target\nline three") {
        op_integration_fail('Rejected content rewrite still changed the post.');
    }

    // Targeted source reads do not need the entire post REST representation.
    $range_outer = op_integration_call('post.content.read_range', [
        'post_id' => $post_id,
        'start_line' => 2,
        'max_lines' => 1,
    ]);
    $range = op_integration_v099_payload($range_outer);
    $range_wrapper = $range['result']['data'] ?? null;
    $range_payload = is_array($range_wrapper) ? ($range_wrapper['data'] ?? null) : null;
    if (!is_array($range_payload)
        || ($range_payload['content'] ?? null) !== 'line two target'
        || (int) ($range_payload['start_line'] ?? 0) !== 2) {
        op_integration_fail('post.content.read_range failed.');
    }

    // High-level aliases inside readonly.batch are normalized to the strict
    // read-only action map, eliminating a second action-name vocabulary.
    $batch_outer = op_integration_call('readonly.batch', [
        'operations' => [
            [
                'label' => 'post source',
                'operation' => 'post.content.read_range',
                'params' => ['post_id' => $post_id, 'start_line' => 1, 'max_lines' => 2],
            ],
            [
                'label' => 'plugins',
                'operation' => 'plugin.list',
                'params' => [],
            ],
        ],
    ]);
    $batch = op_integration_v099_payload($batch_outer);
    $batch_wrapper = $batch['result']['data'] ?? null;
    $batch_payload = is_array($batch_wrapper) ? ($batch_wrapper['data'] ?? null) : null;
    if (!is_array($batch_payload)
        || empty($batch_payload['ok'])
        || (int) ($batch_payload['operation_count'] ?? 0) !== 2
        || (int) ($batch_payload['failed_count'] ?? -1) !== 0) {
        op_integration_fail('readonly.batch high-level alias integration failed.');
    }

    // Build a tiny valid PNG. The deterministic inline operation verifies known
    // bytes/SHA before delegating to WordPress media handling.
    $png_chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };
    $png = "\x89PNG\r\n\x1a\n";
    $png .= $png_chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0));
    // Reproduce the ~154 KiB small-image case that was previously split into
    // twenty GitHub writes. It must stay on a single native operation command.
    $png .= $png_chunk('tEXt', "Comment\0" . str_repeat('A', 157000));
    $png .= $png_chunk('IDAT', gzcompress("\x00\x00\x00\x00\x00", 9));
    $png .= $png_chunk('IEND', '');
    $png_sha = hash('sha256', $png);

    $bad_media_outer = op_integration_call('media.upload.inline', [
        'filename' => 'wpab-policy-bad.png',
        'data_b64' => base64_encode($png),
        'expected_bytes' => strlen($png),
        'expected_sha256' => str_repeat('0', 64),
    ]);
    if ((int) ($bad_media_outer['status'] ?? 0) !== 409) {
        op_integration_fail('Inline media SHA mismatch was not rejected before upload.');
    }

    $upload_started = microtime(true);
    $upload_params = [
        'filename' => 'wpab-policy-good.png',
        'data_b64' => base64_encode($png),
        'expected_bytes' => strlen($png),
        'expected_sha256' => $png_sha,
        'alt_text' => 'WPAB operation integration',
    ];
    $native_media = $runtime_call('runtime-small-media', 'media.upload.inline', $upload_params);
    if (empty($native_media['ok'])) op_integration_fail('Native small-image command failed.');
    $media_outer = $native_media['data'];
    $media = op_integration_v099_payload($media_outer);
    if (empty($media['ok']) || ($media['operation'] ?? '') !== 'media.upload.inline') {
        op_integration_fail('Small inline media upload failed.');
    }
    $media_payload = $media['result']['data'] ?? null;
    if (!is_array($media_payload) || empty($media_payload['id']) || (int) ($media_payload['bytes'] ?? 0) !== strlen($png)) {
        op_integration_fail('Small inline media result was incomplete.');
    }
    $attachment_id = (int) $media_payload['id'];
    $replayed_media = $runtime_call('runtime-small-media', 'media.upload.inline', $upload_params);
    if (($replayed_media['data']['data']['result']['data']['id'] ?? null) !== $attachment_id) {
        op_integration_fail('Small-image replay did not preserve attachment identity.');
    }
    echo wp_json_encode(['scenario' => 'small_media', 'decoded_bytes' => strlen($png),
        'upload_commands' => 1, 'staging_writes' => 0, 'replay_checks' => 1,
        'local_execution_ms' => (int) round((microtime(true) - $upload_started) * 1000),
        'github_roundtrip_measured' => false]) . "\n";

    $feature_outer = op_integration_call('post.update', [
        'post_id' => $post_id,
        'fields' => ['featured_media' => $attachment_id],
    ]);
    $feature = op_integration_v099_payload($feature_outer);
    if (empty($feature['ok']) || (int) get_post_thumbnail_id($post_id) !== $attachment_id) {
        op_integration_fail('Featured image update failed.');
    }

    op_integration_call('post.update', ['post_id' => $post_id, 'fields' => ['featured_media' => 0]]);
    $delete_outer = op_integration_call('media.delete', ['attachment_id' => $attachment_id, 'force' => true]);
    $delete = op_integration_v099_payload($delete_outer);
    if (empty($delete['ok'])) {
        op_integration_fail('Temporary integration media deletion failed.');
    }

    echo "Deterministic operation router clean-WordPress integration: OK\n";
} finally {
    if (isset($attachment_id) && get_post($attachment_id)) {
        wp_delete_attachment((int) $attachment_id, true);
    }
    if (get_post($post_id)) {
        wp_delete_post($post_id, true);
    }
}
