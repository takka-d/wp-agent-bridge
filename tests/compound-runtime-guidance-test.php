<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-post-compound-change.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-media-upload-assign.php';
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-takka-wordpress-bridge-compound-runtime-guidance.php';

function crg_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$base = [
    'schema' => 2,
    'bridge_version' => '1.1.35',
    'features' => ['deterministic_operation_router' => true],
    'routes' => [
        'operations' => [
            'route' => '/takka-v099/v1/operate',
            'operations' => ['post.get', 'post.update', 'media.upload.inline'],
        ],
    ],
    'command_contract' => ['rules' => [], 'templates' => []],
    'task_recipes' => [],
    'failure_policy' => [],
    'fast_path' => [],
];

$catalog = TakKa_WordPress_Bridge_Compound_Runtime_Guidance::enrich_capabilities($base);
foreach ([
    'post_compound_guarded_change',
    'post_compensating_rollback',
    'media_inline_upload_assign',
    'media_assignment_cleanup_on_failure',
] as $feature) {
    if (empty($catalog['features'][$feature])) {
        crg_fail('Missing compound feature: ' . $feature);
    }
}

$operations = $catalog['routes']['operations']['operations'] ?? [];
foreach (['post.change.preview', 'post.change.apply', 'media.upload_and_assign.inline'] as $operation) {
    if (!in_array($operation, $operations, true)) {
        crg_fail('Missing compound operation: ' . $operation);
    }
}

$post_contract = $catalog['routes']['operations']['post_compound_change'] ?? [];
if (($post_contract['plan_version'] ?? null) !== 1
    || empty($post_contract['revision_backup_for_revisioned_fields'])
    || ($post_contract['rollback_mode'] ?? '') !== 'compensating_rest_update_plus_revision_fallback'
    || ($post_contract['database_transaction_claimed'] ?? true) !== false
    || empty($post_contract['rollback_reports_durable_state_restored'])
    || empty($post_contract['unrelated_untouched_metadata_may_change_between_preview_and_apply'])) {
    crg_fail('Compound post change contract is incomplete.');
}

$media_contract = $catalog['routes']['operations']['media_upload_and_assign_inline'] ?? [];
if (($media_contract['operation'] ?? '') !== 'media.upload_and_assign.inline'
    || ($media_contract['max_decoded_bytes'] ?? 0) !== 1048576
    || empty($media_contract['rechecks_featured_media_after_upload_before_assignment'])
    || empty($media_contract['new_attachment_cleanup_on_assignment_failure'])
    || empty($media_contract['cleanup_never_restores_over_a_post_upload_field_conflict'])
    || empty($media_contract['larger_files_use_staged_media_then_guarded_post_update'])) {
    crg_fail('Integrated media assignment contract is incomplete.');
}

$preview_template = $catalog['command_contract']['templates']['post_change_preview'] ?? [];
$apply_template = $catalog['command_contract']['templates']['post_change_apply'] ?? [];
$media_template = $catalog['command_contract']['templates']['media_upload_and_assign_inline'] ?? [];
if (($preview_template['operation'] ?? '') !== 'post.change.preview'
    || ($apply_template['operation'] ?? '') !== 'post.change.apply'
    || !isset($apply_template['params']['expected_plan_hash'], $apply_template['params']['confirm'])
    || ($media_template['operation'] ?? '') !== 'media.upload_and_assign.inline'
    || !isset($media_template['params']['expected_featured_media_sha256'], $media_template['params']['confirm'])) {
    crg_fail('Compound command templates are incomplete.');
}

$rules = implode("\n", $catalog['command_contract']['rules'] ?? []);
if (strpos($rules, 'prefer post.change.preview then post.change.apply') === false
    || strpos($rules, 'not a database transaction') === false
    || strpos($rules, 'media.upload_and_assign.inline') === false) {
    crg_fail('Compound command rules are incomplete.');
}

$recipes = $catalog['task_recipes'] ?? [];
if (($recipes['compound_post_change']['steps'][0] ?? '') !== 'post.change.preview'
    || ($recipes['featured_image_small_file']['steps'][0] ?? '') !== 'post.get'
    || ($recipes['featured_image_small_file']['steps'][1] ?? '') !== 'media.upload_and_assign.inline with expected_featured_media_sha256') {
    crg_fail('Compound task recipes are incomplete.');
}
if (!isset(
    $catalog['failure_policy']['post_change_plan_409'],
    $catalog['failure_policy']['post_change_rollback_incomplete'],
    $catalog['failure_policy']['media_assign_conflict_after_upload']
)) {
    crg_fail('Compound failure policy is incomplete.');
}
if (strpos((string) ($catalog['fast_path']['compound_post_change'] ?? ''), 'post.change') === false
    || strpos((string) ($catalog['fast_path']['small_featured_image'] ?? ''), 'media.upload_and_assign.inline') === false) {
    crg_fail('Compound fast-path guidance is incomplete.');
}

$agents = "# WP Agent Bridge\n\nExisting guidance.\n";
$once = TakKa_WordPress_Bridge_Compound_Runtime_Guidance::enrich_agents($agents);
$twice = TakKa_WordPress_Bridge_Compound_Runtime_Guidance::enrich_agents($once);
if ($once !== $twice
    || substr_count($once, '<!-- WPAB_COMPOUND_MUTATIONS_START -->') !== 1
    || strpos($once, '`post.change.preview`') === false
    || strpos($once, 'not a database transaction') === false
    || strpos($once, '`media.upload_and_assign.inline`') === false
    || strpos($once, '`expected_featured_media_sha256`') === false
    || strpos($once, 'preserve that newer selection') === false) {
    crg_fail('Compound AGENTS guidance is missing or not idempotent.');
}

$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    crg_fail('Compound runtime guidance exposed unexpected sensitive material.');
}

echo "compound-runtime-guidance-test: ok\n";
