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
    '1.1.14',
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test'
);

if (($catalog['schema'] ?? null) !== 1 || ($catalog['bridge_version'] ?? null) !== '1.1.14') {
    fail_test('Runtime capability catalog version/schema mismatch.');
}
if (($catalog['runtime']['repository'] ?? null) !== 'owner/runtime-repo'
    || ($catalog['runtime']['branch'] ?? null) !== 'wp-agent-bridge-runtime'
    || ($catalog['runtime']['transport'] ?? null) !== 'direct-github-webhook') {
    fail_test('Runtime identity metadata mismatch.');
}
$bookkeeping = $catalog['runtime']['bookkeeping'] ?? [];
if (($bookkeeping['mode'] ?? null) !== 'atomic_git_tree'
    || empty($bookkeeping['result_completed_pending_same_commit'])
    || ($bookkeeping['max_ref_update_attempts'] ?? null) !== 3
    || empty($bookkeeping['result_visibility_is_completion_barrier'])) {
    fail_test('Atomic command bookkeeping metadata mismatch.');
}
if (($catalog['release_pointer']['path'] ?? null) !== 'UPDATE_MANIFEST.json') {
    fail_test('Official release pointer is missing.');
}
if (empty($catalog['features']['workspace'])
    || empty($catalog['features']['media_fast_path'])
    || empty($catalog['features']['readonly_batch'])
    || empty($catalog['features']['atomic_command_bookkeeping'])) {
    fail_test('Expected feature flags are missing.');
}
$workspace = $catalog['routes']['workspace'] ?? [];
if (($workspace['route'] ?? null) !== '/takka-v097/v1/manage'
    || !in_array('workspace.file.read.range', $workspace['actions'] ?? [], true)
    || !in_array('workspace.file.patch', $workspace['actions'] ?? [], true)
    || ($workspace['limits']['max_file_bytes'] ?? null) !== 2097152) {
    fail_test('Workspace capability metadata mismatch.');
}
$batch = $catalog['routes']['readonly_batch'] ?? [];
if (($batch['route'] ?? null) !== '/takka-v098/v1/manage'
    || ($batch['action'] ?? null) !== 'readonly.batch'
    || ($batch['limits']['max_operations'] ?? null) !== 12
    || !in_array('workspace.file.search', $batch['rest_actions'] ?? [], true)
    || !in_array('bridge.self_update.status', $batch['direct_actions'] ?? [], true)
    || !empty($batch['mutation_actions_allowed'])
    || !empty($batch['arbitrary_rest_allowed'])) {
    fail_test('Read-only batch capability metadata mismatch.');
}
if (($catalog['connector_policy']['ordinary_command_write'][0] ?? null) !== 'create_file'
    || !in_array('update_ref', $catalog['connector_policy']['preferred_atomic_git_data'] ?? [], true)) {
    fail_test('Connector fast-path metadata mismatch.');
}
if (strpos((string) ($catalog['fast_path']['readonly_batch'] ?? ''), 'two or more') === false) {
    fail_test('Read-only batch fast-path guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['command_chaining'] ?? ''), 'next pending command may be submitted immediately') === false) {
    fail_test('Atomic command chaining guidance is missing.');
}
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    fail_test('Capability catalog must not expose credentials or installation secrets.');
}

echo "runtime-capabilities-test: ok\n";
