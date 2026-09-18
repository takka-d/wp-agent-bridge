<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publishes post reliability contracts to the canonical runtime files after the
 * base catalog/guidance and post-concurrency enrichment have completed.
 */
final class WP_Agent_Bridge_Post_Reliability_Runtime_Guidance
{
    private const CAPABILITIES_PATH = 'wordpress-bridge/RUNTIME_CAPABILITIES.json';
    private const AGENTS_PATH = 'AGENTS.md';
    private const OPTION_SIGNATURE = 'wpab_post_reliability_guidance_signature_v1';
    private const TRANSIENT_LOCK = 'wpab_post_reliability_guidance_lock_v1';
    private const MARKER_START = '<!-- WPAB_POST_RELIABILITY_START -->';
    private const MARKER_END = '<!-- WPAB_POST_RELIABILITY_END -->';

    public static function init(): void
    {
        // Base runtime syncs at 30/31 and post concurrency enrichment at 33.
        add_action('init', [self::class, 'maybe_sync'], 34);
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
        $connection = WP_Agent_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_post_reliability_guidance_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
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

        $capabilities = WP_Agent_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, self::CAPABILITIES_PATH);
        if (is_wp_error($capabilities)) {
            return $capabilities;
        }
        $decoded = json_decode($capabilities, true);
        if (!is_array($decoded)) {
            return new WP_Error('wpab_post_reliability_guidance_catalog', 'Runtime capability catalog is not valid JSON.', ['status' => 500]);
        }
        $enriched = self::enrich_capabilities($decoded);
        $catalog_json = wp_json_encode($enriched, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($catalog_json)) {
            return new WP_Error('wpab_post_reliability_guidance_json', 'Could not encode enriched runtime capability catalog.', ['status' => 500]);
        }
        $catalog_json .= "\n";

        $agents = WP_Agent_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, self::AGENTS_PATH);
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
                'WP Agent Bridge: sync post reliability guidance'
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
            'post_revision_rollback',
            'post_content_patch_match_diagnostics',
            'deterministic_operation_response_contract',
            'explicit_bridge_version_semantics',
        ] as $feature) {
            $catalog['features'][$feature] = true;
        }

        $catalog['version_fields'] = [
            'plugin_version' => (string) ($catalog['bridge_version'] ?? self::bridge_version()),
            'operation_api_version' => '0.9.9',
            'runtime_schema_version' => (int) ($catalog['schema'] ?? 2),
            'health_bridge_version_semantics' => 'legacy_api_compatibility_version',
        ];

        if (!isset($catalog['routes']) || !is_array($catalog['routes'])) {
            $catalog['routes'] = [];
        }
        if (!isset($catalog['routes']['operations']) || !is_array($catalog['routes']['operations'])) {
            $catalog['routes']['operations'] = [];
        }
        $operations = isset($catalog['routes']['operations']['operations']) && is_array($catalog['routes']['operations']['operations'])
            ? $catalog['routes']['operations']['operations']
            : [];
        foreach (WP_Agent_Bridge_Post_Revisions::contract()['operations'] as $operation) {
            if (!in_array($operation, $operations, true)) {
                $operations[] = $operation;
            }
        }
        $catalog['routes']['operations']['operations'] = $operations;
        $catalog['routes']['operations']['post_revisions'] = WP_Agent_Bridge_Post_Revisions::contract();
        $catalog['routes']['operations']['post_content_patch_diagnostics'] = WP_Agent_Bridge_Post_Content_Diagnostics::contract();
        $catalog['routes']['operations']['response_contract'] = WP_Agent_Bridge_V099_Response_Contract::contract();

        if (!isset($catalog['command_contract']) || !is_array($catalog['command_contract'])) {
            $catalog['command_contract'] = [];
        }
        $catalog['command_contract']['response_contract'] = WP_Agent_Bridge_V099_Response_Contract::contract();
        if (!isset($catalog['command_contract']['templates']) || !is_array($catalog['command_contract']['templates'])) {
            $catalog['command_contract']['templates'] = [];
        }
        $catalog['command_contract']['templates']['post_revisions_list'] = [
            'operation' => 'post.revisions.list',
            'params' => ['post_id' => '<verified id>', 'limit' => 20],
        ];
        $catalog['command_contract']['templates']['post_revisions_get'] = [
            'operation' => 'post.revisions.get',
            'params' => ['post_id' => '<verified id>', 'revision_id' => '<revision id>', 'include_content' => false],
        ];
        $catalog['command_contract']['templates']['post_revisions_restore'] = [
            'operation' => 'post.revisions.restore',
            'params' => [
                'post_id' => '<verified id>',
                'revision_id' => '<revision id>',
                'expected_current_revision_fields_hash' => '<post.revisions.list current_revision_fields_hash>',
                'confirm' => true,
                'confirm_live' => '<true for non-draft>',
            ],
        ];
        if (!isset($catalog['command_contract']['rules']) || !is_array($catalog['command_contract']['rules'])) {
            $catalog['command_contract']['rules'] = [];
        }
        self::append_unique($catalog['command_contract']['rules'],
            'Treat post.content patch approximate/whitespace candidates as read-only location hints. Never convert them into a write target without a new exact preview.');
        self::append_unique($catalog['command_contract']['rules'],
            'Use post.revisions.list/get/restore for explicit title/content/excerpt rollback. Restore requires the current revision_fields_hash and a rollback revision; it does not restore taxonomy, featured media, or arbitrary meta.');
        self::append_unique($catalog['command_contract']['rules'],
            'Decode deterministic operation responses by ok/status/code/message and side_effects. side_effects=null means the operation response did not prove whether a mutation happened.');

        if (!isset($catalog['task_recipes']) || !is_array($catalog['task_recipes'])) {
            $catalog['task_recipes'] = [];
        }
        $catalog['task_recipes']['post_revision_rollback'] = [
            'steps' => [
                'post.revisions.list',
                'post.revisions.get for the selected revision when content inspection is needed',
                'post.revisions.restore with current_revision_fields_hash and confirm=true',
            ],
            'rule' => 'Re-read the current revision state on 409. Never force a stale restore. Revision rollback covers title/content/excerpt only.',
        ];
        $catalog['task_recipes']['post_content_match_conflict'] = [
            'steps' => [
                'inspect 409 diagnostics',
                'use bounded candidate excerpts only to locate current text',
                'issue a new exact post.content.patch_preview',
                'apply only the exact guarded plan',
            ],
            'rule' => 'Normalized or approximate candidates are never writable by themselves.',
        ];

        if (!isset($catalog['failure_policy']) || !is_array($catalog['failure_policy'])) {
            $catalog['failure_policy'] = [];
        }
        $catalog['failure_policy']['post_content_match_409'] =
            'Use diagnostics as read-only location evidence, then produce a new exact preview. Do not auto-apply a whitespace-normalized or approximate candidate.';
        $catalog['failure_policy']['post_revision_restore_409'] =
            'Re-read post.revisions.list and compare the current revision_fields_hash. Do not remove the guard or restore over newer title/content/excerpt changes.';

        if (!isset($catalog['fast_path']) || !is_array($catalog['fast_path'])) {
            $catalog['fast_path'] = [];
        }
        $catalog['fast_path']['post_revision_rollback'] =
            'Use the deterministic post.revisions.* operations. They are on the same v0.9.9 operation route and require a fresh current revision_fields_hash before restore.';
        $catalog['fast_path']['post_content_match_diagnostics'] =
            'On an exact-match 409, consume the bounded diagnostics from that same failure before performing any broader search. Candidates are hints only.';

        return $catalog;
    }

    public static function enrich_agents(string $agents): string
    {
        $section = self::MARKER_START . "\n"
            . "## Post reliability and rollback\n\n"
            . "- Deterministic operation errors use a stable envelope: `ok`, `operation`, `status`, `code`, `message`, `data`, and `side_effects`. `side_effects=null` means the response does not prove whether a mutation occurred; inspect the matching result/state instead of guessing.\n"
            . "- A `post.content.patch_preview` or apply exact-match 409 may include bounded whitespace-normalized and approximate candidates. They are read-only location hints. Never auto-write a candidate; locate the current text and create a new exact preview/plan.\n"
            . "- Use `post.revisions.list`, `post.revisions.get`, and `post.revisions.restore` for explicit rollback of WordPress revision fields only: title, content, and excerpt. Revisions do not restore taxonomy, featured media, or arbitrary post meta.\n"
            . "- Before revision restore, use the current `revision_fields_hash` returned by `post.revisions.list`. Send it as `expected_current_revision_fields_hash`; non-draft restore also needs `confirm_live=true`. A 409 means newer revisioned fields exist; re-read rather than removing the guard.\n"
            . "- Health exposes `plugin_version`, `api_compatibility_version`, and `runtime_schema_version` separately. The legacy `bridge_version` health field denotes API compatibility, not the installed plugin release.\n"
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
            'response' => WP_Agent_Bridge_V099_Response_Contract::contract(),
            'content_diagnostics' => WP_Agent_Bridge_Post_Content_Diagnostics::contract(),
            'revisions' => WP_Agent_Bridge_Post_Revisions::contract(),
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
