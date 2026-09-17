<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Publish compound post/media mutation contracts after the base runtime syncs. */
final class TakKa_WordPress_Bridge_Compound_Runtime_Guidance
{
    private const CAPABILITIES_PATH = 'wordpress-bridge/RUNTIME_CAPABILITIES.json';
    private const AGENTS_PATH = 'AGENTS.md';
    private const OPTION_SIGNATURE = 'takka_bridge_compound_runtime_guidance_signature_v1';
    private const TRANSIENT_LOCK = 'takka_bridge_compound_runtime_guidance_lock_v1';
    private const MARKER_START = '<!-- WPAB_COMPOUND_MUTATIONS_START -->';
    private const MARKER_END = '<!-- WPAB_COMPOUND_MUTATIONS_END -->';

    public static function init(): void
    {
        // Base 30/31, concurrency 33, post reliability 34.
        add_action('init', [self::class, 'maybe_sync'], 35);
    }

    public static function maybe_sync(): void
    {
        $version = self::bridge_version();
        if ($version === '' || get_transient(self::TRANSIENT_LOCK)) {
            return;
        }
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return;
        }
        $signature = $version . ':' . hash('sha256', (string) wp_json_encode(self::contract_material(), JSON_UNESCAPED_SLASHES));
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
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_compound_guidance_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }
        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token(
            (int) $connection['installation_id'],
            (int) $connection['repository_id']
        );
        if (is_wp_error($token)) {
            return $token;
        }
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];

        $catalog_text = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, self::CAPABILITIES_PATH);
        if (is_wp_error($catalog_text)) {
            return $catalog_text;
        }
        $catalog = json_decode($catalog_text, true);
        if (!is_array($catalog)) {
            return new WP_Error('wpab_compound_guidance_catalog', 'Runtime capability catalog is not valid JSON.', ['status' => 500]);
        }
        $catalog = self::enrich_capabilities($catalog);
        $catalog_json = wp_json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($catalog_json)) {
            return new WP_Error('wpab_compound_guidance_json', 'Could not encode compound runtime capability catalog.', ['status' => 500]);
        }
        $catalog_json .= "\n";

        $agents = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, self::AGENTS_PATH);
        if (is_wp_error($agents)) {
            return $agents;
        }
        $agents_enriched = self::enrich_agents($agents);

        $results = [];
        foreach ([
            self::CAPABILITIES_PATH => [$catalog_text, $catalog_json],
            self::AGENTS_PATH => [$agents, $agents_enriched],
        ] as $path => $pair) {
            [$before, $after] = $pair;
            if (hash_equals(hash('sha256', $before), hash('sha256', $after))) {
                $results[$path] = ['changed' => false, 'sha256' => hash('sha256', $after)];
                continue;
            }
            $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
                $token,
                $repository,
                $branch,
                $path,
                $after,
                'WP Agent Bridge: sync compound mutation guidance'
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
        if (!isset($catalog['features']) || !is_array($catalog['features'])) {
            $catalog['features'] = [];
        }
        foreach ([
            'post_compound_guarded_change',
            'post_compensating_rollback',
            'media_inline_upload_assign',
            'media_assignment_cleanup_on_failure',
        ] as $feature) {
            $catalog['features'][$feature] = true;
        }

        if (!isset($catalog['routes']['operations']) || !is_array($catalog['routes']['operations'])) {
            $catalog['routes']['operations'] = [];
        }
        $ops = isset($catalog['routes']['operations']['operations']) && is_array($catalog['routes']['operations']['operations'])
            ? $catalog['routes']['operations']['operations']
            : [];
        foreach (['post.change.preview', 'post.change.apply', 'media.upload_and_assign.inline'] as $operation) {
            if (!in_array($operation, $ops, true)) {
                $ops[] = $operation;
            }
        }
        $catalog['routes']['operations']['operations'] = $ops;
        $catalog['routes']['operations']['post_compound_change'] = TakKa_WordPress_Bridge_Post_Compound_Change::contract();
        $catalog['routes']['operations']['media_upload_and_assign_inline'] = TakKa_WordPress_Bridge_Media_Upload_Assign::contract();

        if (!isset($catalog['command_contract']['templates']) || !is_array($catalog['command_contract']['templates'])) {
            $catalog['command_contract']['templates'] = [];
        }
        $catalog['command_contract']['templates']['post_change_preview'] = [
            'operation' => 'post.change.preview',
            'params' => [
                'post_id' => '<verified id>',
                'fields' => '<optional safe metadata fields object>',
                'content_patch' => '<optional exact find/replace object>',
            ],
        ];
        $catalog['command_contract']['templates']['post_change_apply'] = [
            'operation' => 'post.change.apply',
            'params' => [
                'post_id' => '<verified id>',
                'fields' => '<same fields as preview>',
                'content_patch' => '<same patch as preview>',
                'expected_plan_hash' => '<preview plan_hash>',
                'confirm' => true,
                'confirm_live' => '<true when required>',
            ],
        ];
        $catalog['command_contract']['templates']['media_upload_and_assign_inline'] = [
            'operation' => 'media.upload_and_assign.inline',
            'params' => [
                'post_id' => '<verified id>',
                'expected_featured_media_sha256' => '<post.get concurrency.field_hashes.featured_media>',
                'filename' => '<name>',
                'data_b64' => '<base64>',
                'expected_bytes' => '<recommended>',
                'expected_sha256' => '<recommended>',
                'confirm' => true,
                'confirm_live' => '<true for non-draft>',
            ],
        ];

        if (!isset($catalog['command_contract']['rules']) || !is_array($catalog['command_contract']['rules'])) {
            $catalog['command_contract']['rules'] = [];
        }
        self::append_unique($catalog['command_contract']['rules'],
            'When one user request changes post content plus one or more supported metadata fields, prefer post.change.preview then post.change.apply so one guarded plan is revalidated and partial durable state is compensated on failure.');
        self::append_unique($catalog['command_contract']['rules'],
            'post.change.apply is a compensating workflow, not a database transaction. If a mutation attempt fails and durable state is restored, side_effects may still be true because WordPress/plugin hooks may already have fired.');
        self::append_unique($catalog['command_contract']['rules'],
            'For a small featured image upload up to 1 MiB, prefer media.upload_and_assign.inline with the featured_media field hash from post.get. It rechecks the field after upload and deletes the new attachment if assignment cannot safely finish.');

        if (!isset($catalog['task_recipes']) || !is_array($catalog['task_recipes'])) {
            $catalog['task_recipes'] = [];
        }
        $catalog['task_recipes']['compound_post_change'] = [
            'steps' => ['post.change.preview', 'post.change.apply with the identical specification and preview plan_hash'],
            'rule' => 'Use when content and supported metadata belong to one logical user change. Unrelated untouched metadata may change between preview/apply without invalidating the plan.',
        ];
        $catalog['task_recipes']['featured_image_small_file'] = [
            'steps' => ['post.get', 'media.upload_and_assign.inline with expected_featured_media_sha256'],
            'rule' => 'For decoded files up to 1 MiB, combine upload and guarded assignment so an assignment failure does not intentionally leave a new orphan attachment.',
        ];

        if (!isset($catalog['failure_policy']) || !is_array($catalog['failure_policy'])) {
            $catalog['failure_policy'] = [];
        }
        $catalog['failure_policy']['post_change_plan_409'] =
            'Re-read only the relevant touched fields/content, produce a new post.change.preview, and apply the new exact plan. Do not reuse or remove a stale plan hash.';
        $catalog['failure_policy']['post_change_rollback_incomplete'] =
            'Treat durable state as requiring inspection. Do not issue another mutation until the reported touched fields/content are re-read.';
        $catalog['failure_policy']['media_assign_conflict_after_upload'] =
            'Do not overwrite the newer featured-media choice. Inspect cleanup.deleted; if false, clean only the newly created attachment after confirming it is not assigned.';

        if (!isset($catalog['fast_path']) || !is_array($catalog['fast_path'])) {
            $catalog['fast_path'] = [];
        }
        $catalog['fast_path']['compound_post_change'] =
            'For one logical edit spanning content and supported metadata, use one post.change preview/apply plan instead of serial independent mutations.';
        $catalog['fast_path']['small_featured_image'] =
            'Use media.upload_and_assign.inline for <=1 MiB only after post.get supplies the featured_media field hash. Larger files remain on staged media + guarded post.update.';

        return $catalog;
    }

    public static function enrich_agents(string $agents): string
    {
        $section = self::MARKER_START . "\n"
            . "## Compound post and media mutations\n\n"
            . "- If one user request changes post content and supported metadata together, use `post.change.preview` then `post.change.apply` with the identical specification and `expected_plan_hash`. This is preferred to independent serial mutations for one logical edit.\n"
            . "- `post.change.apply` uses guarded preconditions, a WordPress revision backup for title/content/excerpt, post-update verification, and compensating rollback when a later phase fails. It is not a database transaction. A response can report `durable_state_restored=true` while `side_effects=true` because hooks may have fired during the attempted mutation.\n"
            . "- A stale compound plan returns 409 before mutation. Re-read only the touched state and create a new preview; never remove `expected_plan_hash` to force an old edit.\n"
            . "- For a featured image up to 1 MiB decoded, prefer `media.upload_and_assign.inline` after `post.get`. Send `expected_featured_media_sha256` from `post.get.concurrency.field_hashes.featured_media`, plus byte/SHA integrity when known. The Bridge rechecks the field after upload and deletes the newly uploaded attachment if safe assignment cannot finish.\n"
            . "- If featured media changes while upload is running, preserve that newer selection. Do not restore the old field value merely to complete the upload. For files above 1 MiB, keep the staged media path and use a separate guarded `post.update`.\n"
            . self::MARKER_END;

        $pattern = '~' . preg_quote(self::MARKER_START, '~') . '.*?' . preg_quote(self::MARKER_END, '~') . '~s';
        if (preg_match($pattern, $agents)) {
            $replaced = preg_replace($pattern, $section, $agents, 1);
            return is_string($replaced) ? $replaced : $agents;
        }
        return rtrim($agents) . "\n\n" . $section . "\n";
    }

    private static function contract_material(): array
    {
        return [
            'post_compound' => TakKa_WordPress_Bridge_Post_Compound_Change::contract(),
            'media_assign' => TakKa_WordPress_Bridge_Media_Upload_Assign::contract(),
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
        $data = get_file_data(dirname(__DIR__) . '/takka-wordpress-bridge.php', ['Version' => 'Version'], 'plugin');
        return isset($data['Version']) ? trim((string) $data['Version']) : '';
    }

    private static function valid_connection(array $connection): bool
    {
        return (int) ($connection['installation_id'] ?? 0) > 0
            && (int) ($connection['repository_id'] ?? 0) > 0
            && preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) ($connection['repository'] ?? '')) === 1
            && (string) ($connection['runtime_branch'] ?? '') === TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH;
    }
}
