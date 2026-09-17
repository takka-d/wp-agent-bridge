<?php

/**
 * Clean-WordPress integration test for field-level post.update concurrency.
 * Run with: wp eval-file tests/post-update-concurrency-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function pc_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$secret = str_repeat('c', 64);
update_option('takka_bridge_secret', $secret, false);
update_option('takka_bridge_user_id', 1, false);

function pc_operation(string $operation, array $params = [], ?string $request_id = null): array
{
    $secret = (string) get_option('takka_bridge_secret');
    $request_id = $request_id ?: 'post-concurrency-' . substr(hash('sha256', $operation . '|' . wp_json_encode($params) . '|' . wp_generate_uuid4()), 0, 32);
    $inner = [
        'request_id' => $request_id,
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
        pc_fail('Outer request failed: ' . $response->get_error_message());
    }
    $response = rest_ensure_response($response);
    if ($response->get_status() >= 500) {
        pc_fail('Outer HTTP failure: ' . $response->get_status());
    }
    $data = $response->get_data();
    if (!is_array($data)) {
        pc_fail('Outer response was not an object.');
    }
    return $data;
}

function pc_v099(array $outer): array
{
    $payload = $outer['data'] ?? null;
    if (!is_array($payload)) {
        pc_fail('Missing v0.9.9 payload: ' . wp_json_encode($outer));
    }
    return $payload;
}

$post_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'Concurrency baseline title',
    'post_excerpt' => 'Concurrency baseline excerpt',
    'post_content' => 'Body must not be touched by metadata concurrency tests.',
], true);
if (is_wp_error($post_id) || (int) $post_id < 1) {
    pc_fail('Could not create concurrency fixture.');
}
$post_id = (int) $post_id;

try {
    $catalog = pc_v099(pc_operation('catalog'));
    $contract = $catalog['post_update_concurrency'] ?? null;
    if (!is_array($contract)
        || empty($contract['field_level_compare_and_swap'])
        || empty($contract['allows_non_overlapping_concurrent_updates'])
        || ($contract['preferred_guard'] ?? '') !== 'expected_field_hashes') {
        pc_fail('post.update concurrency contract is missing from the operation catalog.');
    }

    // Both simulated chats read the same snapshot.
    $read_a = pc_v099(pc_operation('post.get', ['post_id' => $post_id, 'query' => ['context' => 'edit']]));
    $snapshot = $read_a['concurrency'] ?? null;
    if (!is_array($snapshot)
        || ($snapshot['mode'] ?? '') !== 'field-hash-v1'
        || (int) ($snapshot['post_id'] ?? 0) !== $post_id
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['state_hash'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['field_hashes']['title'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($snapshot['field_hashes']['excerpt'] ?? ''))) {
        pc_fail('post.get did not return a usable concurrency snapshot: ' . wp_json_encode($read_a));
    }

    // Chat A changes title with a title-only compare-and-swap guard.
    $update_a = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['title' => 'Title from chat A'],
        'expected_field_hashes' => ['title' => $snapshot['field_hashes']['title']],
    ]));
    if (empty($update_a['ok'])
        || empty($update_a['concurrency_guard']['used'])
        || ($update_a['concurrency_guard']['mode'] ?? '') !== 'field_hashes'
        || get_post($post_id)->post_title !== 'Title from chat A') {
        pc_fail('Guarded title update failed: ' . wp_json_encode($update_a));
    }

    // Chat B still has the old snapshot, but changes a different field. The
    // stale whole-post modified time must not block this non-overlapping update.
    $update_b = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['excerpt' => 'Excerpt from chat B'],
        'expected_field_hashes' => ['excerpt' => $snapshot['field_hashes']['excerpt']],
        'expected_modified_gmt' => $snapshot['modified_gmt'],
    ]));
    $post_after_b = get_post($post_id);
    if (empty($update_b['ok'])
        || empty($update_b['concurrency_guard']['used'])
        || empty($update_b['concurrency_guard']['allows_non_overlapping_concurrent_updates'])
        || $post_after_b->post_title !== 'Title from chat A'
        || $post_after_b->post_excerpt !== 'Excerpt from chat B') {
        pc_fail('Non-overlapping stale snapshot update did not merge safely: ' . wp_json_encode($update_b));
    }

    // The same stale snapshot must not overwrite the field Chat A already changed.
    $stale_title = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['title' => 'Stale title from chat B'],
        'expected_field_hashes' => ['title' => $snapshot['field_hashes']['title']],
    ]));
    $stale_json = wp_json_encode($stale_title, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ((int) ($stale_title['status'] ?? 0) !== 409
        || strpos((string) $stale_json, 'wpab_post_update_field_conflict') === false
        || strpos((string) $stale_json, '"side_effects":false') === false
        || get_post($post_id)->post_title !== 'Title from chat A') {
        pc_fail('Overlapping stale title update was not stopped safely: ' . $stale_json);
    }

    // Whole-state compare is available for clients that want a coarser guard.
    $fresh = pc_v099(pc_operation('post.get', ['post_id' => $post_id]));
    $fresh_snapshot = $fresh['concurrency'] ?? [];
    $state_update = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['slug' => 'concurrency-safe-slug'],
        'expected_state_hash' => $fresh_snapshot['state_hash'] ?? '',
    ]));
    if (empty($state_update['ok']) || ($state_update['concurrency_guard']['mode'] ?? '') !== 'state_hash') {
        pc_fail('Whole-state concurrency fallback failed: ' . wp_json_encode($state_update));
    }

    $state_stale = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['status' => 'pending'],
        'expected_state_hash' => $fresh_snapshot['state_hash'] ?? '',
    ]));
    if ((int) ($state_stale['status'] ?? 0) !== 409 || get_post($post_id)->post_status !== 'draft') {
        pc_fail('Stale whole-state update was not rejected.');
    }

    // Unsupported/complex fields need a coarse guard when field hashes are used.
    $unsupported = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['meta' => ['example' => 'value']],
        'expected_field_hashes' => ['title' => ($state_update['concurrency']['field_hashes']['title'] ?? '')],
    ]));
    if ((int) ($unsupported['status'] ?? 0) !== 400
        || strpos((string) wp_json_encode($unsupported), 'wpab_post_update_unsupported_field_guard') === false) {
        pc_fail('Unsupported guarded field did not require a coarse guard.');
    }

    // Backward compatibility remains explicit rather than pretending an old
    // caller was protected. This may be tightened in a future major contract.
    $unguarded = pc_v099(pc_operation('post.update', [
        'post_id' => $post_id,
        'fields' => ['comment_status' => 'closed'],
    ]));
    if (empty($unguarded['ok'])
        || !isset($unguarded['concurrency_guard'])
        || !empty($unguarded['concurrency_guard']['used'])
        || ($unguarded['concurrency_guard']['mode'] ?? '') !== 'none') {
        pc_fail('Backward-compatible unguarded update was not explicitly marked unguarded.');
    }

    if ((string) get_post($post_id)->post_content !== 'Body must not be touched by metadata concurrency tests.') {
        pc_fail('Metadata concurrency test changed post content.');
    }

    echo "Post update field-level concurrency integration: OK\n";
} finally {
    if (get_post($post_id)) {
        wp_delete_post($post_id, true);
    }
}
