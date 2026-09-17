<?php

/**
 * Clean WordPress integration for compound post change and inline media assign.
 * Run with: wp eval-file tests/post-compound-media-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function pcm_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$secret = str_repeat('e', 64);
update_option('takka_bridge_secret', $secret, false);
update_option('takka_bridge_user_id', 1, false);

function pcm_operation(string $operation, array $params = []): array
{
    $secret = (string) get_option('takka_bridge_secret');
    $inner = [
        'request_id' => 'compound-media-' . substr(hash('sha256', $operation . '|' . wp_json_encode($params) . '|' . wp_generate_uuid4()), 0, 32),
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
        pcm_fail('Outer request failed: ' . $response->get_error_message());
    }
    $data = rest_ensure_response($response)->get_data();
    if (!is_array($data) || !is_array($data['data'] ?? null)) {
        pcm_fail('Missing deterministic operation payload: ' . wp_json_encode($data));
    }
    return $data['data'];
}

function pcm_feature_hash(int $id): string
{
    $encoded = wp_json_encode($id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    return hash('sha256', is_string($encoded) ? $encoded : 'null');
}

$post_id = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'draft',
    'post_title' => 'Compound baseline title',
    'post_excerpt' => 'Compound baseline excerpt',
    'post_content' => 'Start TOKEN End',
], true);
if (is_wp_error($post_id) || (int) $post_id < 1) {
    pcm_fail('Could not create compound fixture.');
}
$post_id = (int) $post_id;
$attachments = [];
$conflict_attachment_id = 0;

try {
    $catalog = pcm_operation('catalog');
    $compound_contract = $catalog['post_compound_change'] ?? null;
    $media_contract = $catalog['media_upload_and_assign_inline'] ?? null;
    if (!is_array($compound_contract)
        || !in_array('post.change.preview', $catalog['operations'] ?? [], true)
        || !in_array('post.change.apply', $catalog['operations'] ?? [], true)
        || empty($compound_contract['revision_backup_for_revisioned_fields'])
        || ($compound_contract['database_transaction_claimed'] ?? true) !== false
        || !is_array($media_contract)
        || !in_array('media.upload_and_assign.inline', $catalog['operations'] ?? [], true)
        || empty($media_contract['new_attachment_cleanup_on_assignment_failure'])) {
        pcm_fail('Compound/media operation catalog contract is incomplete.');
    }

    // One logical edit changes content + title. An unrelated excerpt change
    // between preview and apply must coexist instead of invalidating the plan.
    $change_spec = [
        'post_id' => $post_id,
        'fields' => ['title' => 'Compound applied title'],
        'content_patch' => [
            'find' => 'TOKEN',
            'replace' => 'PATCHED',
            'expected_matches' => 1,
        ],
    ];
    $preview = pcm_operation('post.change.preview', $change_spec);
    $preview_data = $preview['result']['data'] ?? null;
    if (empty($preview['ok'])
        || !is_array($preview_data)
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($preview_data['plan_hash'] ?? ''))
        || !empty($preview['side_effects'])
        || (int) ($preview_data['content_replacements'] ?? 0) !== 1) {
        pcm_fail('Compound preview failed: ' . wp_json_encode($preview));
    }

    wp_update_post(['ID' => $post_id, 'post_excerpt' => 'Independent excerpt from another client']);
    $apply_params = $change_spec;
    $apply_params['expected_plan_hash'] = $preview_data['plan_hash'];
    $apply_params['confirm'] = true;
    $applied = pcm_operation('post.change.apply', $apply_params);
    $apply_data = $applied['result']['data'] ?? null;
    $post = get_post($post_id);
    if (empty($applied['ok'])
        || !is_array($apply_data)
        || (int) ($apply_data['backup_revision_id'] ?? 0) < 1
        || empty($applied['side_effects'])
        || $post->post_title !== 'Compound applied title'
        || $post->post_content !== 'Start PATCHED End'
        || $post->post_excerpt !== 'Independent excerpt from another client') {
        pcm_fail('Compound apply did not preserve unrelated metadata: ' . wp_json_encode($applied));
    }

    // A touched field changing after preview invalidates the plan before write.
    $stale_spec = [
        'post_id' => $post_id,
        'fields' => ['title' => 'Stale requested title'],
    ];
    $stale_preview = pcm_operation('post.change.preview', $stale_spec);
    $stale_plan = $stale_preview['result']['data']['plan_hash'] ?? '';
    wp_update_post(['ID' => $post_id, 'post_title' => 'Newer title from another client']);
    $stale_apply = pcm_operation('post.change.apply', $stale_spec + [
        'expected_plan_hash' => $stale_plan,
        'confirm' => true,
    ]);
    if (!empty($stale_apply['ok'])
        || (int) ($stale_apply['status'] ?? 0) !== 409
        || ($stale_apply['code'] ?? '') !== 'wpab_post_change_plan_changed'
        || ($stale_apply['side_effects'] ?? null) !== false
        || get_post($post_id)->post_title !== 'Newer title from another client') {
        pcm_fail('Stale compound plan was not stopped safely: ' . wp_json_encode($stale_apply));
    }

    // Inject an error after the core callback has already mutated the fixture.
    // The compound operation must restore the durable touched state and report
    // that transient hook side effects cannot be proven absent.
    $before_failure = get_post($post_id);
    $before_failure_title = (string) $before_failure->post_title;
    $before_failure_content = (string) $before_failure->post_content;
    $failure_spec = [
        'post_id' => $post_id,
        'fields' => ['title' => 'Title that must roll back'],
        'content_patch' => [
            'find' => 'PATCHED',
            'replace' => 'MUTATED-BEFORE-FAILURE',
            'expected_matches' => 1,
        ],
    ];
    $failure_preview = pcm_operation('post.change.preview', $failure_spec);
    $failure_plan = $failure_preview['result']['data']['plan_hash'] ?? '';
    $inject_once = true;
    $failure_filter = static function ($response, array $handler, WP_REST_Request $request) use (&$inject_once, $post_id) {
        if ($inject_once
            && $request->get_route() === '/wp/v2/posts/' . $post_id
            && $request->get_header('x-wpab-compound-phase') === 'apply') {
            $inject_once = false;
            return new WP_Error('wpab_test_after_update_failure', 'Injected failure after core mutation.', ['status' => 500]);
        }
        return $response;
    };
    add_filter('rest_request_after_callbacks', $failure_filter, 999, 3);
    $failed_apply = pcm_operation('post.change.apply', $failure_spec + [
        'expected_plan_hash' => $failure_plan,
        'confirm' => true,
    ]);
    remove_filter('rest_request_after_callbacks', $failure_filter, 999);
    $after_failure = get_post($post_id);
    if (!empty($failed_apply['ok'])
        || ($failed_apply['code'] ?? '') !== 'wpab_post_change_apply_failed_rolled_back'
        || empty($failed_apply['data']['durable_state_restored'])
        || ($failed_apply['side_effects'] ?? null) !== true
        || $after_failure->post_title !== $before_failure_title
        || $after_failure->post_content !== $before_failure_content) {
        pcm_fail('Compound compensation did not restore durable state: ' . wp_json_encode($failed_apply));
    }

    // Small media upload + assignment succeeds with the featured-media field
    // hash from post.get, and reports byte/SHA integrity.
    $gif_b64 = 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    $gif = base64_decode($gif_b64, true);
    if (!is_string($gif)) {
        pcm_fail('GIF fixture is invalid Base64.');
    }
    $read = pcm_operation('post.get', ['post_id' => $post_id, 'query' => ['context' => 'edit']]);
    $featured_hash = $read['concurrency']['field_hashes']['featured_media'] ?? '';
    $media = pcm_operation('media.upload_and_assign.inline', [
        'post_id' => $post_id,
        'expected_featured_media_sha256' => $featured_hash,
        'filename' => 'wpab-compound-pixel.gif',
        'data_b64' => $gif_b64,
        'expected_bytes' => strlen($gif),
        'expected_sha256' => hash('sha256', $gif),
        'alt_text' => 'WPAB compound media fixture',
        'confirm' => true,
    ]);
    $media_data = $media['result']['data'] ?? null;
    $attachment_id = is_array($media_data) ? (int) ($media_data['featured_media'] ?? 0) : 0;
    if (empty($media['ok'])
        || $attachment_id < 1
        || (int) get_post_thumbnail_id($post_id) !== $attachment_id
        || !get_post($attachment_id)
        || ($media_data['integrity']['sha256'] ?? '') !== hash('sha256', $gif)) {
        pcm_fail('Integrated media upload/assignment failed: ' . wp_json_encode($media));
    }
    $attachments[] = $attachment_id;

    // Reset to no thumbnail, then let an add_attachment hook make a newer
    // featured-media choice while the next upload is processing. The Bridge
    // must preserve that newer choice and delete only the new unassigned file.
    delete_post_thumbnail($post_id);
    $read_zero = pcm_operation('post.get', ['post_id' => $post_id, 'query' => ['context' => 'edit']]);
    $zero_hash = $read_zero['concurrency']['field_hashes']['featured_media'] ?? '';
    if ($zero_hash !== pcm_feature_hash(0)) {
        pcm_fail('Featured-media zero hash did not match the concurrency snapshot.');
    }
    $conflict_hook = static function ($new_attachment_id) use ($post_id, $attachment_id) {
        if ((int) $new_attachment_id !== $attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
        }
    };
    add_action('add_attachment', $conflict_hook, 10, 1);
    $conflict = pcm_operation('media.upload_and_assign.inline', [
        'post_id' => $post_id,
        'expected_featured_media_sha256' => $zero_hash,
        'filename' => 'wpab-conflict-pixel.gif',
        'data_b64' => $gif_b64,
        'expected_bytes' => strlen($gif),
        'expected_sha256' => hash('sha256', $gif),
        'confirm' => true,
    ]);
    remove_action('add_attachment', $conflict_hook, 10);
    $conflict_attachment_id = (int) ($conflict['data']['uploaded_attachment_id'] ?? 0);
    if (!empty($conflict['ok'])
        || (int) ($conflict['status'] ?? 0) !== 409
        || ($conflict['code'] ?? '') !== 'wpab_media_assign_featured_media_conflict_after_upload'
        || empty($conflict['data']['durable_uploaded_attachment_deleted'])
        || (int) get_post_thumbnail_id($post_id) !== $attachment_id
        || ($conflict_attachment_id > 0 && get_post($conflict_attachment_id))) {
        pcm_fail('Media conflict cleanup did not preserve the newer featured image: ' . wp_json_encode($conflict));
    }

    echo "Compound post/media integration: OK\n";
} finally {
    remove_all_filters('rest_request_after_callbacks', 999);
    foreach (array_unique(array_filter(array_merge($attachments, [$conflict_attachment_id]))) as $attachment_id) {
        if (get_post((int) $attachment_id)) {
            wp_delete_attachment((int) $attachment_id, true);
        }
    }
    if (get_post($post_id)) {
        wp_delete_post($post_id, true);
    }
}
