<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps a deterministic machine-readable capability catalog in the user's
 * canonical private runtime repository.
 */
final class TakKa_WordPress_Bridge_Runtime_Capabilities
{
    private const PATH = 'wordpress-bridge/RUNTIME_CAPABILITIES.json';
    private const OPTION_SYNCED_VERSION = 'takka_bridge_runtime_capabilities_synced_version';
    private const TRANSIENT_SYNC_LOCK = 'takka_bridge_runtime_capabilities_sync_lock';

    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_sync'], 30);
    }

    public static function maybe_sync(): void
    {
        $version = self::bridge_version();
        if ($version === '') {
            return;
        }
        if (get_transient(self::TRANSIENT_SYNC_LOCK)) {
            return;
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return;
        }

        // Disk headers can advance while an older request still has old classes loaded.
        // Stamp the generated contract, so the next fresh request repairs stale content.
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '') $host = 'wordpress';
        $signature = $version . ':' . hash('sha256', (string) wp_json_encode(self::catalog($version, $repository, $branch, $host)));
        if ((string) get_option(self::OPTION_SYNCED_VERSION, '') === $signature) {
            return;
        }

        set_transient(self::TRANSIENT_SYNC_LOCK, '1', 300);
        $result = self::sync();
        if (!is_wp_error($result)) {
            update_option(self::OPTION_SYNCED_VERSION, $signature, false);
            delete_transient(self::TRANSIENT_SYNC_LOCK);
        }
    }

    public static function sync()
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_runtime_capabilities_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $installation_id = (int) $connection['installation_id'];
        $repository_id = (int) $connection['repository_id'];
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];
        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($site_host === '') {
            $site_host = 'wordpress';
        }
        $version = self::bridge_version();
        if ($version === '') {
            return new WP_Error('wpab_runtime_capabilities_version', 'Could not determine the installed Bridge version.', ['status' => 500]);
        }

        $catalog = self::catalog($version, $repository, $branch, $site_host);
        $json = wp_json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return new WP_Error('wpab_runtime_capabilities_json', 'Could not encode runtime capability catalog.', ['status' => 500]);
        }
        $json .= "\n";

        $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, self::PATH);
        if (!is_wp_error($current)) {
            if (hash_equals(hash('sha256', $current), hash('sha256', $json))) {
                return ['ok' => true, 'changed' => false, 'path' => self::PATH, 'sha256' => hash('sha256', $json)];
            }
        } elseif (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($current) !== 404) {
            return $current;
        }

        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $token,
            $repository,
            $branch,
            self::PATH,
            $json,
            'WP Agent Bridge: sync runtime capability catalog'
        );
        if (is_wp_error($written)) {
            return $written;
        }
        return ['ok' => true, 'changed' => true, 'path' => self::PATH, 'sha256' => hash('sha256', $json)];
    }

    public static function catalog(string $version, string $repository, string $branch, string $site_host): array
    {
        $operation_catalog = class_exists('TakKa_WordPress_Bridge_V099_Operations')
            ? TakKa_WordPress_Bridge_V099_Operations::catalog()
            : [];

        return [
            'schema' => 2,
            'bridge_version' => $version,
            'runtime' => [
                'repository' => $repository,
                'branch' => $branch,
                'transport' => 'direct-github-webhook',
                'site_host' => $site_host,
                'pending_path' => 'wordpress-bridge/commands/pending/<id>.json',
                'result_path' => 'wordpress-bridge/results/<id>.json',
                'bookkeeping' => [
                    'mode' => 'atomic_git_tree',
                    'result_completed_pending_same_commit' => true,
                    'max_ref_update_attempts' => 3,
                    'result_visibility_is_completion_barrier' => true,
                ],
            ],
            'release_pointer' => [
                'repository' => 'takka-d/wp-agent-bridge',
                'path' => 'UPDATE_MANIFEST.json',
                'branch' => 'main',
            ],
            'features' => [
                'source_self_update' => true,
                'workspace' => true,
                'workspace_read' => true,
                'workspace_guarded_write' => true,
                'workspace_exact_patch' => true,
                'workspace_snapshot_rollback' => true,
                'readonly_batch' => true,
                'post_content_range_read' => true,
                'deterministic_operation_router' => true,
                'native_operation_command' => true,
                'theme_file_inspection' => true,
                'theme_guarded_write' => true,
                'diagnostics' => true,
                'media_inline_upload' => true,
                'media_fast_path' => true,
                'media_verify_only' => true,
                'site_icon' => true,
                'pending_recovery' => true,
                'request_inflight_guard' => true,
                'local_result_journal' => true,
                'atomic_command_bookkeeping' => true,
            ],
            'routes' => [
                'operations' => $operation_catalog,
                'self_update' => [
                    'route' => '/takka-bridge/v1/v06',
                    'actions' => ['bridge.self_update.status', 'bridge.self_update.apply', 'bridge.self_update.rollback'],
                ],
                'post_content' => [
                    'route' => '/takka-v084/v1/manage',
                    'read_actions' => ['post.content.inspect', 'post.content.search', 'post.content.read.range'],
                    'write_actions' => ['post.content.patch.preview', 'post.content.patch.apply'],
                    'limits' => [
                        'max_content_bytes' => 4194304,
                        'max_read_range_lines' => 1000,
                        'max_read_range_bytes' => 262144,
                    ],
                ],
                'readonly_batch' => [
                    'route' => '/takka-v098/v1/manage',
                    'action' => 'readonly.batch',
                    'limits' => [
                        'max_operations' => 12,
                        'max_operation_params_bytes' => 262144,
                        'max_response_bytes' => 1048576,
                        'max_total_ms' => 20000,
                    ],
                    'direct_actions' => [
                        'v04.capabilities', 'plugin.list', 'theme.manage.list', 'cron.schedules',
                        'admin.capabilities', 'v05.capabilities', 'idempotency.status', 'menu.list',
                        'menu.get', 'updates.status', 'v06.capabilities', 'bridge.self_update.status',
                    ],
                    'rest_actions' => [
                        'post.content.inspect', 'post.content.search', 'post.content.read.range',
                        'v097.capabilities', 'workspace.list', 'workspace.file.get',
                        'workspace.file.read.range', 'workspace.file.search', 'workspace.file.diff',
                        'workspace.snapshot.list', 'theme.files.list', 'theme.files.search',
                        'theme.file.read.many', 'theme.file.outline', 'theme.file.read.range',
                        'page.html.inspect', 'media.file.inspect', 'site.icon.get',
                        'media.upload.capabilities',
                    ],
                    'mutation_actions_allowed' => false,
                    'arbitrary_rest_allowed' => false,
                ],
                'workspace' => [
                    'route' => '/takka-v097/v1/manage',
                    'actions' => [
                        'v097.capabilities', 'workspace.list', 'workspace.file.get',
                        'workspace.file.read.range', 'workspace.file.search', 'workspace.file.write',
                        'workspace.file.patch', 'workspace.file.delete', 'workspace.file.diff',
                        'workspace.snapshot.create', 'workspace.snapshot.list', 'workspace.snapshot.rollback',
                    ],
                    'limits' => [
                        'max_file_bytes' => 2097152,
                        'max_full_read_bytes' => 524288,
                        'max_range_lines' => 1000,
                        'max_search_files' => 100,
                        'max_search_bytes' => 5242880,
                        'max_search_results' => 100,
                    ],
                ],
                'theme_inspection' => [
                    'route' => '/takka-v096/v1/theme-files',
                    'actions' => ['theme.files.list', 'theme.files.search', 'theme.file.read.many'],
                ],
                'diagnostics' => [
                    'route' => '/takka-v094/v1/manage',
                    'actions' => ['http.probe', 'http.probe.batch', 'media.file.inspect'],
                ],
                'structured_inspection' => [
                    'route' => '/takka-v095/v1/manage',
                    'actions' => [
                        'theme.file.outline', 'theme.file.read.range', 'page.html.inspect',
                        'classic_theme.create', 'classic_theme.preview_url', 'classic_theme.publish', 'classic_theme.discard',
                    ],
                ],
                'site_media' => [
                    'route' => '/takka-v096/v1/manage',
                    'actions' => ['site.icon.get', 'site.icon.set', 'site.icon.clear', 'media.upload.capabilities'],
                ],
                'media_inline' => [
                    'route' => '/takka-bridge/v1/manage',
                    'action' => 'media.upload_base64',
                    'max_decoded_bytes' => 6291456,
                    'preferred_max_decoded_bytes' => 1048576,
                ],
                'media_fast_path' => [
                    'upload_route' => '/wp-agent-bridge-runtime/v1/media-upload',
                    'verify_route' => '/wp-agent-bridge-runtime/v1/media-verify',
                    'max_decoded_bytes' => 6291456,
                    'max_chunks' => 32,
                ],
            ],
            'command_contract' => [
                'preferred_command' => [
                    'type' => 'operation',
                    'operation' => '<operation>',
                    'params' => '<object>',
                ],
                'preferred_common_task_route' => '/takka-v099/v1/operate',
                'preferred_common_task_command' => [
                    'type' => 'rest',
                    'method' => 'POST',
                    'route' => '/takka-v099/v1/operate',
                    'body' => [
                        'operation' => '<operation>',
                        'params' => '<object>',
                    ],
                ],
                'rules' => [
                    'Use type=operation with operation and params for common tasks. The runtime supplies the fixed route and method; the REST shape below remains compatible.',
                    'For low-level actions absent from the operation catalog, use type=bridge with action and params. Never proxy /takka-bridge/v1/execute through type=rest.',
                    'Use the deterministic operation router for advertised common operations instead of reconstructing versioned Bridge routes.',
                    'Never append a query string to a REST route field. Put query values in the query object.',
                    'Do not issue capability probe commands or source-code searches for an operation already present in this catalog.',
                    'Do not switch to WPVibe, an operator relay, old Bridge Key, takka-d/chatgpt-data, or a per-command GitHub Actions worker.',
                    'Do not retry a mutating command merely because a later GitHub read failed. First check whether the matching result already exists.',
                ],
                'templates' => [
                    'post_get_edit_context' => [
                        'operation' => 'post.get',
                        'params' => ['post_id' => '<id>', 'query' => ['context' => 'edit']],
                    ],
                    'post_update' => [
                        'operation' => 'post.update',
                        'params' => ['post_id' => '<id>', 'fields' => '<WordPress REST fields object>'],
                    ],
                    'post_content_search' => [
                        'operation' => 'post.content.search',
                        'params' => ['post_id' => '<id>', 'query' => '<literal>'],
                    ],
                    'post_content_read_range' => [
                        'operation' => 'post.content.read_range',
                        'params' => ['post_id' => '<id>', 'start_line' => 1, 'max_lines' => 200],
                    ],
                    'small_media_upload' => [
                        'operation' => 'media.upload.inline',
                        'params' => ['filename' => '<name>', 'mime_type' => '<mime>', 'data_b64' => '<base64>'],
                    ],
                    'workspace_search' => [
                        'operation' => 'workspace.file.search',
                        'params' => ['path' => '<workspace path>', 'query' => '<literal>'],
                    ],
                    'readonly_batch' => [
                        'operation' => 'readonly.batch',
                        'params' => ['operations' => '<read-only operations array>'],
                    ],
                    'self_update_status' => [
                        'operation' => 'self_update.status',
                        'params' => new stdClass(),
                    ],
                ],
            ],
            'task_recipes' => [
                'targeted_post_edit' => [
                    'steps' => ['post.content.search or post.content.read_range', 'post.content.patch_preview', 'post.content.patch_apply'],
                    'rule' => 'Do not fetch and rewrite the full post when an exact guarded patch is sufficient.',
                ],
                'featured_image_small_file' => [
                    'steps' => ['media.upload.inline', 'post.update with fields.featured_media'],
                    'rule' => 'For decoded files up to 1 MiB, do not stage Git media chunks unless inline upload actually fails.',
                ],
                'featured_image_large_file' => [
                    'steps' => ['stage binary-first chunks', 'POST staged media Fast Path once', 'post.update with fields.featured_media'],
                    'rule' => 'Do not run verify-only before a normal staged upload; use verify-only for explicit verification or integrity diagnosis.',
                ],
                'large_iterative_artifact' => [
                    'steps' => ['workspace.file.search/read_range', 'workspace.file.patch or guarded write', 'workspace snapshot when rollback point is needed'],
                    'rule' => 'Do not rediscover the same artifact from File Library or historical chat on each continuation.',
                ],
                'multiple_independent_reads' => [
                    'steps' => ['readonly.batch'],
                    'rule' => 'Use one batch for two or more independent allowlisted reads instead of serial pending commands.',
                ],
            ],
            'failure_policy' => [
                'rest_no_route' => 'Do not repeat the same route. Check the deterministic operation catalog and query/route separation first.',
                'stale_write_or_plan_409' => 'Re-read only the affected state, regenerate the preview/plan, then retry the guarded write once with fresh hashes.',
                'media_integrity_409' => 'Inspect reported chunk/file integrity and replace only mismatched staging data. Do not switch transport blindly.',
                'runtime_branch_race_409_422' => 'Check for the matching result first. If absent, retry only the Git publication step against the latest runtime head; do not replay the WordPress side effect.',
                'unknown_operation' => 'Read this catalog. Do not enumerate unrelated connectors or fall back to WPVibe.',
            ],
            'media_routing' => [
                'client_preparer' => [
                    'path' => 'wordpress-bridge/prepare-media.py',
                    'runtime' => 'Python 3 standard library in an available client execution tool',
                    'network' => false,
                    'preserves_original_bytes' => true,
                    'returns' => ['manifest', 'command', 'tree'],
                    'publication' => 'Pass generated strings directly to existing GitHub tools without model transcription; the preparer does not upload.',
                    'asset_unavailable' => 'Original image bytes could not be read; this does not establish missing GitHub tools or a Bridge upload failure.',
                ],
                'inline_action' => 'media.upload_base64',
                'inline_preferred_max_decoded_bytes' => 1048576,
                'staged_upload_route' => '/wp-agent-bridge-runtime/v1/media-upload',
                'staged_preferred_above_decoded_bytes' => 1048576,
                'verify_only_on_failure_or_explicit_request' => true,
                'rule' => 'For a local or conversation-uploaded media file up to 1 MiB decoded, prefer one inline media.upload_base64 pending command. For larger files, prefer staged media Fast Path with binary-first chunking and integrity metadata.',
            ],
            'connector_policy' => [
                'ordinary_command_write' => ['create_file'],
                'preferred_atomic_git_data' => ['create_blob', 'create_tree', 'create_commit', 'update_ref'],
                'discovery_rule' => 'Do not rediscover connector capabilities when the required write action is already visible in the current task/session.',
                'question_rule' => 'Do not ask the user to identify or confirm repository/files/routes that the connected tools can inspect directly.',
            ],
            'fast_path' => [
                'runtime_resolution' => 'Read RUNTIME_CONNECTION.json only when canonical runtime is not already verified or a migration signal appears.',
                'capability_resolution' => 'Read this file before issuing capability probe commands or searching source code for known Bridge routes/actions.',
                'operation_router' => 'For common tasks, use the single /takka-v099/v1/operate route and an allowlisted operation from routes.operations. This is preferred over reconstructing versioned internal routes.',
                'command_chaining' => 'When atomic_command_bookkeeping=true, a visible matching result means result creation, completed-command storage, and pending deletion are already durable in the same commit. The next pending command may be submitted immediately without waiting for later bookkeeping commits.',
                'readonly_batch' => 'When two or more independent allowlisted reads are needed, prefer one readonly.batch request instead of multiple pending commands/webhooks/results.',
                'post_content_read' => 'For WordPress post content, use post.content.inspect for metadata, post.content.search for targeted lookup, and post.content.read_range for bounded source reads through the deterministic operation router.',
                'media_upload' => 'For local or conversation-uploaded media up to 1 MiB decoded, prefer media.upload.inline through the deterministic operation router. Use staged Media Fast Path for larger files. Do not split or stage a small file unless inline upload actually fails or the caller explicitly requests staged transport.',
                'workspace' => 'Prefer workspace operations for iterative large text artifacts instead of repeated File Library or historical-response reconstruction.',
            ],
        ];
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
