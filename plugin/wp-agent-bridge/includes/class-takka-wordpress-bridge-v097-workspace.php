<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.7 private development workspace backed by the user's canonical runtime repository.
 *
 * The workspace is intentionally limited to bounded text artifacts under
 * wordpress-bridge/workspace/. It provides guarded reads/writes, exact patching,
 * literal search, snapshots, rollback, and compact line-diff summaries without
 * exposing arbitrary filesystem or shell access.
 */
final class TakKa_WordPress_Bridge_V097_Workspace
{
    private const VERSION = '0.9.7';
    private const NS = 'takka-v097/v1';
    private const ROUTE = '/takka-v097/v1/manage';
    private const OUTER = '/takka-bridge/v1/execute';
    private const HEALTH = '/takka-bridge/v1/health';
    private const SECRET = 'takka_bridge_secret';
    private const USER = 'takka_bridge_user_id';
    private const SKEW = 300;
    private const ROOT = 'wordpress-bridge/workspace/';
    private const SNAPSHOT_ROOT = 'wordpress-bridge/workspace/.snapshots/';
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_FULL_READ_BYTES = 524288;
    private const MAX_RANGE_LINES = 1000;
    private const MAX_SEARCH_FILES = 100;
    private const MAX_SEARCH_BYTES = 5242880;
    private const MAX_SEARCH_RESULTS = 100;
    private const MAX_DIFF_LINES = 300;

    private static $allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v097.capabilities',
        'workspace.list',
        'workspace.file.get',
        'workspace.file.read.range',
        'workspace.file.search',
        'workspace.file.write',
        'workspace.file.patch',
        'workspace.file.delete',
        'workspace.file.diff',
        'workspace.snapshot.create',
        'workspace.snapshot.list',
        'workspace.snapshot.rollback',
    ];

    private const EXTENSIONS = [
        'c', 'cc', 'cpp', 'css', 'csv', 'h', 'hpp', 'htm', 'html', 'inc',
        'ini', 'js', 'json', 'jsx', 'md', 'mjs', 'pov', 'py', 'svg', 'ts',
        'tsx', 'txt', 'xml', 'yaml', 'yml',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 68, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'health'], 406, 3);
    }

    public static function register(): void
    {
        register_rest_route(self::NS, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function prepare($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::OUTER
            || strtoupper($request->get_method()) !== 'POST'
            || !self::valid_hmac($request)) {
            return $result;
        }
        $inner = self::inner($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') {
            return $result;
        }
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        if (strtoupper((string) ($params['method'] ?? 'GET')) === 'POST'
            && (string) ($params['route'] ?? '') === self::ROUTE) {
            self::$allowed = true;
            self::$request_id = isset($inner['request_id']) && is_string($inner['request_id'])
                ? trim($inner['request_id'])
                : '';
        }
        return $result;
    }

    public static function permission()
    {
        if (!self::$allowed || !current_user_can('manage_options')) {
            return new WP_Error(
                'takka_bridge_v097_internal_only',
                'This route is only callable through the signed Bridge REST proxy.',
                ['status' => 403]
            );
        }
        self::$allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('takka_bridge_v097_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v097_action', 'Unknown or blocked v0.9.7 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v097.capabilities':
                    $response = rest_ensure_response(self::capabilities());
                    break;
                case 'workspace.list':
                    $response = self::workspace_list($params);
                    break;
                case 'workspace.file.get':
                    $response = self::file_get($params);
                    break;
                case 'workspace.file.read.range':
                    $response = self::file_read_range($params);
                    break;
                case 'workspace.file.search':
                    $response = self::file_search($params);
                    break;
                case 'workspace.file.write':
                    $response = self::file_write($params);
                    break;
                case 'workspace.file.patch':
                    $response = self::file_patch($params);
                    break;
                case 'workspace.file.delete':
                    $response = self::file_delete($params);
                    break;
                case 'workspace.file.diff':
                    $response = self::file_diff($params);
                    break;
                case 'workspace.snapshot.create':
                    $response = self::snapshot_create($params);
                    break;
                case 'workspace.snapshot.list':
                    $response = self::snapshot_list($params);
                    break;
                case 'workspace.snapshot.rollback':
                    $response = self::snapshot_rollback($params);
                    break;
                default:
                    $response = new WP_Error('takka_bridge_v097_dispatch', 'Dispatch fell through.', ['status' => 500]);
            }
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v097_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }

        if (in_array($action, [
            'workspace.file.write',
            'workspace.file.patch',
            'workspace.file.delete',
            'workspace.snapshot.create',
            'workspace.snapshot.rollback',
        ], true) && class_exists('TakKa_WordPress_Bridge_V07_Audit')) {
            TakKa_WordPress_Bridge_V07_Audit::record(
                self::$request_id,
                $action,
                self::audit_params($params),
                $response
            );
        }
        return $response;
    }

    public static function health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'private_runtime_workspace',
            'workspace_guarded_text_write',
            'workspace_exact_patch',
            'workspace_snapshot_rollback',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $data['workspace'] = [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'root' => self::ROOT,
            'actions' => self::ACTIONS,
        ];
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::ROUTE,
            'storage' => [
                'kind' => 'canonical_private_runtime_repository',
                'root' => self::ROOT,
                'snapshot_root' => self::SNAPSHOT_ROOT,
            ],
            'actions' => self::ACTIONS,
            'limits' => [
                'max_file_bytes' => self::MAX_FILE_BYTES,
                'max_full_read_bytes' => self::MAX_FULL_READ_BYTES,
                'max_range_lines' => self::MAX_RANGE_LINES,
                'max_search_files' => self::MAX_SEARCH_FILES,
                'max_search_bytes' => self::MAX_SEARCH_BYTES,
                'max_search_results' => self::MAX_SEARCH_RESULTS,
                'allowed_extensions' => self::EXTENSIONS,
            ],
            'write_guards' => [
                'confirm_required' => true,
                'existing_file_requires_expected_current_sha256' => true,
                'new_file_requires_expected_current_absent' => true,
            ],
        ];
    }

    private static function workspace_list(array $params)
    {
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $prefix = isset($params['path_prefix']) ? self::normalize_prefix((string) $params['path_prefix']) : '';
        if (is_wp_error($prefix)) {
            return $prefix;
        }
        $tree = self::runtime_tree($ctx);
        if (is_wp_error($tree)) {
            return $tree;
        }
        $files = [];
        foreach ($tree['entries'] as $entry) {
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = (string) ($entry['path'] ?? '');
            if (strpos($path, self::ROOT) !== 0 || strpos($path, self::SNAPSHOT_ROOT) === 0) {
                continue;
            }
            $relative = substr($path, strlen(self::ROOT));
            if ($relative === '' || $relative === '.gitkeep' || ($prefix !== '' && strpos($relative, $prefix) !== 0)) {
                continue;
            }
            $files[] = [
                'path' => $relative,
                'bytes' => isset($entry['size']) ? (int) $entry['size'] : null,
                'blob_sha' => isset($entry['sha']) ? (string) $entry['sha'] : null,
            ];
        }
        usort($files, static function ($a, $b) {
            return strcmp((string) $a['path'], (string) $b['path']);
        });
        return rest_ensure_response([
            'ok' => true,
            'root' => self::ROOT,
            'head' => $tree['head'],
            'count' => count($files),
            'files' => $files,
        ]);
    }

    private static function file_get(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $file = self::read_file($ctx, $path);
        if (is_wp_error($file)) {
            return $file;
        }
        if ($file['bytes'] > self::MAX_FULL_READ_BYTES && empty($params['allow_large_full_read'])) {
            return new WP_Error('takka_bridge_workspace_read_too_large', 'File exceeds the normal full-read limit. Use workspace.file.read.range.', [
                'status' => 413,
                'path' => $path,
                'bytes' => $file['bytes'],
                'max_full_read_bytes' => self::MAX_FULL_READ_BYTES,
                'sha256' => $file['sha256'],
                'line_count' => self::line_count($file['content']),
            ]);
        }
        return rest_ensure_response([
            'ok' => true,
            'path' => $path,
            'bytes' => $file['bytes'],
            'sha256' => $file['sha256'],
            'blob_sha' => $file['blob_sha'],
            'line_count' => self::line_count($file['content']),
            'content' => $file['content'],
        ]);
    }

    private static function file_read_range(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        $start = isset($params['start_line']) ? (int) $params['start_line'] : 1;
        $end = isset($params['end_line']) ? (int) $params['end_line'] : $start + 199;
        if ($start < 1 || $end < $start || ($end - $start + 1) > self::MAX_RANGE_LINES) {
            return new WP_Error('takka_bridge_workspace_range', 'Invalid or oversized line range.', [
                'status' => 400,
                'max_range_lines' => self::MAX_RANGE_LINES,
            ]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $file = self::read_file($ctx, $path);
        if (is_wp_error($file)) {
            return $file;
        }
        $lines = self::split_lines($file['content']);
        $slice = array_slice($lines, $start - 1, $end - $start + 1);
        return rest_ensure_response([
            'ok' => true,
            'path' => $path,
            'sha256' => $file['sha256'],
            'blob_sha' => $file['blob_sha'],
            'line_count' => count($lines),
            'start_line' => $start,
            'end_line' => min($end, count($lines)),
            'content' => implode("\n", $slice),
        ]);
    }

    private static function file_search(array $params)
    {
        $query = isset($params['query']) && is_string($params['query']) ? $params['query'] : '';
        if ($query === '' || strlen($query) > 4096) {
            return new WP_Error('takka_bridge_workspace_search_query', 'A literal query up to 4096 bytes is required.', ['status' => 400]);
        }
        $case_sensitive = !empty($params['case_sensitive']);
        $prefix = isset($params['path_prefix']) ? self::normalize_prefix((string) $params['path_prefix']) : '';
        if (is_wp_error($prefix)) {
            return $prefix;
        }
        $limit = isset($params['max_results']) ? (int) $params['max_results'] : 30;
        $limit = max(1, min(self::MAX_SEARCH_RESULTS, $limit));
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $tree = self::runtime_tree($ctx);
        if (is_wp_error($tree)) {
            return $tree;
        }

        $matches = [];
        $files_scanned = 0;
        $bytes_scanned = 0;
        $truncated = false;
        foreach ($tree['entries'] as $entry) {
            if (count($matches) >= $limit) {
                break;
            }
            if (($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $full = (string) ($entry['path'] ?? '');
            if (strpos($full, self::ROOT) !== 0 || strpos($full, self::SNAPSHOT_ROOT) === 0) {
                continue;
            }
            $relative = substr($full, strlen(self::ROOT));
            if ($relative === '' || $relative === '.gitkeep' || ($prefix !== '' && strpos($relative, $prefix) !== 0)) {
                continue;
            }
            $valid = self::normalize_relative_path($relative);
            if (is_wp_error($valid)) {
                continue;
            }
            $size = isset($entry['size']) ? (int) $entry['size'] : 0;
            if ($size > self::MAX_FILE_BYTES) {
                continue;
            }
            if ($files_scanned >= self::MAX_SEARCH_FILES || ($bytes_scanned + max(0, $size)) > self::MAX_SEARCH_BYTES) {
                $truncated = true;
                break;
            }
            $blob_sha = (string) ($entry['sha'] ?? '');
            $content = self::read_blob($ctx, $blob_sha);
            if (is_wp_error($content)) {
                continue;
            }
            $files_scanned++;
            $bytes_scanned += strlen($content);
            foreach (self::split_lines($content) as $index => $line) {
                $found = $case_sensitive ? strpos($line, $query) : stripos($line, $query);
                if ($found === false) {
                    continue;
                }
                $matches[] = [
                    'path' => $relative,
                    'line' => $index + 1,
                    'column' => $found + 1,
                    'snippet' => self::bounded_snippet($line, 400),
                ];
                if (count($matches) >= $limit) {
                    break 2;
                }
            }
        }

        return rest_ensure_response([
            'ok' => true,
            'query' => $query,
            'case_sensitive' => $case_sensitive,
            'files_scanned' => $files_scanned,
            'bytes_scanned' => $bytes_scanned,
            'truncated' => $truncated,
            'matches' => $matches,
        ]);
    }

    private static function file_write(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_workspace_confirmation_required', 'workspace.file.write requires confirm=true.', ['status' => 400]);
        }
        if (!isset($params['content']) || !is_string($params['content'])) {
            return new WP_Error('takka_bridge_workspace_content', 'content must be a UTF-8 text string.', ['status' => 400]);
        }
        $content = $params['content'];
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return new WP_Error('takka_bridge_workspace_file_too_large', 'Workspace file exceeds the maximum size.', [
                'status' => 413,
                'max_file_bytes' => self::MAX_FILE_BYTES,
            ]);
        }
        if (!self::valid_utf8($content)) {
            return new WP_Error('takka_bridge_workspace_utf8', 'Workspace text must be valid UTF-8.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        return self::guarded_write($ctx, $path, $content, $params, 'WP Agent Bridge: workspace write ' . $path);
    }

    private static function file_patch(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_workspace_confirmation_required', 'workspace.file.patch requires confirm=true.', ['status' => 400]);
        }
        $search = isset($params['search']) && is_string($params['search']) ? $params['search'] : '';
        $replace = isset($params['replace']) && is_string($params['replace']) ? $params['replace'] : '';
        if ($search === '') {
            return new WP_Error('takka_bridge_workspace_patch_search', 'search must be a non-empty exact text fragment.', ['status' => 400]);
        }
        $expected = isset($params['expected_current_sha256']) ? strtolower(trim((string) $params['expected_current_sha256'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new WP_Error('takka_bridge_workspace_expected_sha_required', 'workspace.file.patch requires expected_current_sha256.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $file = self::read_file($ctx, $path);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!hash_equals($expected, $file['sha256'])) {
            return new WP_Error('takka_bridge_workspace_stale', 'Workspace file changed after it was inspected.', [
                'status' => 409,
                'expected_current_sha256' => $expected,
                'current_sha256' => $file['sha256'],
            ]);
        }
        $count = substr_count($file['content'], $search);
        if ($count < 1) {
            return new WP_Error('takka_bridge_workspace_patch_not_found', 'Exact search text was not found.', ['status' => 409]);
        }
        $replace_all = !empty($params['replace_all']);
        $occurrence = isset($params['occurrence']) ? (int) $params['occurrence'] : 0;
        if ($replace_all) {
            $new_content = str_replace($search, $replace, $file['content']);
            $replaced = $count;
        } elseif ($occurrence > 0) {
            if ($occurrence > $count) {
                return new WP_Error('takka_bridge_workspace_patch_occurrence', 'Requested occurrence exceeds the number of exact matches.', [
                    'status' => 409,
                    'occurrence' => $occurrence,
                    'match_count' => $count,
                ]);
            }
            $new_content = self::replace_nth($file['content'], $search, $replace, $occurrence);
            $replaced = 1;
        } else {
            if ($count !== 1) {
                return new WP_Error('takka_bridge_workspace_patch_ambiguous', 'Exact search text matched more than once; specify occurrence or replace_all.', [
                    'status' => 409,
                    'match_count' => $count,
                ]);
            }
            $new_content = str_replace($search, $replace, $file['content']);
            $replaced = 1;
        }
        if (strlen($new_content) > self::MAX_FILE_BYTES || !self::valid_utf8($new_content)) {
            return new WP_Error('takka_bridge_workspace_patch_result', 'Patched content is invalid or exceeds the file-size limit.', ['status' => 413]);
        }
        $response = self::guarded_write($ctx, $path, $new_content, [
            'confirm' => true,
            'expected_current_sha256' => $expected,
        ], 'WP Agent Bridge: workspace patch ' . $path);
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (is_array($data)) {
            $data['patch'] = [
                'match_count_before' => $count,
                'replaced' => $replaced,
                'replace_all' => $replace_all,
                'occurrence' => $occurrence > 0 ? $occurrence : null,
            ];
            $rest->set_data($data);
        }
        return $rest;
    }

    private static function file_delete(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_workspace_confirmation_required', 'workspace.file.delete requires confirm=true.', ['status' => 400]);
        }
        $expected = isset($params['expected_current_sha256']) ? strtolower(trim((string) $params['expected_current_sha256'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new WP_Error('takka_bridge_workspace_expected_sha_required', 'workspace.file.delete requires expected_current_sha256.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $file = self::read_file($ctx, $path);
        if (is_wp_error($file)) {
            return $file;
        }
        if (!hash_equals($expected, $file['sha256'])) {
            return new WP_Error('takka_bridge_workspace_stale', 'Workspace file changed after it was inspected.', [
                'status' => 409,
                'expected_current_sha256' => $expected,
                'current_sha256' => $file['sha256'],
            ]);
        }
        $deleted = TakKa_WordPress_Bridge_Direct_GitHub::delete_file(
            $ctx['token'], $ctx['repository'], $ctx['branch'], self::ROOT . $path,
            $file['blob_sha'], 'WP Agent Bridge: workspace delete ' . $path
        );
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        return rest_ensure_response([
            'ok' => true,
            'deleted' => true,
            'path' => $path,
            'previous' => self::file_meta($file),
        ]);
    }

    private static function snapshot_create(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_workspace_confirmation_required', 'workspace.snapshot.create requires confirm=true.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $file = self::read_file($ctx, $path);
        if (is_wp_error($file)) {
            return $file;
        }
        if (isset($params['expected_current_sha256'])) {
            $expected = strtolower(trim((string) $params['expected_current_sha256']));
            if (!preg_match('/^[a-f0-9]{64}$/', $expected) || !hash_equals($expected, $file['sha256'])) {
                return new WP_Error('takka_bridge_workspace_stale', 'Workspace file changed after it was inspected.', [
                    'status' => 409,
                    'current_sha256' => $file['sha256'],
                ]);
            }
        }
        $snapshot_id = gmdate('Ymd\THis\Z') . '-' . substr(hash('sha256', $path . '|' . self::$request_id . '|' . microtime(true)), 0, 12);
        $manifest = [
            'schema' => 1,
            'snapshot_id' => $snapshot_id,
            'created_at' => gmdate('c'),
            'request_id' => self::$request_id,
            'path' => $path,
            'blob_sha' => $file['blob_sha'],
            'sha256' => $file['sha256'],
            'bytes' => $file['bytes'],
        ];
        $json = wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            return new WP_Error('takka_bridge_workspace_snapshot_json', 'Could not encode workspace snapshot.', ['status' => 500]);
        }
        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $ctx['token'], $ctx['repository'], $ctx['branch'],
            self::SNAPSHOT_ROOT . $snapshot_id . '.json', $json . "\n",
            'WP Agent Bridge: workspace snapshot ' . $path
        );
        if (is_wp_error($written)) {
            return $written;
        }
        $manifest['ok'] = true;
        return rest_ensure_response($manifest);
    }

    private static function snapshot_list(array $params)
    {
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $tree = self::runtime_tree($ctx);
        if (is_wp_error($tree)) {
            return $tree;
        }
        $items = [];
        foreach ($tree['entries'] as $entry) {
            $path = (string) ($entry['path'] ?? '');
            if (($entry['type'] ?? '') !== 'blob' || strpos($path, self::SNAPSHOT_ROOT) !== 0 || substr($path, -5) !== '.json') {
                continue;
            }
            $id = basename($path, '.json');
            if (!self::valid_snapshot_id($id)) {
                continue;
            }
            $items[] = [
                'snapshot_id' => $id,
                'blob_sha' => isset($entry['sha']) ? (string) $entry['sha'] : null,
                'bytes' => isset($entry['size']) ? (int) $entry['size'] : null,
            ];
        }
        usort($items, static function ($a, $b) {
            return strcmp((string) $b['snapshot_id'], (string) $a['snapshot_id']);
        });
        $limit = isset($params['limit']) ? (int) $params['limit'] : 50;
        $limit = max(1, min(200, $limit));
        return rest_ensure_response([
            'ok' => true,
            'count' => min(count($items), $limit),
            'total' => count($items),
            'snapshots' => array_slice($items, 0, $limit),
        ]);
    }

    private static function file_diff(array $params)
    {
        $path = self::required_path($params);
        if (is_wp_error($path)) {
            return $path;
        }
        $snapshot_id = isset($params['snapshot_id']) && is_string($params['snapshot_id']) ? trim($params['snapshot_id']) : '';
        $blob_sha = isset($params['blob_sha']) && is_string($params['blob_sha']) ? strtolower(trim($params['blob_sha'])) : '';
        if (($snapshot_id === '' && $blob_sha === '') || ($snapshot_id !== '' && $blob_sha !== '')) {
            return new WP_Error('takka_bridge_workspace_diff_source', 'Provide exactly one of snapshot_id or blob_sha.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $current = self::read_file($ctx, $path);
        if (is_wp_error($current)) {
            return $current;
        }
        if ($snapshot_id !== '') {
            $snapshot = self::load_snapshot($ctx, $snapshot_id);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }
            if ($snapshot['path'] !== $path) {
                return new WP_Error('takka_bridge_workspace_snapshot_path', 'Snapshot belongs to a different workspace path.', [
                    'status' => 409,
                    'snapshot_path' => $snapshot['path'],
                    'requested_path' => $path,
                ]);
            }
            $blob_sha = $snapshot['blob_sha'];
        } elseif (!preg_match('/^[a-f0-9]{40,64}$/', $blob_sha)) {
            return new WP_Error('takka_bridge_workspace_blob_sha', 'Invalid blob_sha.', ['status' => 400]);
        }
        $before = self::read_blob($ctx, $blob_sha);
        if (is_wp_error($before)) {
            return $before;
        }
        $diff = self::diff_lines($before, $current['content']);
        $diff['ok'] = true;
        $diff['path'] = $path;
        $diff['before_blob_sha'] = $blob_sha;
        $diff['before_sha256'] = hash('sha256', $before);
        $diff['current_blob_sha'] = $current['blob_sha'];
        $diff['current_sha256'] = $current['sha256'];
        return rest_ensure_response($diff);
    }

    private static function snapshot_rollback(array $params)
    {
        $snapshot_id = isset($params['snapshot_id']) && is_string($params['snapshot_id']) ? trim($params['snapshot_id']) : '';
        if (!self::valid_snapshot_id($snapshot_id)) {
            return new WP_Error('takka_bridge_workspace_snapshot_id', 'A valid snapshot_id is required.', ['status' => 400]);
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_workspace_confirmation_required', 'workspace.snapshot.rollback requires confirm=true.', ['status' => 400]);
        }
        $expected = isset($params['expected_current_sha256']) ? strtolower(trim((string) $params['expected_current_sha256'])) : '';
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            return new WP_Error('takka_bridge_workspace_expected_sha_required', 'workspace.snapshot.rollback requires expected_current_sha256.', ['status' => 400]);
        }
        $ctx = self::context();
        if (is_wp_error($ctx)) {
            return $ctx;
        }
        $snapshot = self::load_snapshot($ctx, $snapshot_id);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $content = self::read_blob($ctx, $snapshot['blob_sha']);
        if (is_wp_error($content)) {
            return $content;
        }
        if (!hash_equals($snapshot['sha256'], hash('sha256', $content))) {
            return new WP_Error('takka_bridge_workspace_snapshot_integrity', 'Snapshot blob content does not match the stored SHA-256.', ['status' => 409]);
        }
        $write = self::guarded_write($ctx, $snapshot['path'], $content, [
            'confirm' => true,
            'expected_current_sha256' => $expected,
        ], 'WP Agent Bridge: workspace rollback ' . $snapshot['path']);
        if (is_wp_error($write)) {
            return $write;
        }
        $rest = rest_ensure_response($write);
        $data = $rest->get_data();
        if (is_array($data)) {
            $data['rollback_snapshot_id'] = $snapshot_id;
            $rest->set_data($data);
        }
        return $rest;
    }

    private static function guarded_write(array $ctx, string $path, string $content, array $params, string $message)
    {
        $current = self::read_file_optional($ctx, $path);
        if (is_wp_error($current)) {
            return $current;
        }
        if ($current === null) {
            if (empty($params['expected_current_absent'])) {
                return new WP_Error('takka_bridge_workspace_absent_guard_required', 'New workspace files require expected_current_absent=true.', [
                    'status' => 409,
                    'path' => $path,
                ]);
            }
        } else {
            $expected = isset($params['expected_current_sha256']) ? strtolower(trim((string) $params['expected_current_sha256'])) : '';
            if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
                return new WP_Error('takka_bridge_workspace_expected_sha_required', 'Existing workspace files require expected_current_sha256.', [
                    'status' => 409,
                    'path' => $path,
                    'current_sha256' => $current['sha256'],
                ]);
            }
            if (!hash_equals($expected, $current['sha256'])) {
                return new WP_Error('takka_bridge_workspace_stale', 'Workspace file changed after it was inspected.', [
                    'status' => 409,
                    'expected_current_sha256' => $expected,
                    'current_sha256' => $current['sha256'],
                ]);
            }
        }
        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $ctx['token'], $ctx['repository'], $ctx['branch'], self::ROOT . $path, $content, $message
        );
        if (is_wp_error($written)) {
            return $written;
        }
        $verified = self::read_file($ctx, $path);
        if (is_wp_error($verified)) {
            return $verified;
        }
        $expected_new = hash('sha256', $content);
        if (!hash_equals($expected_new, $verified['sha256'])) {
            return new WP_Error('takka_bridge_workspace_write_verify', 'Workspace write verification failed.', [
                'status' => 500,
                'expected_sha256' => $expected_new,
                'actual_sha256' => $verified['sha256'],
            ]);
        }
        return rest_ensure_response([
            'ok' => true,
            'changed' => $current === null || !hash_equals($current['sha256'], $verified['sha256']),
            'path' => $path,
            'previous' => $current === null ? null : self::file_meta($current),
            'current' => self::file_meta($verified),
        ]);
    }

    private static function context()
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = trim((string) ($connection['repository'] ?? ''));
        $branch = (string) ($connection['runtime_branch'] ?? '');
        if ($installation_id < 1 || $repository_id < 1
            || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
            || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return new WP_Error('takka_bridge_workspace_connection', 'Canonical Direct Runtime connection is incomplete.', ['status' => 503]);
        }
        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }
        return [
            'installation_id' => $installation_id,
            'repository_id' => $repository_id,
            'repository' => $repository,
            'branch' => $branch,
            'token' => $token,
        ];
    }

    private static function runtime_tree(array $ctx)
    {
        $ref = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET', '/repos/' . $ctx['repository'] . '/git/ref/heads/' . rawurlencode($ctx['branch']), $ctx['token']
        );
        if (is_wp_error($ref)) {
            return $ref;
        }
        $head = strtolower((string) ($ref['data']['object']['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $head)) {
            return new WP_Error('takka_bridge_workspace_head', 'Could not resolve runtime branch head.', ['status' => 502]);
        }
        $commit = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET', '/repos/' . $ctx['repository'] . '/git/commits/' . $head, $ctx['token']
        );
        if (is_wp_error($commit)) {
            return $commit;
        }
        $tree_sha = strtolower((string) ($commit['data']['tree']['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $tree_sha)) {
            return new WP_Error('takka_bridge_workspace_tree', 'Could not resolve runtime Git tree.', ['status' => 502]);
        }
        $tree = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET', '/repos/' . $ctx['repository'] . '/git/trees/' . $tree_sha . '?recursive=1', $ctx['token']
        );
        if (is_wp_error($tree)) {
            return $tree;
        }
        if (!empty($tree['data']['truncated'])) {
            return new WP_Error('takka_bridge_workspace_tree_truncated', 'Runtime Git tree is too large for safe workspace enumeration.', ['status' => 413]);
        }
        return [
            'head' => $head,
            'tree_sha' => $tree_sha,
            'entries' => isset($tree['data']['tree']) && is_array($tree['data']['tree']) ? $tree['data']['tree'] : [],
        ];
    }

    private static function read_file(array $ctx, string $relative)
    {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
            $ctx['token'], $ctx['repository'], $ctx['branch'], self::ROOT . $relative
        );
        if (is_wp_error($meta)) {
            return $meta;
        }
        $blob_sha = strtolower((string) ($meta['sha'] ?? ''));
        if (!preg_match('/^[a-f0-9]{40,64}$/', $blob_sha)) {
            return new WP_Error('takka_bridge_workspace_blob_sha', 'GitHub did not return a valid blob SHA.', ['status' => 502]);
        }
        $content = self::decode_contents_meta($meta);
        if (is_wp_error($content)) {
            $content = self::read_blob($ctx, $blob_sha);
            if (is_wp_error($content)) {
                return $content;
            }
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return new WP_Error('takka_bridge_workspace_file_too_large', 'Workspace file exceeds the maximum supported size.', [
                'status' => 413,
                'path' => $relative,
            ]);
        }
        if (!self::valid_utf8($content)) {
            return new WP_Error('takka_bridge_workspace_utf8', 'Workspace file is not valid UTF-8 text.', [
                'status' => 415,
                'path' => $relative,
            ]);
        }
        return [
            'path' => $relative,
            'content' => $content,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'blob_sha' => $blob_sha,
        ];
    }

    private static function read_file_optional(array $ctx, string $relative)
    {
        $file = self::read_file($ctx, $relative);
        if (!is_wp_error($file)) {
            return $file;
        }
        $data = $file->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 0;
        if ($status === 404 || preg_match('/_http_404$/', (string) $file->get_error_code())) {
            return null;
        }
        return $file;
    }

    private static function read_blob(array $ctx, string $blob_sha)
    {
        if (!preg_match('/^[a-f0-9]{40,64}$/', strtolower($blob_sha))) {
            return new WP_Error('takka_bridge_workspace_blob_sha', 'Invalid Git blob SHA.', ['status' => 400]);
        }
        $response = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET', '/repos/' . $ctx['repository'] . '/git/blobs/' . strtolower($blob_sha), $ctx['token']
        );
        if (is_wp_error($response)) {
            return $response;
        }
        $encoding = (string) ($response['data']['encoding'] ?? '');
        $encoded = isset($response['data']['content']) && is_string($response['data']['content'])
            ? preg_replace('/\s+/', '', $response['data']['content'])
            : '';
        if ($encoding !== 'base64' || $encoded === '') {
            return new WP_Error('takka_bridge_workspace_blob_content', 'Git blob response did not contain Base64 content.', ['status' => 502]);
        }
        $content = base64_decode($encoded, true);
        if (!is_string($content)) {
            return new WP_Error('takka_bridge_workspace_blob_decode', 'Could not decode Git blob content.', ['status' => 502]);
        }
        if (strlen($content) > self::MAX_FILE_BYTES || !self::valid_utf8($content)) {
            return new WP_Error('takka_bridge_workspace_blob_type', 'Git blob is not a supported bounded UTF-8 workspace artifact.', ['status' => 415]);
        }
        return $content;
    }

    private static function load_snapshot(array $ctx, string $snapshot_id)
    {
        if (!self::valid_snapshot_id($snapshot_id)) {
            return new WP_Error('takka_bridge_workspace_snapshot_id', 'Invalid snapshot_id.', ['status' => 400]);
        }
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata(
            $ctx['token'], $ctx['repository'], $ctx['branch'], self::SNAPSHOT_ROOT . $snapshot_id . '.json'
        );
        if (is_wp_error($meta)) {
            return $meta;
        }
        $raw = self::decode_contents_meta($meta);
        if (is_wp_error($raw)) {
            return $raw;
        }
        $snapshot = json_decode($raw, true);
        if (!is_array($snapshot)
            || ($snapshot['schema'] ?? null) !== 1
            || ($snapshot['snapshot_id'] ?? '') !== $snapshot_id
            || !isset($snapshot['path'], $snapshot['blob_sha'], $snapshot['sha256'])) {
            return new WP_Error('takka_bridge_workspace_snapshot_manifest', 'Snapshot manifest is invalid.', ['status' => 409]);
        }
        $path = self::normalize_relative_path((string) $snapshot['path']);
        if (is_wp_error($path)) {
            return $path;
        }
        $blob_sha = strtolower((string) $snapshot['blob_sha']);
        $sha256 = strtolower((string) $snapshot['sha256']);
        if (!preg_match('/^[a-f0-9]{40,64}$/', $blob_sha) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            return new WP_Error('takka_bridge_workspace_snapshot_manifest', 'Snapshot hashes are invalid.', ['status' => 409]);
        }
        return [
            'snapshot_id' => $snapshot_id,
            'path' => $path,
            'blob_sha' => $blob_sha,
            'sha256' => $sha256,
            'bytes' => isset($snapshot['bytes']) ? (int) $snapshot['bytes'] : null,
            'created_at' => isset($snapshot['created_at']) ? (string) $snapshot['created_at'] : null,
        ];
    }

    private static function required_path(array $params)
    {
        $path = isset($params['path']) && is_string($params['path']) ? $params['path'] : '';
        return self::normalize_relative_path($path);
    }

    private static function normalize_prefix(string $prefix)
    {
        $prefix = trim(str_replace('\\', '/', $prefix), '/');
        if ($prefix === '') {
            return '';
        }
        if (strpos($prefix, '..') !== false || strpos($prefix, "\0") !== false || strlen($prefix) > 400) {
            return new WP_Error('takka_bridge_workspace_prefix', 'Invalid workspace path_prefix.', ['status' => 400]);
        }
        foreach (explode('/', $prefix) as $segment) {
            if ($segment === '' || $segment[0] === '.' || !preg_match('/^[A-Za-z0-9._ -]+$/', $segment)) {
                return new WP_Error('takka_bridge_workspace_prefix', 'Invalid workspace path_prefix.', ['status' => 400]);
            }
        }
        return $prefix . '/';
    }

    private static function normalize_relative_path(string $path)
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || strlen($path) > 400 || strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            return new WP_Error('takka_bridge_workspace_path', 'Invalid workspace relative path.', ['status' => 400]);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment[0] === '.' || !preg_match('/^[A-Za-z0-9._ -]+$/', $segment)) {
                return new WP_Error('takka_bridge_workspace_path', 'Invalid workspace relative path.', ['status' => 400]);
            }
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::EXTENSIONS, true)) {
            return new WP_Error('takka_bridge_workspace_extension', 'Workspace file extension is not allowlisted.', [
                'status' => 415,
                'allowed_extensions' => self::EXTENSIONS,
            ]);
        }
        return $path;
    }

    private static function decode_contents_meta(array $meta)
    {
        $encoding = (string) ($meta['encoding'] ?? '');
        $encoded = isset($meta['content']) && is_string($meta['content'])
            ? preg_replace('/\s+/', '', $meta['content'])
            : '';
        if ($encoding !== 'base64' || $encoded === '') {
            return new WP_Error('takka_bridge_workspace_content', 'GitHub contents response did not contain Base64 file content.', ['status' => 502]);
        }
        $decoded = base64_decode($encoded, true);
        if (!is_string($decoded)) {
            return new WP_Error('takka_bridge_workspace_content_decode', 'Could not decode workspace file content.', ['status' => 502]);
        }
        return $decoded;
    }

    private static function file_meta(array $file): array
    {
        return [
            'path' => $file['path'],
            'bytes' => $file['bytes'],
            'sha256' => $file['sha256'],
            'blob_sha' => $file['blob_sha'],
            'line_count' => self::line_count($file['content']),
        ];
    }

    private static function split_lines(string $content): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $content));
    }

    private static function line_count(string $content): int
    {
        return count(self::split_lines($content));
    }

    private static function replace_nth(string $content, string $search, string $replace, int $occurrence): string
    {
        $offset = 0;
        for ($i = 1; $i <= $occurrence; $i++) {
            $position = strpos($content, $search, $offset);
            if ($position === false) {
                return $content;
            }
            if ($i === $occurrence) {
                return substr($content, 0, $position) . $replace . substr($content, $position + strlen($search));
            }
            $offset = $position + strlen($search);
        }
        return $content;
    }

    private static function diff_lines(string $before, string $after): array
    {
        $a = self::split_lines($before);
        $b = self::split_lines($after);
        $prefix = 0;
        $min = min(count($a), count($b));
        while ($prefix < $min && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while ($suffix < (count($a) - $prefix)
            && $suffix < (count($b) - $prefix)
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]) {
            $suffix++;
        }
        $removed = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $added = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        return [
            'equal' => $before === $after,
            'common_prefix_lines' => $prefix,
            'common_suffix_lines' => $suffix,
            'before_start_line' => $prefix + 1,
            'after_start_line' => $prefix + 1,
            'removed_line_count' => count($removed),
            'added_line_count' => count($added),
            'removed_lines' => array_slice($removed, 0, self::MAX_DIFF_LINES),
            'added_lines' => array_slice($added, 0, self::MAX_DIFF_LINES),
            'removed_truncated' => count($removed) > self::MAX_DIFF_LINES,
            'added_truncated' => count($added) > self::MAX_DIFF_LINES,
        ];
    }

    private static function bounded_snippet(string $line, int $max): string
    {
        return strlen($line) <= $max ? $line : substr($line, 0, $max) . '…';
    }

    private static function valid_utf8(string $value): bool
    {
        return preg_match('//u', $value) === 1;
    }

    private static function valid_snapshot_id(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._:-]{1,120}$/', $id);
    }

    private static function audit_params(array $params): array
    {
        $out = [];
        foreach (['path', 'expected_current_sha256', 'expected_current_absent', 'confirm', 'snapshot_id', 'occurrence', 'replace_all'] as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
        }
        if (isset($params['content']) && is_string($params['content'])) {
            $out['content_bytes'] = strlen($params['content']);
            $out['content_sha256'] = hash('sha256', $params['content']);
        }
        if (isset($params['search']) && is_string($params['search'])) {
            $out['search_bytes'] = strlen($params['search']);
            $out['search_sha256'] = hash('sha256', $params['search']);
        }
        if (isset($params['replace']) && is_string($params['replace'])) {
            $out['replace_bytes'] = strlen($params['replace']);
            $out['replace_sha256'] = hash('sha256', $params['replace']);
        }
        return $out;
    }

    private static function valid_hmac(WP_REST_Request $request): bool
    {
        $secret = (string) get_option(self::SECRET, '');
        $user_id = (int) get_option(self::USER, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) {
            return false;
        }
        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > self::SKEW) {
            return false;
        }
        $payload = $timestamp . "\nPOST\n" . self::OUTER . "\n" . hash('sha256', (string) $request->get_body());
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function inner(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer)
            || ($outer['action'] ?? '') !== 'envelope'
            || !isset($outer['params']['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }
}
