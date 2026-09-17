<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-v099-response-contract.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-post-content-diagnostics.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-post-revisions.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-post-reliability-runtime-guidance.php';

function prrg_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$base = [
    'schema' => 2,
    'bridge_version' => '1.1.34',
    'features' => ['deterministic_operation_router' => true],
    'routes' => [
        'operations' => [
            'route' => '/takka-v099/v1/operate',
            'operations' => ['post.get', 'post.update', 'post.content.patch_preview', 'post.content.patch_apply'],
        ],
    ],
    'command_contract' => ['rules' => [], 'templates' => []],
    'task_recipes' => [],
    'failure_policy' => [],
    'fast_path' => [],
];

$catalog = TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance::enrich_capabilities($base);
foreach ([
    'post_revision_rollback',
    'post_content_patch_match_diagnostics',
    'deterministic_operation_response_contract',
    'explicit_bridge_version_semantics',
] as $feature) {
    if (empty($catalog['features'][$feature])) {
        prrg_fail('Missing post reliability feature: ' . $feature);
    }
}
if (($catalog['version_fields']['plugin_version'] ?? '') !== '1.1.34'
    || ($catalog['version_fields']['operation_api_version'] ?? '') !== '0.9.9'
    || (int) ($catalog['version_fields']['runtime_schema_version'] ?? 0) !== 2
    || ($catalog['version_fields']['health_bridge_version_semantics'] ?? '') !== 'legacy_api_compatibility_version') {
    prrg_fail('Version field semantics are incomplete.');
}

$operations = $catalog['routes']['operations']['operations'] ?? [];
foreach (['post.revisions.list', 'post.revisions.get', 'post.revisions.restore'] as $operation) {
    if (!in_array($operation, $operations, true)) {
        prrg_fail('Revision operation missing from runtime catalog: ' . $operation);
    }
}
$revision_contract = $catalog['routes']['operations']['post_revisions'] ?? [];
if (($revision_contract['revisioned_fields'] ?? null) !== ['title', 'content', 'excerpt']
    || !empty($revision_contract['metadata_taxonomy_featured_media_restored'])
    || empty($revision_contract['backup_before_restore_required'])
    || !in_array('expected_current_revision_fields_hash', $revision_contract['restore_requires'] ?? [], true)) {
    prrg_fail('Revision rollback contract is incomplete.');
}
$content_diag = $catalog['routes']['operations']['post_content_patch_diagnostics'] ?? [];
if (empty($content_diag['read_only'])
    || empty($content_diag['whitespace_normalized_match_count'])
    || !empty($content_diag['approximate_matches_can_write'])) {
    prrg_fail('Post content diagnostics contract is incomplete.');
}
$response_contract = $catalog['command_contract']['response_contract'] ?? [];
if ((int) ($response_contract['version'] ?? 0) !== 1
    || empty($response_contract['nested_operation_errors_promoted'])
    || empty($response_contract['wp_error_details_preserved_in_data'])) {
    prrg_fail('Deterministic response contract is incomplete.');
}

$restore_template = $catalog['command_contract']['templates']['post_revisions_restore']['params'] ?? [];
if (!isset($restore_template['expected_current_revision_fields_hash'], $restore_template['confirm'])) {
    prrg_fail('Revision restore template lacks guards.');
}
$rules = implode("\n", $catalog['command_contract']['rules'] ?? []);
if (strpos($rules, 'read-only location hints') === false
    || strpos($rules, 'explicit title/content/excerpt rollback') === false
    || strpos($rules, 'side_effects=null') === false) {
    prrg_fail('Post reliability command rules are incomplete.');
}
if (!isset($catalog['failure_policy']['post_content_match_409'], $catalog['failure_policy']['post_revision_restore_409'])
    || !isset($catalog['task_recipes']['post_revision_rollback'], $catalog['task_recipes']['post_content_match_conflict'])) {
    prrg_fail('Post reliability failure policy or recipes are incomplete.');
}

$agents = "# WP Agent Bridge\n\nExisting guidance.\n";
$once = TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance::enrich_agents($agents);
$twice = TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance::enrich_agents($once);
if ($once !== $twice
    || substr_count($once, '<!-- WPAB_POST_RELIABILITY_START -->') !== 1
    || strpos($once, 'approximate candidates') === false
    || strpos($once, '`post.revisions.list`') === false
    || strpos($once, '`expected_current_revision_fields_hash`') === false
    || strpos($once, '`plugin_version`') === false) {
    prrg_fail('AGENTS post reliability guidance is missing or not idempotent.');
}

$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    prrg_fail('Post reliability catalog exposed unexpected sensitive material.');
}

echo "post-reliability-runtime-guidance-test: ok\n";
