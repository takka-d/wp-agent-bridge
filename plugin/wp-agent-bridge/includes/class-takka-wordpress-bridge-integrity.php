<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integrity guards and diagnostics for exact theme asset writes and staged media.
 *
 * This class intentionally layers on top of the existing Bridge surfaces so the
 * existing backup/lint/atomic-write and media-upload behavior remains unchanged.
 */
final class TakKa_WordPress_Bridge_Integrity
{
    private const RUNTIME_NAMESPACE = 'wp-agent-bridge-runtime/v1';
    private const MEDIA_VERIFY_ROUTE = '/media-verify';
    private const MAX_MEDIA_BYTES = 6291456;
    private const MAX_SOURCE_TEXT_BYTES = 10485760;
    private const MAX_CHUNKS = 32;
    private const DRAFTS_OPTION = 'takka_bridge_draft_themes';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_before_callbacks', [self::class, 'guard_legacy_theme_write'], 500, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'after_callbacks'], 560, 3);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::RUNTIME_NAMESPACE, self::MEDIA_VERIFY_ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'media_verify'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpab_integrity_forbidden', 'Administrator capability is required.', ['status' => 403]);
        }
        return true;
    }

    /**
     * Optional integrity fields for the existing theme.file.write action.
     *
     * Existing callers remain compatible. When either guard field is supplied,
     * it is enforced before the legacy callback can mutate the theme.
     */
    public static function guard_legacy_theme_write($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null || $request->get_route() !== '/takka-bridge/v1/execute') {
            return $response;
        }

        if ((string) $request->get_param('action') !== 'theme.file.write') {
            return $response;
        }

        $params = $request->get_param('params');
        if (!is_array($params)) {
            return $response;
        }

        $require_complete_guard = !empty($params['require_integrity']);
        $has_content_guard = array_key_exists('expected_content_sha256', $params);
        $has_current_guard = array_key_exists('expected_current_sha256', $params)
            || array_key_exists('expected_current_absent', $params);
        if (!$require_complete_guard && !$has_content_guard && !$has_current_guard) {
            return $response;
        }

        $checked = self::validate_theme_integrity($params, $require_complete_guard);
        return is_wp_error($checked) ? $checked : $response;
    }

    private static function validate_theme_integrity(array $params, bool $require_complete_guard)
    {
        $path = isset($params['path']) && is_string($params['path']) ? trim($params['path']) : '';
        if ($path === '') {
            return new WP_Error('wpab_integrity_theme_path', 'path is required.', ['status' => 400]);
        }
        if (!array_key_exists('content', $params) || !is_string($params['content'])) {
            return new WP_Error('wpab_integrity_theme_content', 'content must be a string.', ['status' => 400]);
        }

        $expected_content = isset($params['expected_content_sha256']) && is_string($params['expected_content_sha256'])
            ? strtolower(trim($params['expected_content_sha256']))
            : '';
        if ($require_complete_guard || array_key_exists('expected_content_sha256', $params)) {
            if (!preg_match('/^[a-f0-9]{64}$/', $expected_content)) {
                return new WP_Error('wpab_integrity_theme_expected_content', 'expected_content_sha256 must be a SHA-256 hex digest.', ['status' => 400]);
            }
            $actual_content = hash('sha256', $params['content']);
            if (!hash_equals($expected_content, $actual_content)) {
                return new WP_Error('wpab_integrity_theme_content_mismatch', 'Theme content does not match expected_content_sha256.', [
                    'status' => 409,
                    'expected_content_sha256' => $expected_content,
                    'actual_content_sha256' => $actual_content,
                    'bytes' => strlen($params['content']),
                ]);
            }
        }

        $target = self::theme_target($params, $path);
        if (is_wp_error($target)) {
            return $target;
        }

        $exists = is_file($target);
        $expected_current = isset($params['expected_current_sha256']) && is_string($params['expected_current_sha256'])
            ? strtolower(trim($params['expected_current_sha256']))
            : '';
        $expect_absent = !empty($params['expected_current_absent']);

        if ($require_complete_guard && $expected_current === '' && !$expect_absent) {
            return new WP_Error(
                'wpab_integrity_theme_current_required',
                'Provide expected_current_sha256 for an existing target, or expected_current_absent=true for a new target.',
                ['status' => 400]
            );
        }
        if ($expected_current !== '' && $expect_absent) {
            return new WP_Error('wpab_integrity_theme_current_conflict', 'Do not combine expected_current_sha256 with expected_current_absent=true.', ['status' => 400]);
        }
        if ($expected_current !== '' && !preg_match('/^[a-f0-9]{64}$/', $expected_current)) {
            return new WP_Error('wpab_integrity_theme_expected_current', 'expected_current_sha256 must be a SHA-256 hex digest.', ['status' => 400]);
        }

        if ($expect_absent && $exists) {
            return new WP_Error('wpab_integrity_theme_expected_absent', 'Theme target exists but expected_current_absent=true was declared.', [
                'status' => 409,
                'path' => $path,
                'actual_current_sha256' => hash_file('sha256', $target),
            ]);
        }

        if ($expected_current !== '') {
            if (!$exists) {
                return new WP_Error('wpab_integrity_theme_current_missing', 'Theme target does not exist for expected_current_sha256.', [
                    'status' => 409,
                    'path' => $path,
                    'expected_current_sha256' => $expected_current,
                ]);
            }
            $actual_current = hash_file('sha256', $target);
            if (!is_string($actual_current) || !hash_equals($expected_current, $actual_current)) {
                return new WP_Error('wpab_integrity_theme_current_mismatch', 'Theme target changed before the guarded write.', [
                    'status' => 409,
                    'path' => $path,
                    'expected_current_sha256' => $expected_current,
                    'actual_current_sha256' => is_string($actual_current) ? $actual_current : null,
                ]);
            }
        }

        return true;
    }

    private static function theme_target(array $params, string $relative)
    {
        if (strpos($relative, "\0") !== false) {
            return new WP_Error('wpab_integrity_theme_path', 'Invalid path.', ['status' => 400]);
        }
        $relative = str_replace('\\', '/', $relative);
        if ($relative === '' || $relative[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
            return new WP_Error('wpab_integrity_theme_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
        }

        $draft_id = isset($params['draft_id']) && is_string($params['draft_id']) ? trim($params['draft_id']) : '';
        if ($draft_id !== '') {
            $drafts = get_option(self::DRAFTS_OPTION, []);
            if (!is_array($drafts) || !isset($drafts[$draft_id]) || !is_array($drafts[$draft_id])) {
                return new WP_Error('wpab_integrity_theme_draft_not_found', 'Draft theme not found.', ['status' => 404]);
            }
            $slug = isset($drafts[$draft_id]['slug']) ? basename((string) $drafts[$draft_id]['slug']) : '';
            $theme_root = realpath(get_theme_root());
            $root = $theme_root !== false && $slug !== '' ? realpath($theme_root . DIRECTORY_SEPARATOR . $slug) : false;
        } else {
            $root = realpath(get_stylesheet_directory());
        }

        if ($root === false || !is_dir($root)) {
            return new WP_Error('wpab_integrity_theme_root_missing', 'Theme directory not found.', ['status' => 500]);
        }

        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (file_exists($target)) {
            $real = realpath($target);
            if ($real === false || !is_file($real) || !self::path_inside($root, $real)) {
                return new WP_Error('wpab_integrity_theme_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
            }
            return $real;
        }

        $parent = dirname($target);
        while (!is_dir($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                break;
            }
            $parent = $next;
        }
        $parent_real = realpath($parent);
        if ($parent_real === false || !self::path_inside($root, $parent_real, true)) {
            return new WP_Error('wpab_integrity_theme_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
        }
        return $target;
    }

    private static function path_inside(string $root, string $path, bool $allow_same = false): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($allow_same && $path === $root) {
            return true;
        }
        return strpos($path . '/', $root . '/') === 0 && $path !== $root;
    }

    public static function media_verify(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('wpab_integrity_media_json', 'JSON body is required.', ['status' => 400]);
        }

        $report = self::inspect_staged_media($json);
        if (is_wp_error($report)) {
            return $report;
        }
        if (empty($report['ok'])) {
            return new WP_Error('wpab_integrity_media_verify_mismatch', 'Staged media does not match the declared integrity metadata.', array_merge([
                'status' => 409,
                'side_effects' => false,
            ], $report));
        }

        $report['verified'] = true;
        $report['side_effects'] = false;
        $report['source_cleanup'] = false;
        return rest_ensure_response($report);
    }

    public static function after_callbacks($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() === '/takka-bridge/v1/health' && !is_wp_error($response)) {
            return self::annotate_health($response);
        }

        if ($request->get_route() !== '/wp-agent-bridge-runtime/v1/media-upload'
            || !is_wp_error($response)
            || $response->get_error_code() !== 'wpab_direct_media_integrity_mismatch') {
            return $response;
        }

        $json = $request->get_json_params();
        if (!is_array($json)) {
            return $response;
        }

        $data = $response->get_error_data('wpab_direct_media_integrity_mismatch');
        if (!is_array($data)) {
            $data = [];
        }
        $report = self::inspect_staged_media($json);
        if (is_wp_error($report)) {
            $data['chunk_diagnostics_error'] = [
                'code' => $report->get_error_code(),
                'message' => $report->get_error_message(),
            ];
        } else {
            $data['chunk_diagnostics'] = $report;
        }
        $response->add_data($data, 'wpab_direct_media_integrity_mismatch');
        return $response;
    }

    private static function annotate_health($response)
    {
        $rest_response = rest_ensure_response($response);
        $data = $rest_response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['media_runtime_verify', 'media_runtime_chunk_diagnostics', 'theme_write_integrity_guard'] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $data['media_runtime_verify'] = [
            'route' => '/' . self::RUNTIME_NAMESPACE . self::MEDIA_VERIFY_ROUTE,
            'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            'max_chunks' => self::MAX_CHUNKS,
            'integrity' => ['expected_bytes', 'expected_sha256', 'chunk_integrity'],
            'side_effects' => false,
        ];
        if (isset($data['media_runtime_file_upload']) && is_array($data['media_runtime_file_upload'])) {
            $upload = $data['media_runtime_file_upload'];
            $integrity = isset($upload['integrity']) && is_array($upload['integrity']) ? $upload['integrity'] : [];
            if (!in_array('chunk_integrity', $integrity, true)) {
                $integrity[] = 'chunk_integrity';
            }
            $upload['integrity'] = $integrity;
            $upload['chunk_integrity_optional'] = true;
            $upload['verify_route'] = '/' . self::RUNTIME_NAMESPACE . self::MEDIA_VERIFY_ROUTE;
            $upload['mismatch_diagnostics'] = true;
            $data['media_runtime_file_upload'] = $upload;
        }
        $data['theme_write_integrity_guard'] = [
            'route' => '/takka-bridge/v1/execute',
            'action' => 'theme.file.write',
            'require_flag' => 'require_integrity',
            'required_when_flagged' => ['expected_content_sha256', 'expected_current_sha256|expected_current_absent'],
            'optional_fields' => ['expected_content_sha256', 'expected_current_sha256', 'expected_current_absent'],
        ];
        $rest_response->set_data($data);
        return $rest_response;
    }

    private static function inspect_staged_media(array $json)
    {
        $paths = self::payload_paths($json);
        if (is_wp_error($paths)) {
            return $paths;
        }

        $expected_bytes = isset($json['expected_bytes']) ? (int) $json['expected_bytes'] : 0;
        $expected_sha256 = isset($json['expected_sha256']) && is_string($json['expected_sha256'])
            ? strtolower(trim($json['expected_sha256']))
            : '';
        if ($expected_bytes < 1 || $expected_bytes > self::MAX_MEDIA_BYTES || !preg_match('/^[a-f0-9]{64}$/', $expected_sha256)) {
            return new WP_Error('wpab_integrity_media_whole_required', 'expected_bytes and expected_sha256 are required.', [
                'status' => 400,
                'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            ]);
        }

        $chunk_expectations = self::chunk_expectations($json, $paths);
        if (is_wp_error($chunk_expectations)) {
            return $chunk_expectations;
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = isset($connection['repository']) ? trim((string) $connection['repository']) : '';
        $branch = isset($connection['runtime_branch']) ? (string) $connection['runtime_branch'] : '';
        if ($installation_id < 1 || $repository_id < 1 || $repository === '' || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return new WP_Error('wpab_integrity_media_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $binary = '';
        $chunks = [];
        $all_chunks_match = $chunk_expectations !== null ? true : null;
        foreach ($paths as $index => $path) {
            $source = self::read_runtime_file($token, $repository, $branch, $path);
            if (is_wp_error($source)) {
                self::memzero($binary);
                return $source;
            }
            $chunk = self::decode_base64((string) $source['text']);
            if (is_wp_error($chunk)) {
                self::memzero($binary);
                return $chunk;
            }
            if (strlen($binary) + strlen($chunk) > self::MAX_MEDIA_BYTES) {
                self::memzero($chunk);
                self::memzero($binary);
                return new WP_Error('wpab_integrity_media_size', 'Decoded media exceeds the size limit.', [
                    'status' => 413,
                    'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
                ]);
            }

            $actual_chunk_bytes = strlen($chunk);
            $actual_chunk_sha256 = hash('sha256', $chunk);
            $expectation = $chunk_expectations !== null ? $chunk_expectations[$index] : null;
            $bytes_match = $expectation !== null ? $actual_chunk_bytes === $expectation['expected_bytes'] : null;
            $sha_match = $expectation !== null ? hash_equals($expectation['expected_sha256'], $actual_chunk_sha256) : null;
            $match = $expectation !== null ? ($bytes_match && $sha_match) : null;
            if ($match === false) {
                $all_chunks_match = false;
            }

            $chunks[] = [
                'index' => $index,
                'path' => $path,
                'source_blob_sha' => (string) $source['sha'],
                'source_text_bytes' => strlen((string) $source['text']),
                'actual_bytes' => $actual_chunk_bytes,
                'actual_sha256' => $actual_chunk_sha256,
                'expected_bytes' => $expectation !== null ? $expectation['expected_bytes'] : null,
                'expected_sha256' => $expectation !== null ? $expectation['expected_sha256'] : null,
                'bytes_match' => $bytes_match,
                'sha256_match' => $sha_match,
                'match' => $match,
            ];

            $binary .= $chunk;
            self::memzero($chunk);
        }

        $actual_bytes = strlen($binary);
        $actual_sha256 = hash('sha256', $binary);
        $whole_bytes_match = $actual_bytes === $expected_bytes;
        $whole_sha_match = hash_equals($expected_sha256, $actual_sha256);
        $whole_match = $whole_bytes_match && $whole_sha_match;
        $ok = $whole_match && ($all_chunks_match !== false);

        if ($all_chunks_match === false) {
            $diagnosis = 'one_or_more_chunks_mismatch';
        } elseif ($all_chunks_match === true && !$whole_match) {
            $diagnosis = 'declared_chunks_match_but_whole_file_mismatch';
        } elseif ($all_chunks_match === null && !$whole_match) {
            $diagnosis = 'whole_file_mismatch_without_chunk_expectations';
        } else {
            $diagnosis = 'integrity_ok';
        }

        self::memzero($binary);
        return [
            'ok' => $ok,
            'diagnosis' => $diagnosis,
            'chunk_integrity_supplied' => $chunk_expectations !== null,
            'all_chunks_match' => $all_chunks_match,
            'expected_bytes' => $expected_bytes,
            'actual_bytes' => $actual_bytes,
            'expected_sha256' => $expected_sha256,
            'actual_sha256' => $actual_sha256,
            'whole_bytes_match' => $whole_bytes_match,
            'whole_sha256_match' => $whole_sha_match,
            'whole_match' => $whole_match,
            'chunks' => $chunks,
        ];
    }

    private static function payload_paths(array $json)
    {
        $paths = [];
        if (isset($json['data_paths']) && is_array($json['data_paths'])) {
            foreach ($json['data_paths'] as $path) {
                if (is_string($path) && trim($path) !== '') {
                    $paths[] = trim($path);
                }
            }
        } elseif (isset($json['data_path']) && is_string($json['data_path']) && trim($json['data_path']) !== '') {
            $paths[] = trim($json['data_path']);
        }

        if (!$paths || count($paths) > self::MAX_CHUNKS) {
            return new WP_Error('wpab_integrity_media_paths', 'Provide data_path or 1-' . self::MAX_CHUNKS . ' data_paths.', ['status' => 400]);
        }

        $seen = [];
        foreach ($paths as $path) {
            if (!preg_match('#^wordpress-bridge/media/pending/[A-Za-z0-9._-]{1,120}\.b64$#', $path)) {
                return new WP_Error('wpab_integrity_media_path', 'Media payload paths must match wordpress-bridge/media/pending/<id>.b64.', ['status' => 400]);
            }
            if (isset($seen[$path])) {
                return new WP_Error('wpab_integrity_media_duplicate_path', 'Duplicate media payload path.', ['status' => 400, 'path' => $path]);
            }
            $seen[$path] = true;
        }
        return $paths;
    }

    private static function chunk_expectations(array $json, array $paths)
    {
        if (!array_key_exists('chunk_integrity', $json)) {
            return null;
        }
        if (!is_array($json['chunk_integrity']) || count($json['chunk_integrity']) !== count($paths)) {
            return new WP_Error('wpab_integrity_media_chunk_manifest', 'chunk_integrity must contain one entry for every data_path.', ['status' => 400]);
        }

        $out = [];
        foreach ($paths as $index => $path) {
            $entry = $json['chunk_integrity'][$index] ?? null;
            if (!is_array($entry)) {
                return new WP_Error('wpab_integrity_media_chunk_entry', 'Each chunk_integrity entry must be an object.', ['status' => 400, 'index' => $index]);
            }
            if (isset($entry['path']) && (!is_string($entry['path']) || trim($entry['path']) !== $path)) {
                return new WP_Error('wpab_integrity_media_chunk_path', 'chunk_integrity path does not match data_paths ordering.', [
                    'status' => 400,
                    'index' => $index,
                    'expected_path' => $path,
                ]);
            }
            $bytes = isset($entry['expected_bytes']) ? (int) $entry['expected_bytes'] : 0;
            $sha = isset($entry['expected_sha256']) && is_string($entry['expected_sha256'])
                ? strtolower(trim($entry['expected_sha256']))
                : '';
            if ($bytes < 1 || $bytes > self::MAX_MEDIA_BYTES || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
                return new WP_Error('wpab_integrity_media_chunk_values', 'Each chunk_integrity entry requires expected_bytes and expected_sha256.', [
                    'status' => 400,
                    'index' => $index,
                ]);
            }
            $out[] = [
                'expected_bytes' => $bytes,
                'expected_sha256' => $sha,
            ];
        }
        return $out;
    }

    private static function read_runtime_file(string $token, string $repository, string $ref, string $path)
    {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return $meta;
        }
        $sha = isset($meta['sha']) && is_string($meta['sha']) ? strtolower(trim((string) $meta['sha'])) : '';
        if (!preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
            return new WP_Error('wpab_integrity_media_source_sha', 'GitHub did not return a valid source blob SHA.', ['status' => 502]);
        }

        $encoded = isset($meta['content']) && is_string($meta['content']) ? preg_replace('/\s+/', '', $meta['content']) : '';
        if ($encoded === '') {
            $blob = TakKa_WordPress_Bridge_Direct_GitHub::github_api('GET', '/repos/' . $repository . '/git/blobs/' . $sha, $token);
            if (is_wp_error($blob)) {
                return $blob;
            }
            $blob_data = isset($blob['data']) && is_array($blob['data']) ? $blob['data'] : [];
            $encoded = isset($blob_data['content']) && is_string($blob_data['content']) ? preg_replace('/\s+/', '', $blob_data['content']) : '';
        }
        if (!is_string($encoded) || $encoded === '') {
            return new WP_Error('wpab_integrity_media_source_content', 'GitHub media payload content is missing.', ['status' => 502]);
        }

        $text = base64_decode($encoded, true);
        if (!is_string($text)) {
            return new WP_Error('wpab_integrity_media_source_decode', 'Could not decode the GitHub file content.', ['status' => 502]);
        }
        if (strlen($text) < 1 || strlen($text) > self::MAX_SOURCE_TEXT_BYTES) {
            return new WP_Error('wpab_integrity_media_source_size', 'Runtime media payload file is empty or too large.', [
                'status' => 413,
                'max_source_text_bytes' => self::MAX_SOURCE_TEXT_BYTES,
            ]);
        }
        return ['text' => $text, 'sha' => $sha];
    }

    private static function decode_base64(string $value)
    {
        $value = trim($value);
        if (preg_match('#^data:[^,]*;base64,#i', $value, $matches)) {
            $value = substr($value, strlen($matches[0]));
        }
        $value = preg_replace('/\s+/', '', $value);
        if (!is_string($value) || $value === '') {
            return new WP_Error('wpab_integrity_media_base64_empty', 'Base64 media payload is empty.', ['status' => 400]);
        }
        $value = strtr($value, '-_', '+/');
        if (preg_match('/[^A-Za-z0-9+\/=]/', $value)) {
            return new WP_Error('wpab_integrity_media_base64_chars', 'Base64 media payload contains invalid characters.', ['status' => 400]);
        }
        $unpadded = rtrim($value, '=');
        $existing_padding = strlen($value) - strlen($unpadded);
        if ($existing_padding > 2 || strpos($unpadded, '=') !== false) {
            return new WP_Error('wpab_integrity_media_base64_padding', 'Base64 media payload has invalid padding.', ['status' => 400]);
        }
        $mod = strlen($unpadded) % 4;
        if ($mod === 1) {
            return new WP_Error('wpab_integrity_media_base64_length', 'Base64 media payload has an invalid length.', ['status' => 400]);
        }
        $normalized = $unpadded . str_repeat('=', (4 - $mod) % 4);
        $binary = base64_decode($normalized, true);
        if (!is_string($binary)) {
            return new WP_Error('wpab_integrity_media_base64_decode', 'Base64 media payload could not be decoded.', ['status' => 400]);
        }
        if (strlen($binary) < 1 || strlen($binary) > self::MAX_MEDIA_BYTES) {
            self::memzero($binary);
            return new WP_Error('wpab_integrity_media_chunk_size', 'Decoded media chunk is empty or too large.', [
                'status' => 413,
                'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            ]);
        }
        return $binary;
    }

    private static function memzero(string &$value): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = '';
        }
    }
}
