<?php

/**
 * Clean-WordPress integration for deterministic post reliability features.
 * Run with: wp eval-file tests/post-reliability-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function pr_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$secret = str_repeat('d', 64);
update_option('takka_bridge_secret', $secret, false);
update_option('takka_bridge_user_id', 1, false);

function pr_operation(string $operation, array $params = []): array
{
    $secret = (string) get_option('takka_bridge_secret');
    $inner = [
        'request_id' => 'post-reliability-' . substr(hash('sha256', $operation . '|' . wp_json_encode($params) . '|' . wp_generate_uuid4()), 0, 32),
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
        pr_fail('Outer request failed: ' . $response->get_error_message());
    }
    $response = rest_ensure_response($response);
    if ($response->get_status() >= 500) {
        pr_fail('Outer HTTP failure: ' . $response->get_status());
    }
    $data = $response->get_data();
    if (!is_array($data)) {
        pr_fail('Outer response was not an object.');
    }
    return $data;
}

function pr_v099(array $outer): array
{
    $payload = $outer['data'] ?? null;
    if (!is_array($payload)) {
        pr_fail('Missing v0.9.9 payload: ' . wp_json_encode($outer));
    }
    return $payload;
}

function pr_revision_fields_hash(WP_Post $post): string
{
    $material = wp_json_encode([
        'title_sha256' => hash('sha256', (string) $post->post_title),
        'excerpt_sha256' => hash('sha256', (string) $post->post_excerpt),
        'content_sha256' => hash('sha256', (string) $post->post_content),
    ], JSON_UNESCAPED_SLASHES);
    return hash('sha256', is_string($material) ? $material : '');
}

// 1. The deterministic route must expose one stable error decoder.
$unknown = pr_v099(pr_operation('post.reliability.nonexistent'));
if (!empty($unknown['ok'])
    || (int) ($unknown['status'] ?? 0) !== 400
    || ($unknown['code'] ?? '') !== 'takka_bridge_v099_unknown_operation'
    || !is_string($unknown['message'] ?? null)
    || !array_key_exists('side_effects', $unknown)
    || (int) ($unknown['response_contract_version'] ?? 0) !== 1) {
    pr_fail('Deterministic error response contract is incomplete: ' . wp_json_encode($unknown));
}

$post_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'Reliability baseline title',
    'post_excerpt' => 'Reliability baseline excerpt',
    'post_content' => "Alpha  beta\nGamma target\nOmega",
], true);
if (is_wp_error($post_id) || (int) $post_id < 1) {
    pr_fail('Could not create post reliability fixture.');
}
$post_id = (int) $post_id;

try {
    // 2. Exact-match conflict stays fail-closed, but now explains likely current
    // text locations without turning approximate matches into writes.
    $before_content = (string) get_post($post_id)->post_content;
    $mismatch = pr_v099(pr_operation('post.content.patch_preview', [
        'post_id' => $post_id,
        'find' => 'Alpha beta',
        'replace' => 'SHOULD NOT WRITE',
        'expected_matches' => 1,
    ]));
    $diag = $mismatch['data']['diagnostics'] ?? null;
    if (!empty($mismatch['ok'])
        || (int) ($mismatch['status'] ?? 0) !== 409
        || ($mismatch['code'] ?? '') !== 'takka_bridge_post_content_match_count'
        || !is_array($diag)
        || empty($diag['read_only'])
        || (int) ($diag['exact_match_count'] ?? -1) !== 0
        || (int) ($diag['whitespace_normalized_match_count'] ?? 0) < 1
        || !array_key_exists('approximate_candidates', $diag)
        || !empty($diag['approximate_matches_can_write'])
        || ($mismatch['side_effects'] ?? null) !== false
        || (string) get_post($post_id)->post_content !== $before_content) {
        pr_fail('Post content conflict diagnostics were incomplete or mutated content: ' . wp_json_encode($mismatch));
    }

    // 3. Establish a baseline revision, then move the post to a newer state.
    wp_save_post_revision($post_id);
    $baseline_post = get_post($post_id);
    $baseline_hash = pr_revision_fields_hash($baseline_post);
    $baseline_revisions = wp_get_post_revisions($post_id, ['order' => 'ASC', 'orderby' => 'ID']);
    $target_revision_id = 0;
    foreach ($baseline_revisions as $revision) {
        if ($revision instanceof WP_Post && hash_equals($baseline_hash, pr_revision_fields_hash($revision))) {
            $target_revision_id = (int) $revision->ID;
            break;
        }
    }
    if ($target_revision_id < 1) {
        pr_fail('Could not establish baseline WordPress revision.');
    }

    $updated = wp_update_post([
        'ID' => $post_id,
        'post_title' => 'Reliability current title',
        'post_excerpt' => 'Reliability current excerpt',
        'post_content' => 'Current body after baseline revision.',
    ], true);
    if (is_wp_error($updated)) {
        pr_fail('Could not update revision fixture: ' . $updated->get_error_message());
    }
    wp_save_post_revision($post_id);

    $listed = pr_v099(pr_operation('post.revisions.list', ['post_id' => $post_id, 'limit' => 20]));
    $list_data = $listed['result']['data'] ?? null;
    if (empty($listed['ok'])
        || !is_array($list_data)
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($list_data['current_revision_fields_hash'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($list_data['current_content_sha256'] ?? ''))
        || empty($list_data['revisions'])) {
        pr_fail('post.revisions.list failed: ' . wp_json_encode($listed));
    }

    $got = pr_v099(pr_operation('post.revisions.get', [
        'post_id' => $post_id,
        'revision_id' => $target_revision_id,
        'include_content' => true,
    ]));
    $got_data = $got['result']['data'] ?? null;
    if (empty($got['ok'])
        || !is_array($got_data)
        || (int) ($got_data['revision_id'] ?? 0) !== $target_revision_id
        || ($got_data['content'] ?? '') !== $before_content
        || !empty($got['side_effects'])) {
        pr_fail('post.revisions.get failed: ' . wp_json_encode($got));
    }

    // A stale restore guard must stop before mutation.
    $stale_hash = (string) $list_data['current_revision_fields_hash'];
    wp_update_post(['ID' => $post_id, 'post_title' => 'Changed after revision list']);
    $stale = pr_v099(pr_operation('post.revisions.restore', [
        'post_id' => $post_id,
        'revision_id' => $target_revision_id,
        'expected_current_revision_fields_hash' => $stale_hash,
        'confirm' => true,
    ]));
    if (!empty($stale['ok'])
        || (int) ($stale['status'] ?? 0) !== 409
        || ($stale['code'] ?? '') !== 'wpab_post_revision_state_changed'
        || ($stale['side_effects'] ?? null) !== false
        || get_post($post_id)->post_title !== 'Changed after revision list') {
        pr_fail('Stale revision restore was not rejected safely: ' . wp_json_encode($stale));
    }

    // Fresh evidence restores only title/content/excerpt and establishes a
    // rollback revision for the pre-restore state.
    $fresh = pr_v099(pr_operation('post.revisions.list', ['post_id' => $post_id, 'limit' => 20]));
    $fresh_data = $fresh['result']['data'] ?? [];
    $restored = pr_v099(pr_operation('post.revisions.restore', [
        'post_id' => $post_id,
        'revision_id' => $target_revision_id,
        'expected_current_revision_fields_hash' => $fresh_data['current_revision_fields_hash'] ?? '',
        'expected_current_content_sha256' => $fresh_data['current_content_sha256'] ?? '',
        'confirm' => true,
    ]));
    $restore_data = $restored['result']['data'] ?? null;
    $post_after_restore = get_post($post_id);
    if (empty($restored['ok'])
        || !is_array($restore_data)
        || (int) ($restore_data['backup_revision_id'] ?? 0) < 1
        || ($restored['side_effects'] ?? null) !== true
        || $post_after_restore->post_title !== 'Reliability baseline title'
        || $post_after_restore->post_excerpt !== 'Reliability baseline excerpt'
        || $post_after_restore->post_content !== $before_content) {
        pr_fail('Guarded revision restore failed: ' . wp_json_encode($restored));
    }

    // 4. Health separates installed plugin version from legacy API version.
    // Use the same signed deterministic operation path as production clients;
    // the health endpoint itself is intentionally not an unsigned public read.
    $health_v099 = pr_v099(pr_operation('health'));
    $health_data = $health_v099['result']['data'] ?? null;
    $plugin_data = get_file_data(
        __DIR__ . '/../plugin/wp-agent-bridge/takka-wordpress-bridge.php',
        ['Version' => 'Version'],
        'plugin'
    );
    $expected_plugin_version = trim((string) ($plugin_data['Version'] ?? ''));
    if (!is_array($health_data)
        || $expected_plugin_version === ''
        || ($health_data['plugin_version'] ?? '') !== $expected_plugin_version
        || !is_string($health_data['api_compatibility_version'] ?? null)
        || (int) ($health_data['runtime_schema_version'] ?? 0) !== 2
        || ($health_data['bridge_version_semantics'] ?? '') !== 'legacy_api_compatibility_version'
        || !in_array('post_revision_inspection', $health_data['features'] ?? [], true)
        || !in_array('post_content_patch_match_diagnostics', $health_data['features'] ?? [], true)) {
        pr_fail('Health version/reliability contract is incomplete: ' . wp_json_encode($health_v099));
    }

    echo "Post reliability integration: OK\n";
} finally {
    if (get_post($post_id)) {
        wp_delete_post($post_id, true);
    }
}
