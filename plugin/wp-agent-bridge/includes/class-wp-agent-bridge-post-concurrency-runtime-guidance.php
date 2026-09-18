<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Makes the post.update optimistic-concurrency contract visible to model
 * clients that follow the canonical runtime files.
 *
 * The core runtime-capability/guidance generators intentionally remain stable
 * across many Bridge features. This small post-processing layer enriches their
 * generated files after those generators run, so a newly opened chat learns to
 * use the 1.1.32 field-hash guards instead of silently falling back to legacy
 * last-write-wins metadata updates.
 */
final class WP_Agent_Bridge_Post_Concurrency_Runtime_Guidance
{
    private const CAPABILITIES_PATH = 'wordpress-bridge/RUNTIME_CAPABILITIES.json';
    private const AGENTS_PATH = 'AGENTS.md';
    private const OPTION_SIGNATURE = 'wpab_post_concurrency_guidance_signature_v1';
    private const TRANSIENT_LOCK = 'wpab_post_concurrency_guidance_lock_v1';
    private const MARKER_START = '<!-- WPAB_POST_UPDATE_CONCURRENCY_START -->';
    private const MARKER_END = '<!-- WPAB_POST_UPDATE_CONCURRENCY_END -->';

    public static function init(): void
    {
        // Runtime capabilities and canonical AGENTS sync at priorities 30/31.
        // Enrich them after both have completed for this plugin version.
        add_action('init', [self::class, 'maybe_sync'], 33);
    }

    public static function maybe_sync(): void
    {
        $version = self::bridge_version();
        if ($version === '' || get_transient(self::TRANSIENT_LOCK)) {
            return;
        }
        $connection = WP_Agent_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return;
        }

        $signature = $version . ':' . hash('sha256', wp_json_encode(self::contract(), JSON_UNESCAPED_SLASHES));
        if ((string) get_option(self::OPTION_SIGNATURE, '') === $signature) {
            return;
        }

        set_transient(self::TRANSIENT_LOCK, '1', 300);
        $result = self::sync();
        if (!is_wp_error($result)) {
            update_option(self::OPTION_SIGNATURE, $signature, false);
            delete_transient(self::TRANSIENT_LOCK);
        }
    }

    public static function sync()
    {
        $connection = WP_Agent_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_post_concurrency_guidance_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = WP_Agent_Bridge_Direct_GitHub::installation_token(
            (int) $connection['installation_id'],
            (int) $connection['repository_id']
        );
        if (is_wp_error($token)) {
            return $token;
        }
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];

        $capabilities = WP_Agent_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            $branch,
            self::CAPABILITIES_PATH
        );
        if (is_wp_error($capabilities)) {
            return $capabilities;
        }
        $decoded = json_decode($capabilities, true);
        if (!is_array($decoded)) {
            return new WP_Error('wpab_post_concurrency_guidance_catalog', 'Runtime capability catalog is not valid JSON.', ['status' => 500]);
        }
        $enriched = self::enrich_capabilities($decoded);
        $catalog_json = wp_json_encode($enriched, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($catalog_json)) {
            return new WP_Error('wpab_post_concurrency_guidance_json', 'Could not encode enriched runtime capability catalog.', ['status' => 500]);
        }
        $catalog_json .= "\n";

        $agents = WP_Agent_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            $branch,
            self::AGENTS_PATH
        );
        if (is_wp_error($agents)) {
            return $agents;
        }
        $agents_enriched = self::enrich_agents($agents);

        $results = [];
        foreach ([
            self::CAPABILITIES_PATH => [$capabilities, $catalog_json],
            self::AGENTS_PATH => [$agents, $agents_enriched],
        ] as $path => $pair) {
            [$before, $after] = $pair;
            if (hash_equals(hash('sha256', $before), hash('sha256', $after))) {
                $results[$path] = ['changed' => false, 'sha256' => hash('sha256', $after)];
                continue;
            }
            $written = WP_Agent_Bridge_Direct_GitHub::put_text_file(
                $token,
                $repository,
                $branch,
                $path,
                $after,
                'WP Agent Bridge: sync post.update concurrency guidance'
            );
            if (is_wp_error($written)) {
                return $written;
            }
            $results[$path] = ['changed' => true, 'sha256' => hash('sha256', $after)];
        }
        return ['ok' => true, 'files' => $results];
    }

    public static function enrich_capabilities(array $catalog): array
    {
        $contract = self::contract();

        if (!isset($catalog['features']) || !is_array($catalog['features'])) {
            $catalog['features'] = [];
        }
        $catalog['features']['post_metadata_optimistic_concurrency'] = true;
        $catalog['features']['post_update_field_compare_and_swap'] = true;

        if (!isset($catalog['routes']) || !is_array($catalog['routes'])) {
            $catalog['routes'] = [];
        }
        if (!isset($catalog['routes']['operations']) || !is_array($catalog['routes']['operations'])) {
            $catalog['routes']['operations'] = [];
        }
        $catalog['routes']['operations']['post_update_concurrency'] = $contract;

        if (!isset($catalog['command_contract']) || !is_array($catalog['command_contract'])) {
            $catalog['command_contract'] = [];
        }
        $catalog['command_contract']['post_update_concurrency'] = $contract;
        if (!isset($catalog['command_contract']['templates']) || !is_array($catalog['command_contract']['templates'])) {
            $catalog['command_contract']['templates'] = [];
        }
        $catalog['command_contract']['templates']['post_update'] = [
            'operation' => 'post.update',
            'params' => [
                'post_id' => '<verified id from post.get>',
                'fields' => '<WordPress REST fields object>',
                'expected_field_hashes' => '<post.get.concurrency.field_hashes for every supported field being changed>',
            ],
        ];
        if (!isset($catalog['command_contract']['rules']) || !is_array($catalog['command_contract']['rules'])) {
            $catalog['command_contract']['rules'] = [];
        }
        self::append_unique($catalog['command_contract']['rules'],
            'For new post.update mutations, first obtain or reuse the same object\'s post.get concurrency snapshot and send expected_field_hashes for every supported field being changed. Preserve target_url and include the verified post_id when a URL selected the object.');
        self::append_unique($catalog['command_contract']['rules'],
            'Do not use legacy unguarded post.update in new workflows. Field-level guards allow unrelated metadata changes from another chat to coexist, but a stale write to the same guarded field must stop at 409.');

        if (!isset($catalog['task_recipes']) || !is_array($catalog['task_recipes'])) {
            $catalog['task_recipes'] = [];
        }
        $catalog['task_recipes']['post_metadata_update'] = [
            'steps' => [
                'post.get for bounded metadata plus concurrency snapshot',
                'post.update with expected_field_hashes for each supported field being changed',
                'on 409 re-read only the affected metadata and retry once with fresh hashes',
            ],
            'rule' => 'Different fields may be updated from the same older snapshot when their individual hashes still match; never overwrite a same-field 409.',
        ];
        foreach (['featured_image_small_file', 'featured_image_large_file'] as $recipe) {
            if (isset($catalog['task_recipes'][$recipe]) && is_array($catalog['task_recipes'][$recipe])) {
                $steps = $catalog['task_recipes'][$recipe]['steps'] ?? [];
                if (is_array($steps)) {
                    $last = array_pop($steps);
                    $steps[] = 'post.get for current featured_media concurrency hash';
                    $steps[] = 'post.update fields.featured_media with expected_field_hashes.featured_media';
                    $catalog['task_recipes'][$recipe]['steps'] = $steps;
                }
                $catalog['task_recipes'][$recipe]['rule'] = trim((string) ($catalog['task_recipes'][$recipe]['rule'] ?? ''))
                    . ' Guard the featured_media assignment with the current field hash.';
            }
        }

        if (!isset($catalog['failure_policy']) || !is_array($catalog['failure_policy'])) {
            $catalog['failure_policy'] = [];
        }
        $catalog['failure_policy']['post_update_concurrency_409'] =
            'Do not drop the guard or replay the stale value. Re-read only the affected metadata, preserve unrelated successful changes, then retry once with fresh field hashes if the requested edit is still valid.';

        if (!isset($catalog['fast_path']) || !is_array($catalog['fast_path'])) {
            $catalog['fast_path'] = [];
        }
        $catalog['fast_path']['post_metadata_update'] =
            'Use post.get -> post.update with expected_field_hashes. Reuse a recent snapshot when it is still the basis of the requested edit. This preserves non-overlapping edits from other chats and rejects stale same-field writes.';

        return $catalog;
    }

    public static function enrich_agents(string $agents): string
    {
        $section = self::MARKER_START . "\n"
            . "## Multi-chat post metadata concurrency\n\n"
            . "- For every new `post.update`, obtain or reuse the same object's `post.get` response first. Use its `concurrency.field_hashes` and send `expected_field_hashes` for every supported field being changed. Legacy unguarded `post.update` remains compatibility-only; do not use it for new work.\n"
            . "- If the user selected the object by `target_url`, preserve that URL and include the `post_id` verified by `post.get` when sending the guarded update. Do not retarget after a conflict.\n"
            . "- Field hashes are intentionally independent: if another chat changed only a different metadata field, your unchanged target field may still update safely. If the same guarded field changed, Bridge returns 409 with no mutation. Never remove the guard to force the stale value through.\n"
            . "- For complex fields outside the bounded field-hash snapshot, also use `expected_state_hash` or `expected_modified_gmt`. On a concurrency 409, re-read only the affected metadata and retry once with fresh evidence if the user's requested edit is still valid.\n"
            . "- The canonical Direct Runtime serializes authenticated push handling. Combined with these field preconditions, normal multi-chat metadata mutations are checked immediately before the guarded WordPress operation rather than being merged by last-write-wins guesswork.\n"
            . self::MARKER_END;

        $pattern = '~' . preg_quote(self::MARKER_START, '~') . '.*?' . preg_quote(self::MARKER_END, '~') . '~s';
        if (preg_match($pattern, $agents)) {
            $replaced = preg_replace($pattern, $section, $agents, 1);
            return is_string($replaced) ? $replaced : $agents;
        }
        return rtrim($agents) . "\n\n" . $section . "\n";
    }

    public static function contract(): array
    {
        return [
            'version' => 1,
            'operation' => 'post.update',
            'snapshot_source' => 'post.get.concurrency',
            'preferred_guard' => 'expected_field_hashes',
            'guarded_fields' => [
                'title', 'excerpt', 'slug', 'status', 'author', 'featured_media',
                'comment_status', 'ping_status', 'sticky', 'template', 'format',
                'categories', 'tags', 'parent', 'menu_order', 'date', 'date_gmt',
            ],
            'non_overlapping_stale_snapshot_updates_can_coexist' => true,
            'same_field_stale_write_status' => 409,
            'same_field_conflict_side_effects' => false,
            'fallback_guards_for_complex_fields' => ['expected_state_hash', 'expected_modified_gmt'],
            'legacy_unguarded_update' => 'compatibility_only_do_not_use_for_new_workflows',
            'canonical_runtime_serializes_authenticated_pushes' => true,
        ];
    }

    private static function append_unique(array &$items, string $value): void
    {
        if (!in_array($value, $items, true)) {
            $items[] = $value;
        }
    }

    private static function bridge_version(): string
    {
        if (!function_exists('get_file_data')) {
            return '';
        }
        $data = get_file_data(dirname(__DIR__) . '/wp-agent-bridge.php', ['Version' => 'Version'], 'plugin');
        return isset($data['Version']) ? trim((string) $data['Version']) : '';
    }

    private static function valid_connection(array $connection): bool
    {
        return (int) ($connection['installation_id'] ?? 0) > 0
            && (int) ($connection['repository_id'] ?? 0) > 0
            && preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) ($connection['repository'] ?? '')) === 1
            && (string) ($connection['runtime_branch'] ?? '') === WP_Agent_Bridge_Direct_Runtime::RUNTIME_BRANCH;
    }
}
