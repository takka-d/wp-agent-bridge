<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-runtime-capabilities.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$catalog = TakKa_WordPress_Bridge_Runtime_Capabilities::catalog(
    '1.1.12',
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test'
);

if (($catalog['schema'] ?? null) !== 1 || ($catalog['bridge_version'] ?? null) !== '1.1.12') {
    fail_test('Runtime capability catalog version/schema mismatch.');
}
if (($catalog['runtime']['repository'] ?? null) !== 'owner/runtime-repo'
    || ($catalog['runtime']['branch'] ?? null) !== 'wp-agent-bridge-runtime'
    || ($catalog['runtime']['transport'] ?? null) !== 'direct-github-webhook') {
    fail_test('Runtime identity metadata mismatch.');
}
if (($catalog['release_pointer']['path'] ?? null) !== 'UPDATE_MANIFEST.json') {
    fail_test('Official release pointer is missing.');
}
if (empty($catalog['features']['workspace']) || empty($catalog['features']['media_fast_path'])) {
    fail_test('Expected feature flags are missing.');
}
$workspace = $catalog['routes']['workspace'] ?? [];
if (($workspace['route'] ?? null) !== '/takka-v097/v1/manage'
    || !in_array('workspace.file.read.range', $workspace['actions'] ?? [], true)
    || !in_array('workspace.file.patch', $workspace['actions'] ?? [], true)
    || ($workspace['limits']['max_file_bytes'] ?? null) !== 2097152) {
    fail_test('Workspace capability metadata mismatch.');
}
if (($catalog['connector_policy']['ordinary_command_write'][0] ?? null) !== 'create_file'
    || !in_array('update_ref', $catalog['connector_policy']['preferred_atomic_git_data'] ?? [], true)) {
    fail_test('Connector fast-path metadata mismatch.');
}
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    fail_test('Capability catalog must not expose credentials or installation secrets.');
}

echo "runtime-capabilities-test: ok\n";
