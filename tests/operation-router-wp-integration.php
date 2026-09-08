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
    // Catalog is one deterministic entry point rather than a collection of
    // versioned routes the caller must rediscover.
    $catalog_outer = op_integration_call('catalog');
    $catalog = op_integration_v099_payload($catalog_outer);
    if (($catalog['route'] ?? null) !== '/takka-v099/v1/operate'
        || empty($catalog['query_must_be_object'])
        || empty($catalog['arbitrary_route_allowed']) === false) {
        // arbitrary_route_allowed must be exactly false.
        if (($catalog['arbitrary_route_allowed'] ?? null) !== false) {
            op_integration_fail('Operation catalog allows arbitrary routes.');
        }
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

    $media_outer = op_integration_call('media.upload.inline', [
        'filename' => 'wpab-policy-good.png',
        'data_b64' => base64_encode($png),
        'expected_bytes' => strlen($png),
        'expected_sha256' => $png_sha,
        'alt_text' => 'WPAB operation integration',
    ]);
    $media = op_integration_v099_payload($media_outer);
    if (empty($media['ok']) || ($media['operation'] ?? '') !== 'media.upload.inline') {
        op_integration_fail('Small inline media upload failed.');
    }
    $media_payload = $media['result']['data'] ?? null;
    if (!is_array($media_payload) || empty($media_payload['id']) || (int) ($media_payload['bytes'] ?? 0) !== strlen($png)) {
        op_integration_fail('Small inline media result was incomplete.');
    }
    $attachment_id = (int) $media_payload['id'];

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
