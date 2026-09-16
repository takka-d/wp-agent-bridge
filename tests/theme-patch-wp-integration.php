<?php

/**
 * Clean-WordPress integration test for bounded atomic theme.file.patch routing.
 * Run with: wp eval-file tests/theme-patch-wp-integration.php
 */

wp_set_current_user(1);
do_action('rest_api_init');

function tp_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$secret = str_repeat('b', 64);
update_option('takka_bridge_secret', $secret, false);
update_option('takka_bridge_user_id', 1, false);

function tp_operation(string $operation, array $params = []): array
{
    $secret = (string) get_option('takka_bridge_secret');
    $inner = [
        'request_id' => 'theme-patch-' . substr(hash('sha256', $operation . '|' . wp_json_encode($params)), 0, 32),
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
        tp_fail('Outer request failed: ' . $response->get_error_message());
    }
    $response = rest_ensure_response($response);
    if ($response->get_status() >= 500) {
        tp_fail('Outer HTTP failure: ' . $response->get_status());
    }
    $data = $response->get_data();
    if (!is_array($data)) {
        tp_fail('Outer response was not an object.');
    }
    return $data;
}

function tp_legacy(string $action, array $params)
{
    $request = new WP_REST_Request('POST', '/takka-bridge/v1/execute');
    $request->set_param('action', $action);
    $request->set_param('params', $params);
    return TakKa_WordPress_Bridge::execute($request);
}

function tp_v099(array $outer): array
{
    $data = $outer['data'] ?? null;
    if (!is_array($data)) {
        tp_fail('Missing v0.9.9 payload.');
    }
    return $data;
}

$path = 'wpab-theme-patch-integration.html';
$initial = '<!doctype html><html><body>'
    . str_repeat('a', 139000)
    . 'CHAIN_FRONT'
    . str_repeat('b', 139000)
    . 'CAM_AXIS_X'
    . str_repeat('c', 139000)
    . 'ROT_DIR_NEG'
    . '</body></html>';

$write = tp_legacy('theme.file.write', [
    'path' => $path,
    'content' => $initial,
    'confirm_active' => true,
]);
if (is_wp_error($write)) {
    tp_fail('Could not create integration theme asset: ' . $write->get_error_message());
}

try {
    $catalog = tp_v099(tp_operation('catalog'));
    if (!in_array('theme.file.patch', $catalog['operations'] ?? [], true)) {
        tp_fail('theme.file.patch is absent from the deterministic operation catalog.');
    }
    $contract = $catalog['theme_file_patch'] ?? null;
    if (!is_array($contract)
        || empty($contract['atomic_multi_patch']['all_or_nothing'])
        || ($contract['temporary_php_required'] ?? true) !== false
        || ($contract['full_file_rewrite_required'] ?? true) !== false) {
        tp_fail('theme.file.patch catalog contract is incomplete.');
    }

    $patches = [
        ['label' => 'chain side', 'find' => 'CHAIN_FRONT', 'replace' => 'CHAIN_REAR', 'expected_replacements' => 1],
        ['label' => 'camera axis', 'find' => 'CAM_AXIS_X', 'replace' => 'CAM_AXIS_Z', 'expected_replacements' => 1],
        ['label' => 'rotation direction', 'find' => 'ROT_DIR_NEG', 'replace' => 'ROT_DIR_POS', 'expected_replacements' => 1],
    ];

    $preview_outer = tp_operation('theme.file.patch', [
        'path' => $path,
        'patches' => $patches,
        'dry_run' => true,
    ]);
    $preview_v099 = tp_v099($preview_outer);
    $preview = $preview_v099['result']['data'] ?? null;
    if (!is_array($preview)
        || empty($preview['ok'])
        || empty($preview['atomic'])
        || empty($preview['dry_run'])
        || (int) ($preview['patch_count'] ?? 0) !== 3
        || (int) ($preview['replacements'] ?? 0) !== 3
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($preview['before_sha256'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($preview['after_sha256'] ?? ''))
        || !preg_match('/^[a-f0-9]{64}$/', (string) ($preview['plan_hash'] ?? ''))) {
        tp_fail('Atomic theme patch preview failed: ' . wp_json_encode([
            'outer_status' => $preview_outer['status'] ?? null,
            'v099' => $preview_v099,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    if (strlen((string) ($preview['diff'] ?? '')) > 12000 || empty($preview['diff_truncated'])) {
        tp_fail('Large minified theme patch diff was not bounded.');
    }

    $read_before = tp_legacy('theme.file.read', ['path' => $path]);
    $read_before = is_wp_error($read_before) ? $read_before : rest_ensure_response($read_before)->get_data();
    if (is_wp_error($read_before) || !is_array($read_before) || ($read_before['content'] ?? '') !== $initial) {
        tp_fail('Dry-run unexpectedly changed the theme file.');
    }

    $apply_outer = tp_operation('theme.file.patch', [
        'path' => $path,
        'patches' => $patches,
        'expected_sha256' => $preview['before_sha256'],
        'expected_after_sha256' => $preview['after_sha256'],
        'expected_plan_hash' => $preview['plan_hash'],
        'confirm_active' => true,
    ]);
    $apply_v099 = tp_v099($apply_outer);
    $apply = $apply_v099['result']['data'] ?? null;
    if (!is_array($apply)
        || empty($apply['ok'])
        || empty($apply['applied'])
        || empty($apply['side_effects'])
        || (string) ($apply['after_sha256'] ?? '') !== (string) $preview['after_sha256']) {
        tp_fail('Atomic theme patch apply failed: ' . wp_json_encode($apply_v099, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $read_after = tp_legacy('theme.file.read', ['path' => $path]);
    $read_after = is_wp_error($read_after) ? $read_after : rest_ensure_response($read_after)->get_data();
    if (is_wp_error($read_after) || !is_array($read_after)) {
        tp_fail('Could not read patched theme file.');
    }
    $content_after = (string) ($read_after['content'] ?? '');
    foreach (['CHAIN_REAR', 'CAM_AXIS_Z', 'ROT_DIR_POS'] as $needle) {
        if (substr_count($content_after, $needle) !== 1) {
            tp_fail('Patched content is missing expected token: ' . $needle);
        }
    }
    foreach (['CHAIN_FRONT', 'CAM_AXIS_X', 'ROT_DIR_NEG'] as $needle) {
        if (strpos($content_after, $needle) !== false) {
            tp_fail('Patched content retained old token: ' . $needle);
        }
    }

    // A replay of the stale preview must fail before another write.
    $stale_outer = tp_operation('theme.file.patch', [
        'path' => $path,
        'patches' => $patches,
        'expected_sha256' => $preview['before_sha256'],
        'expected_plan_hash' => $preview['plan_hash'],
        'confirm_active' => true,
    ]);
    $stale_v099 = tp_v099($stale_outer);
    if (!empty($stale_v099['ok']) || (int) ($stale_v099['status'] ?? 0) !== 409) {
        tp_fail('Stale atomic theme patch replay was not rejected.');
    }

    echo "Atomic theme patch routing integration: OK\n";
} finally {
    $delete = tp_legacy('theme.file.delete', ['path' => $path, 'confirm_active' => true]);
    if (is_wp_error($delete)) {
        fwrite(STDERR, 'Cleanup failed: ' . $delete->get_error_message() . "\n");
    }
}
