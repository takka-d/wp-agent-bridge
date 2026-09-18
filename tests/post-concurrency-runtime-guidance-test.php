<?php

declare(strict_types=1);

define('ABSPATH', __DIR__ . '/');
require_once __DIR__ . '/../plugin/wp-agent-bridge/includes/class-wp-agent-bridge-post-concurrency-runtime-guidance.php';

function pcrg_fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$base = [
    'features' => ['deterministic_operation_router' => true],
    'routes' => ['operations' => ['route' => '/wpab-v099/v1/operate']],
    'command_contract' => [
        'rules' => ['existing rule'],
        'templates' => [
            'post_update' => ['operation' => 'post.update', 'params' => ['post_id' => '<id>', 'fields' => '<object>']],
        ],
    ],
    'task_recipes' => [
        'featured_image_small_file' => [
            'steps' => ['media.upload.inline', 'post.update with fields.featured_media'],
            'rule' => 'small rule',
        ],
        'featured_image_large_file' => [
            'steps' => ['stage media', 'publish media', 'post.update with fields.featured_media'],
            'rule' => 'large rule',
        ],
    ],
    'failure_policy' => [],
    'fast_path' => [],
];

$catalog = WP_Agent_Bridge_Post_Concurrency_Runtime_Guidance::enrich_capabilities($base);
$contract = $catalog['command_contract']['post_update_concurrency'] ?? null;
if (!is_array($contract)
    || ($contract['preferred_guard'] ?? null) !== 'expected_field_hashes'
    || empty($contract['non_overlapping_stale_snapshot_updates_can_coexist'])
    || ($contract['same_field_stale_write_status'] ?? null) !== 409
    || !empty($contract['same_field_conflict_side_effects'])
    || empty($contract['canonical_runtime_serializes_authenticated_pushes'])) {
    pcrg_fail('Post-update concurrency contract enrichment is incomplete.');
}
if (empty($catalog['features']['post_metadata_optimistic_concurrency'])
    || empty($catalog['features']['post_update_field_compare_and_swap'])) {
    pcrg_fail('Post concurrency feature flags are missing.');
}
$template = $catalog['command_contract']['templates']['post_update']['params'] ?? [];
if (!isset($template['expected_field_hashes'])
    || strpos((string) $template['expected_field_hashes'], 'post.get.concurrency.field_hashes') === false) {
    pcrg_fail('post.update template does not require the concurrency snapshot.');
}
$rules = implode("\n", $catalog['command_contract']['rules'] ?? []);
if (strpos($rules, 'legacy unguarded post.update') === false
    || strpos($rules, 'expected_field_hashes') === false) {
    pcrg_fail('Concurrency command rules are missing.');
}
$recipe = $catalog['task_recipes']['post_metadata_update'] ?? null;
if (!is_array($recipe)
    || strpos(implode("\n", $recipe['steps'] ?? []), 'expected_field_hashes') === false
    || strpos((string) ($recipe['rule'] ?? ''), 'Different fields') === false) {
    pcrg_fail('Post metadata concurrency task recipe is missing.');
}
foreach (['featured_image_small_file', 'featured_image_large_file'] as $name) {
    $steps = implode("\n", $catalog['task_recipes'][$name]['steps'] ?? []);
    if (strpos($steps, 'featured_media concurrency hash') === false
        || strpos($steps, 'expected_field_hashes.featured_media') === false) {
        pcrg_fail('Featured-image recipe is not concurrency guarded: ' . $name);
    }
}
if (strpos((string) ($catalog['failure_policy']['post_update_concurrency_409'] ?? ''), 'Do not drop the guard') === false
    || strpos((string) ($catalog['fast_path']['post_metadata_update'] ?? ''), 'post.get -> post.update') === false) {
    pcrg_fail('Concurrency retry/fast-path guidance is missing.');
}

$agents = "# WP Agent Bridge\n\nExisting guidance.\n";
$once = WP_Agent_Bridge_Post_Concurrency_Runtime_Guidance::enrich_agents($agents);
$twice = WP_Agent_Bridge_Post_Concurrency_Runtime_Guidance::enrich_agents($once);
if ($once !== $twice
    || substr_count($once, '<!-- WPAB_POST_UPDATE_CONCURRENCY_START -->') !== 1
    || strpos($once, 'Legacy unguarded `post.update` remains compatibility-only') === false
    || strpos($once, 'another chat changed only a different metadata field') === false
    || strpos($once, 'Never remove the guard') === false
    || strpos($once, 'serializes authenticated push handling') === false) {
    pcrg_fail('AGENTS concurrency guidance is missing or not idempotent.');
}

$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json)
    || strpos($json, 'PRIVATE KEY') !== false
    || strpos($json, 'webhook_secret') !== false
    || strpos($json, 'installation_id') !== false) {
    pcrg_fail('Enriched capability catalog exposed unexpected sensitive material.');
}

echo "post-concurrency-runtime-guidance-test: ok\n";
