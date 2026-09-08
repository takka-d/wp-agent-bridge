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
    '1.1.16',
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test'
);

if (($catalog['schema'] ?? null) !== 1 || ($catalog['bridge_version'] ?? null) !== '1.1.16') {
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
    || empty($catalog['features']['post_content_range_read'])
    || empty($catalog['features']['media_inline_upload'])
    || empty($catalog['features']['media_fast_path'])
    || empty($catalog['features']['readonly_batch'])
    || empty($catalog['features']['atomic_command_bookkeeping'])) {
    fail_test('Expected feature flags are missing.');
}
$post_content = $catalog['routes']['post_content'] ?? [];
if (($post_content['route'] ?? null) !== '/takka-v084/v1/manage'
    || !in_array('post.content.inspect', $post_content['read_actions'] ?? [], true)
    || !in_array('post.content.search', $post_content['read_actions'] ?? [], true)
    || !in_array('post.content.read.range', $post_content['read_actions'] ?? [], true)
    || ($post_content['limits']['max_read_range_lines'] ?? null) !== 1000
    || ($post_content['limits']['max_read_range_bytes'] ?? null) !== 262144) {
    fail_test('Post-content routing metadata mismatch.');
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
    || !in_array('post.content.read.range', $batch['rest_actions'] ?? [], true)
    || !in_array('workspace.file.search', $batch['rest_actions'] ?? [], true)
    || !in_array('bridge.self_update.status', $batch['direct_actions'] ?? [], true)
    || !empty($batch['mutation_actions_allowed'])
    || !empty($batch['arbitrary_rest_allowed'])) {
    fail_test('Read-only batch capability metadata mismatch.');
}
$media = $catalog['media_routing'] ?? [];
if (($media['inline_action'] ?? null) !== 'media.upload_base64'
    || ($media['inline_preferred_max_decoded_bytes'] ?? null) !== 1048576
    || ($media['staged_upload_route'] ?? null) !== '/wp-agent-bridge-runtime/v1/media-upload'
    || empty($media['verify_only_on_failure_or_explicit_request'])) {
    fail_test('Media routing policy mismatch.');
}
if (($catalog['connector_policy']['ordinary_command_write'][0] ?? null) !== 'create_file'
    || !in_array('update_ref', $catalog['connector_policy']['preferred_atomic_git_data'] ?? [], true)) {
    fail_test('Connector fast-path metadata mismatch.');
}
if (strpos((string) ($catalog['fast_path']['readonly_batch'] ?? ''), 'two or more') === false) {
    fail_test('Read-only batch fast-path guidance is missing.');
}
$post_read_guidance = (string) ($catalog['fast_path']['post_content_read'] ?? '');
if (strpos($post_read_guidance, 'post.content.read.range') === false
    || strpos($post_read_guidance, '?context=edit') === false
    || strpos($post_read_guidance, 'generic core REST') === false) {
    fail_test('Post-content read routing guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['media_upload'] ?? ''), 'up to 1 MiB') === false
    || strpos((string) ($catalog['fast_path']['media_upload'] ?? ''), 'media.upload_base64') === false) {
    fail_test('Small-media inline upload guidance is missing.');
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
