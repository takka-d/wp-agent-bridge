<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.6 bounded multi-file inspection surface.
 *
 * Complements theme.file.outline/read.range with the list/search/batch-read
 * operations that otherwise tempt agents to fall back to another WordPress
 * connector or to issue many small GitHub/Webhook round trips.
 */
final class TakKa_WordPress_Bridge_V096_Theme_Files
{
    private const VERSION = '0.9.6';
    private const NS = 'takka-v096/v1';
    private const ROUTE = '/takka-v096/v1/theme-files';
    private const OUTER = '/takka-bridge/v1/execute';
    private const HEALTH = '/takka-bridge/v1/health';
    private const SECRET = 'takka_bridge_secret';
    private const USER = 'takka_bridge_user_id';
    private const SKEW = 300;
    private const MAX_FILES = 2000;
    private const MAX_LIST_RETURN = 1000;
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_SCAN_BYTES = 20971520;
    private const MAX_RESULTS = 200;
    private const MAX_READ_ITEMS = 20;
    private const MAX_READ_CHARS = 800000;
    private const DEFAULT_EXTENSIONS = ['php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'svg'];

    private static $allowed = false;

    private const ACTIONS = [
        'v096.theme_files.capabilities',
        'theme.files.list',
        'theme.files.search',
        'theme.file.read.many',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 69, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'health'], 407, 3);
    }

    public static function register(): void
    {
        register_rest_route(self::NS, '/theme-files', [
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
        }
        return $result;
    }

    public static function permission()
    {
        if (!self::$allowed || !current_user_can('manage_options')) {
            return new WP_Error(
                'takka_bridge_v096_theme_files_internal_only',
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
            return new WP_Error('takka_bridge_v096_theme_files_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v096_theme_files_action', 'Unknown or blocked v0.9.6 theme-files action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }
        try {
            switch ($action) {
                case 'v096.theme_files.capabilities':
                    return rest_ensure_response(self::capabilities());
                case 'theme.files.list':
                    return self::list_files($params);
                case 'theme.files.search':
                    return self::search_files($params);
                case 'theme.file.read.many':
                    return self::read_many($params);
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v096_theme_files_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }
        return new WP_Error('takka_bridge_v096_theme_files_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    private static function list_files(array $params)
    {
        $root = self::resolve_root($params);
        if (is_wp_error($root)) {
            return $root;
        }
        $pattern = is_string($params['pattern'] ?? null) ? trim($params['pattern']) : '';
        if (strlen($pattern) > 1000 || strpos($pattern, "\0") !== false || preg_match('~(^|/)\.\.(/|$)~', $pattern)) {
            return new WP_Error('takka_bridge_v096_theme_files_pattern', 'Invalid file pattern.', ['status' => 400]);
        }
        $limit = isset($params['limit']) ? max(1, min(self::MAX_LIST_RETURN, (int) $params['limit'])) : 500;
        $include_sha = !empty($params['include_sha256']);
        $files = self::walk($root['root']);
        if (is_wp_error($files)) {
            return $files;
        }
        $out = [];
        $matching = 0;
        foreach ($files as $entry) {
            if ($pattern !== '' && !self::glob_match($pattern, $entry['path'])) {
                continue;
            }
            $matching++;
            if (count($out) >= $limit) {
                continue;
            }
            $row = [
                'path' => $entry['path'],
                'bytes' => $entry['bytes'],
                'extension' => $entry['extension'],
            ];
            if ($include_sha && $entry['bytes'] <= self::MAX_FILE_BYTES) {
                $row['sha256'] = hash_file('sha256', $entry['absolute']);
            }
            $out[] = $row;
        }
        return rest_ensure_response([
            'ok' => true,
            'scope' => $root['scope'],
            'draft_id' => $root['draft_id'],
            'pattern' => $pattern !== '' ? $pattern : null,
            'files' => $out,
            'returned' => count($out),
            'total_matching' => $matching,
            'truncated' => $matching > count($out),
        ]);
    }

    private static function search_files(array $params)
    {
        $root = self::resolve_root($params);
        if (is_wp_error($root)) {
            return $root;
        }
        $query = is_string($params['query'] ?? null) ? $params['query'] : '';
        if ($query === '' || strlen($query) > 4096) {
            return new WP_Error('takka_bridge_v096_theme_files_query', 'query must be a non-empty string within 4096 bytes.', ['status' => 400]);
        }
        $case_sensitive = !empty($params['case_sensitive']);
        $max_results = isset($params['max_results']) ? max(1, min(self::MAX_RESULTS, (int) $params['max_results'])) : 100;
        $context_lines = isset($params['context_lines']) ? max(0, min(5, (int) $params['context_lines'])) : 1;
        $extensions = self::extensions($params);
        if (is_wp_error($extensions)) {
            return $extensions;
        }
        $files = self::walk($root['root']);
        if (is_wp_error($files)) {
            return $files;
        }

        $results = [];
        $scanned_files = 0;
        $scanned_bytes = 0;
        $skipped_large = 0;
        foreach ($files as $entry) {
            if (!in_array($entry['extension'], $extensions, true)) {
                continue;
            }
            if ($entry['bytes'] > self::MAX_FILE_BYTES) {
                $skipped_large++;
                continue;
            }
            if ($scanned_bytes + $entry['bytes'] > self::MAX_SCAN_BYTES) {
                break;
            }
            $content = file_get_contents($entry['absolute']);
            if (!is_string($content)) {
                continue;
            }
            $scanned_files++;
            $scanned_bytes += strlen($content);
            $lines = preg_split('/\R/u', $content);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $index => $line) {
                $found = $case_sensitive ? strpos($line, $query) : stripos($line, $query);
                if ($found === false) {
                    continue;
                }
                $before = [];
                $after = [];
                for ($n = max(0, $index - $context_lines); $n < $index; $n++) {
                    $before[] = ['line' => $n + 1, 'text' => $lines[$n]];
                }
                for ($n = $index + 1; $n <= min(count($lines) - 1, $index + $context_lines); $n++) {
                    $after[] = ['line' => $n + 1, 'text' => $lines[$n]];
                }
                $results[] = [
                    'path' => $entry['path'],
                    'line' => $index + 1,
                    'text' => $line,
                    'before' => $before,
                    'after' => $after,
                ];
                if (count($results) >= $max_results) {
                    break 2;
                }
            }
        }

        return rest_ensure_response([
            'ok' => true,
            'scope' => $root['scope'],
            'draft_id' => $root['draft_id'],
            'query_sha256' => hash('sha256', $query),
            'case_sensitive' => $case_sensitive,
            'extensions' => $extensions,
            'results' => $results,
            'returned' => count($results),
            'truncated' => count($results) >= $max_results || $scanned_bytes >= self::MAX_SCAN_BYTES,
            'scanned_files' => $scanned_files,
            'scanned_bytes' => $scanned_bytes,
            'skipped_large_files' => $skipped_large,
            'scan_byte_limit' => self::MAX_SCAN_BYTES,
        ]);
    }

    private static function read_many(array $params)
    {
        $items = isset($params['files']) && is_array($params['files']) ? $params['files'] : [];
        if (!$items || count($items) > self::MAX_READ_ITEMS) {
            return new WP_Error('takka_bridge_v096_theme_files_read_many', 'Provide 1-' . self::MAX_READ_ITEMS . ' files.', ['status' => 400]);
        }
        $max_chars = isset($params['max_total_chars'])
            ? max(1000, min(self::MAX_READ_CHARS, (int) $params['max_total_chars']))
            : 300000;
        $common_scope = isset($params['scope']) && is_string($params['scope']) ? $params['scope'] : 'active';
        $common_draft = isset($params['draft_id']) && is_string($params['draft_id']) ? $params['draft_id'] : null;
        $out = [];
        $used = 0;
        $truncated = false;

        foreach ($items as $index => $item) {
            if (!is_array($item) || !isset($item['path']) || !is_string($item['path']) || trim($item['path']) === '') {
                return new WP_Error('takka_bridge_v096_theme_files_read_item', 'Each files entry requires a path.', [
                    'status' => 400,
                    'index' => $index,
                ]);
            }
            $read = [
                'scope' => isset($item['scope']) && is_string($item['scope']) ? $item['scope'] : $common_scope,
                'path' => trim($item['path']),
            ];
            $draft_id = isset($item['draft_id']) && is_string($item['draft_id']) ? $item['draft_id'] : $common_draft;
            if (is_string($draft_id) && $draft_id !== '') {
                $read['draft_id'] = $draft_id;
            }
            if (isset($item['start_line'])) {
                $read['start_line'] = (int) $item['start_line'];
            }
            if (isset($item['end_line'])) {
                $read['end_line'] = (int) $item['end_line'];
            } else {
                $read['end_line'] = isset($read['start_line']) ? $read['start_line'] + 499 : 500;
            }

            $response = TakKa_WordPress_Bridge_V095_Outline::read_range($read);
            if (is_wp_error($response)) {
                $out[] = [
                    'path' => $read['path'],
                    'ok' => false,
                    'error' => [
                        'code' => $response->get_error_code(),
                        'message' => $response->get_error_message(),
                        'data' => $response->get_error_data(),
                    ],
                ];
                continue;
            }
            $data = rest_ensure_response($response)->get_data();
            if (!is_array($data)) {
                continue;
            }
            $text = isset($data['text']) && is_string($data['text']) ? $data['text'] : '';
            $remaining = max(0, $max_chars - $used);
            if (mb_strlen($text) > $remaining) {
                $text = mb_substr($text, 0, $remaining);
                $truncated = true;
            }
            $used += mb_strlen($text);
            $out[] = [
                'ok' => true,
                'path' => $data['path'] ?? $read['path'],
                'scope' => $data['scope'] ?? $read['scope'],
                'draft_id' => $data['draft_id'] ?? null,
                'sha256' => $data['sha256'] ?? null,
                'total_lines' => $data['total_lines'] ?? null,
                'start_line' => $data['start_line'] ?? null,
                'end_line' => $data['end_line'] ?? null,
                'text' => $text,
                'output_truncated' => isset($data['text']) && is_string($data['text']) ? mb_strlen($data['text']) > mb_strlen($text) : false,
            ];
            if ($used >= $max_chars) {
                $truncated = true;
                break;
            }
        }

        return rest_ensure_response([
            'ok' => true,
            'files' => $out,
            'requested' => count($items),
            'returned' => count($out),
            'max_total_chars' => $max_chars,
            'returned_chars' => $used,
            'truncated' => $truncated || count($out) < count($items),
        ]);
    }

    private static function resolve_root(array $params)
    {
        $scope = is_string($params['scope'] ?? null) ? strtolower(trim($params['scope'])) : 'active';
        $draft_id = null;
        if ($scope === 'active') {
            $root = realpath(get_stylesheet_directory());
        } elseif ($scope === 'draft') {
            $draft_id = is_string($params['draft_id'] ?? null) ? trim($params['draft_id']) : '';
            if ($draft_id === '') {
                return new WP_Error('takka_bridge_v096_theme_files_draft', 'draft_id is required for draft scope.', ['status' => 400]);
            }
            $slug = '';
            $drafts = get_option('takka_bridge_draft_themes', []);
            if (is_array($drafts) && isset($drafts[$draft_id])) {
                $slug = basename((string) ($drafts[$draft_id]['slug'] ?? ''));
            }
            if ($slug === '') {
                $classic = get_option('takka_bridge_v095_classic_drafts', []);
                if (is_array($classic) && isset($classic[$draft_id])) {
                    $slug = basename((string) ($classic[$draft_id]['slug'] ?? ''));
                }
            }
            if ($slug === '') {
                return new WP_Error('takka_bridge_v096_theme_files_draft_not_found', 'Theme draft was not found.', ['status' => 404]);
            }
            $root = realpath(rtrim(get_theme_root(), '/\\') . DIRECTORY_SEPARATOR . $slug);
        } else {
            return new WP_Error('takka_bridge_v096_theme_files_scope', 'scope must be active or draft.', ['status' => 400]);
        }
        if ($root === false || !is_dir($root)) {
            return new WP_Error('takka_bridge_v096_theme_files_root', 'Theme directory was not found.', ['status' => 404]);
        }
        return ['scope' => $scope, 'draft_id' => $draft_id, 'root' => $root];
    }

    private static function walk(string $root)
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $info) {
            if (!$info->isFile() || $info->isLink()) {
                continue;
            }
            $absolute = realpath($info->getPathname());
            if ($absolute === false || strpos($absolute, $root . DIRECTORY_SEPARATOR) !== 0) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
            if ($relative === '' || strpos($relative, '../') !== false) {
                continue;
            }
            $out[] = [
                'path' => $relative,
                'absolute' => $absolute,
                'bytes' => (int) $info->getSize(),
                'extension' => strtolower(pathinfo($relative, PATHINFO_EXTENSION)),
            ];
            if (count($out) >= self::MAX_FILES) {
                break;
            }
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });
        return $out;
    }

    private static function extensions(array $params)
    {
        if (!isset($params['extensions'])) {
            return self::DEFAULT_EXTENSIONS;
        }
        if (!is_array($params['extensions']) || !$params['extensions']) {
            return new WP_Error('takka_bridge_v096_theme_files_extensions', 'extensions must be a non-empty array.', ['status' => 400]);
        }
        $out = [];
        foreach ($params['extensions'] as $extension) {
            if (!is_string($extension)) {
                return new WP_Error('takka_bridge_v096_theme_files_extension', 'Invalid file extension.', ['status' => 400]);
            }
            $extension = strtolower(ltrim(trim($extension), '.'));
            if (!in_array($extension, self::DEFAULT_EXTENSIONS, true)) {
                return new WP_Error('takka_bridge_v096_theme_files_extension', 'Unsupported file extension.', [
                    'status' => 400,
                    'extension' => $extension,
                ]);
            }
            if (!in_array($extension, $out, true)) {
                $out[] = $extension;
            }
        }
        return $out;
    }

    private static function glob_match(string $pattern, string $path): bool
    {
        if (function_exists('fnmatch')) {
            return fnmatch($pattern, $path, FNM_PATHNAME);
        }
        $quoted = preg_quote($pattern, '~');
        $quoted = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $quoted);
        return (bool) preg_match('~^' . $quoted . '$~', $path);
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'scopes' => ['active', 'draft'],
            'list' => ['glob_pattern' => true, 'max_returned' => self::MAX_LIST_RETURN],
            'search' => [
                'substring' => true,
                'case_sensitive_optional' => true,
                'context_lines' => true,
                'max_results' => self::MAX_RESULTS,
                'max_scan_bytes' => self::MAX_SCAN_BYTES,
            ],
            'read_many' => [
                'max_files' => self::MAX_READ_ITEMS,
                'max_lines_per_file' => 500,
                'max_total_chars' => self::MAX_READ_CHARS,
            ],
        ];
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
        foreach (['theme_file_glob_list', 'theme_file_content_search', 'bounded_multi_file_read'] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $data['theme_files_v096'] = self::capabilities();
        $rest->set_data($data);
        return $rest;
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
