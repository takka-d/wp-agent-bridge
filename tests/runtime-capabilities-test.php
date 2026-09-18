<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-v099-operations.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-runtime-capabilities.php';

function fail_test(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$catalog = WP_Agent_Bridge_Runtime_Capabilities::catalog(
    '1.1.16',
    'owner/runtime-repo',
    'wp-agent-bridge-runtime',
    'example.test'
);

if (($catalog['schema'] ?? null) !== 2 || ($catalog['bridge_version'] ?? null) !== '1.1.16') {
    fail_test('Runtime capability catalog version/schema mismatch.');
}
if (($catalog['runtime']['repository'] ?? null) !== 'owner/runtime-repo'
    || ($catalog['runtime']['branch'] ?? null) !== 'wp-agent-bridge-runtime'
    || ($catalog['runtime']['transport'] ?? null) !== 'direct-github-webhook') {
    fail_test('Runtime identity metadata mismatch.');
}
$bootstrap = $catalog['runtime']['bootstrap'] ?? [];
if (empty($bootstrap['explicit_ref_required_for_runtime_io'])
    || empty($bootstrap['default_branch_read_mirror'])
    || empty($bootstrap['default_branch_is_bootstrap_only'])
    || empty($bootstrap['mutations_require_runtime_branch'])
    || ($bootstrap['canonical_connection_path'] ?? null) !== 'wordpress-bridge/RUNTIME_CONNECTION.json'
    || ($bootstrap['connection_alias_path'] ?? null) !== 'RUNTIME_CONNECTION.json'
    || ($bootstrap['wrong_branch_404_is_access_denial'] ?? true) !== false
    || ($bootstrap['connection_read_required_before_commands'] ?? true) !== false
    || ($bootstrap['known_binding_can_publish_without_marker_read'] ?? false) !== true) {
    fail_test('Classic-safe runtime bootstrap metadata mismatch.');
}
$bookkeeping = $catalog['runtime']['bookkeeping'] ?? [];
if (($bookkeeping['mode'] ?? null) !== 'atomic_git_tree'
    || empty($bookkeeping['result_completed_pending_same_commit'])
    || ($bookkeeping['max_ref_update_attempts'] ?? null) !== 3
    || empty($bookkeeping['result_visibility_is_completion_barrier'])) {
    fail_test('Atomic command bookkeeping metadata mismatch.');
}
$pending_recovery = $catalog['runtime']['pending_recovery'] ?? [];
if (($pending_recovery['max_age_seconds'] ?? null) !== 86400
    || ($pending_recovery['age_sources'] ?? null) !== ['created_at', 'id_timestamp', 'github_path_commit_history']
    || ($pending_recovery['unknown_age'] ?? null) !== 'skip_without_execution'
    || ($pending_recovery['expired_action'] ?? null) !== 'quarantine_to_commands_expired'
    || ($pending_recovery['expired_path'] ?? null) !== 'wordpress-bridge/commands/expired/<id>.json') {
    fail_test('Pending recovery safety metadata mismatch.');
}
if (($catalog['release_pointer']['path'] ?? null) !== 'UPDATE_MANIFEST.json') {
    fail_test('Official release pointer is missing.');
}
if (empty($catalog['features']['workspace'])
    || empty($catalog['features']['post_content_range_read'])
    || empty($catalog['features']['deterministic_operation_router'])
    || empty($catalog['features']['media_inline_upload'])
    || empty($catalog['features']['media_fast_path'])
    || empty($catalog['features']['readonly_batch'])
    || empty($catalog['features']['atomic_command_bookkeeping'])
    || empty($catalog['features']['default_branch_bootstrap_mirror'])
    || empty($catalog['features']['runtime_root_bootstrap_aliases'])) {
    fail_test('Expected feature flags are missing.');
}
$operations = $catalog['routes']['operations'] ?? [];
if (($operations['route'] ?? null) !== '/wpab-v099/v1/operate'
    || !in_array('post.get', $operations['operations'] ?? [], true)
    || !in_array('post.content.read_range', $operations['operations'] ?? [], true)
    || !in_array('media.upload.inline', $operations['operations'] ?? [], true)
    || !in_array('workspace.file.patch', $operations['operations'] ?? [], true)
    || !in_array('self_update.status', $operations['operations'] ?? [], true)
    || !empty($operations['arbitrary_route_allowed'])
    || !empty($operations['arbitrary_action_allowed'])) {
    fail_test('Deterministic operation router metadata mismatch.');
}
$post_content = $catalog['routes']['post_content'] ?? [];
if (($post_content['route'] ?? null) !== '/wpab-v084/v1/manage'
    || !in_array('post.content.inspect', $post_content['read_actions'] ?? [], true)
    || !in_array('post.content.search', $post_content['read_actions'] ?? [], true)
    || !in_array('post.content.read.range', $post_content['read_actions'] ?? [], true)
    || ($post_content['limits']['max_read_range_lines'] ?? null) !== 1000
    || ($post_content['limits']['max_read_range_bytes'] ?? null) !== 262144) {
    fail_test('Post-content routing metadata mismatch.');
}
$workspace = $catalog['routes']['workspace'] ?? [];
if (($workspace['route'] ?? null) !== '/wpab-v097/v1/manage'
    || !in_array('workspace.file.read.range', $workspace['actions'] ?? [], true)
    || !in_array('workspace.file.patch', $workspace['actions'] ?? [], true)
    || ($workspace['limits']['max_file_bytes'] ?? null) !== 2097152) {
    fail_test('Workspace capability metadata mismatch.');
}
$batch = $catalog['routes']['readonly_batch'] ?? [];
if (($batch['route'] ?? null) !== '/wpab-v098/v1/manage'
    || ($batch['action'] ?? null) !== 'readonly.batch'
    || ($batch['limits']['max_operations'] ?? null) !== 12
    || !in_array('post.content.read.range', $batch['rest_actions'] ?? [], true)
    || !in_array('workspace.file.search', $batch['rest_actions'] ?? [], true)
    || !in_array('bridge.self_update.status', $batch['direct_actions'] ?? [], true)
    || !empty($batch['mutation_actions_allowed'])
    || !empty($batch['arbitrary_rest_allowed'])) {
    fail_test('Read-only batch capability metadata mismatch.');
}
$contract = $catalog['command_contract'] ?? [];
if (($contract['preferred_common_task_route'] ?? null) !== '/wpab-v099/v1/operate'
    || (($contract['preferred_common_task_command']['type'] ?? null) !== 'rest')
    || (($contract['templates']['post_get_edit_context']['params']['query']['context'] ?? null) !== 'edit')
    || (($contract['templates']['media_chunk_upload']['route'] ?? null) !== '/wp-agent-bridge-media/v1/upload-chunk')) {
    fail_test('Deterministic command contract/templates are missing.');
}
$rules = implode("\n", is_array($contract['rules'] ?? null) ? $contract['rules'] : []);
if (strpos($rules, 'Never append a query string') === false
    || strpos($rules, 'Do not switch to WPVibe') === false
    || strpos($rules, 'Do not issue capability probe') === false) {
    fail_test('Command anti-regression rules are incomplete.');
}
$recipes = $catalog['task_recipes'] ?? [];
if (($recipes['featured_image_small_file']['steps'][0] ?? null) !== 'prepare connector-safe 8192-byte chunks'
    || ($recipes['multiple_independent_reads']['steps'][0] ?? null) !== 'readonly.batch') {
    fail_test('Task recipes are incomplete.');
}
$failure = $catalog['failure_policy'] ?? [];
if (!isset($failure['rest_no_route'], $failure['media_integrity_409'], $failure['media_base64_truncation'], $failure['runtime_branch_race_409_422'], $failure['bootstrap_404'])
    || strpos((string) $failure['bootstrap_404'], 'not a startup blocker') === false
    || strpos((string) $failure['bootstrap_404'], 'pending command') === false) {
    fail_test('Failure policy is incomplete.');
}
$media = $catalog['media_routing'] ?? [];
if (($media['conversation_upload_route'] ?? null) !== '/wp-agent-bridge-media/v1/upload-chunk'
    || ($media['conversation_upload_mode'] ?? null) !== 'connector-safe-chunk-commands'
    || ($media['recommended_decoded_chunk_bytes'] ?? null) !== 8192
    || ($media['recommended_command_json_bytes'] ?? null) !== 16384
    || ($media['max_chunks'] ?? null) !== 768
    || ($media['max_commands_per_git_push'] ?? null) !== 20) {
    fail_test('Media routing policy mismatch.');
}
if (($catalog['connector_policy']['ordinary_command_write'][0] ?? null) !== 'create_file'
    || !in_array('update_ref', $catalog['connector_policy']['preferred_atomic_git_data'] ?? [], true)
    || strpos((string) ($catalog['connector_policy']['question_rule'] ?? ''), 'Do not ask the user') === false
    || strpos((string) ($catalog['connector_policy']['branch_rule'] ?? ''), 'explicitly') === false
    || strpos((string) ($catalog['connector_policy']['branch_rule'] ?? ''), 'default branch') === false
    || strpos((string) ($catalog['connector_policy']['startup_rule'] ?? ''), 'do not gate') === false
    || strpos((string) ($catalog['connector_policy']['startup_rule'] ?? ''), 'pending command directly') === false) {
    fail_test('Connector fast-path metadata mismatch.');
}
if (strpos((string) ($catalog['fast_path']['readonly_batch'] ?? ''), 'two or more') === false) {
    fail_test('Read-only batch fast-path guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['runtime_resolution'] ?? ''), 'explicit runtime branch/ref') === false
    || strpos((string) ($catalog['fast_path']['runtime_resolution'] ?? ''), 'do not make wordpress-bridge/RUNTIME_CONNECTION.json a preflight gate') === false
    || strpos((string) ($catalog['fast_path']['runtime_resolution'] ?? ''), 'bootstrap aliases only') === false) {
    fail_test('Classic-safe runtime resolution guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['operation_router'] ?? ''), '/wpab-v099/v1/operate') === false) {
    fail_test('Operation-router fast path guidance is missing.');
}
$post_read_guidance = (string) ($catalog['fast_path']['post_content_read'] ?? '');
if (strpos($post_read_guidance, 'post.content.read_range') === false
    || strpos($post_read_guidance, 'deterministic operation router') === false) {
    fail_test('Post-content read routing guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['media_upload'] ?? ''), 'prepare-media.py') === false
    || strpos((string) ($catalog['fast_path']['media_upload'] ?? ''), '8192-byte') === false) {
    fail_test('Connector-safe media upload guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['command_chaining'] ?? ''), 'next pending command may be submitted immediately') === false) {
    fail_test('Atomic command chaining guidance is missing.');
}
if (strpos((string) ($catalog['fast_path']['pending_recovery'] ?? ''), 'never re-executes') === false
    || strpos((string) ($catalog['fast_path']['pending_recovery'] ?? ''), 'commands/expired') === false) {
    fail_test('Pending recovery safety guidance is missing.');
}
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    fail_test('Capability catalog must not expose credentials or installation secrets.');
}

echo "runtime-capabilities-test: ok\n";

require_once __DIR__ . '/runtime-sync-fixture.php';
verify_runtime_sync('WP_Agent_Bridge_Runtime_Capabilities', 'wpab_runtime_capabilities_synced_version', ['wordpress-bridge/RUNTIME_CAPABILITIES.json']);
echo "same-version runtime sync: ok\n";
