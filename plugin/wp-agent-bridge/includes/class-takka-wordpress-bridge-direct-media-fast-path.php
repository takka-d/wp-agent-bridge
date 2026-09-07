<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fast path for Direct Runtime staged-media uploads.
 *
 * When the command supplies the Git blob SHA for every staged Base64 payload,
 * WordPress can read each blob directly instead of resolving every path through
 * the Contents API first. The staged path -> blob mapping is validated with one
 * recursive tree read, chunk/whole-file integrity is checked during the upload,
 * and successful cleanup reuses the same branch snapshot on the common path.
 *
 * Commands without data_blob_shas fall through to the existing media callback.
 */
final class TakKa_WordPress_Bridge_Direct_Media_Fast_Path
{
    private const MEDIA_ROUTE = '/wp-agent-bridge-runtime/v1/media-upload';
    private const MAX_MEDIA_BYTES = 6291456;
    private const MAX_SOURCE_TEXT_BYTES = 10485760;
    private const MAX_CHUNKS = 32;
    private const CLEANUP_RETRIES = 3;

    public static function init(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'maybe_upload'], 525, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 550, 3);
    }

    public static function maybe_upload($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null
            || $request->get_route() !== self::MEDIA_ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }

        $json = $request->get_json_params();
        if (!is_array($json) || !array_key_exists('data_blob_shas', $json)) {
            return $response;
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpab_direct_media_fast_forbidden', 'Administrator capability is required.', ['status' => 403]);
        }

        return self::upload($json);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('media_runtime_blob_fast_path', $features, true)) {
            $features[] = 'media_runtime_blob_fast_path';
        }
        $data['features'] = $features;

        $upload = isset($data['media_runtime_file_upload']) && is_array($data['media_runtime_file_upload'])
            ? $data['media_runtime_file_upload']
            : ['route' => self::MEDIA_ROUTE];
        $upload['data_blob_shas_optional'] = true;
        $upload['blob_sha_direct_read'] = true;
        $upload['blob_sha_requires_git_data_staging'] = true;
        $upload['normal_flow_verify_first'] = false;
        $upload['verify_on'] = ['explicit_verify_only_request', 'previous_integrity_409', 'staging_uncertainty'];
        $upload['recommended_decoded_chunk_bytes'] = 524288;
        $upload['preferred_publish_mode'] = 'single-git-tree-commit';
        $upload['source_mapping_verification'] = 'single-recursive-tree-read';
        $upload['cleanup_snapshot_reuse'] = true;
        $data['media_runtime_file_upload'] = $upload;

        $rest->set_data($data);
        return $rest;
    }

    private static function upload(array $json)
    {
        $paths = self::payload_paths($json);
        if (is_wp_error($paths)) {
            return $paths;
        }
        $blob_shas = self::payload_blob_shas($json, $paths);
        if (is_wp_error($blob_shas)) {
            return $blob_shas;
        }
        $chunk_integrity = self::chunk_integrity($json, $paths);
        if (is_wp_error($chunk_integrity)) {
            return $chunk_integrity;
        }

        $filename = isset($json['filename']) && is_string($json['filename']) ? sanitize_file_name($json['filename']) : '';
        if ($filename === '' || strpos($filename, '.') === false) {
            return new WP_Error('wpab_direct_media_filename', 'filename must include an allowed file extension.', ['status' => 400]);
        }

        $expected_bytes = isset($json['expected_bytes']) ? (int) $json['expected_bytes'] : 0;
        $expected_sha256 = isset($json['expected_sha256']) && is_string($json['expected_sha256'])
            ? strtolower(trim($json['expected_sha256']))
            : '';
        if ($expected_bytes < 1 || $expected_bytes > self::MAX_MEDIA_BYTES || !preg_match('/^[a-f0-9]{64}$/', $expected_sha256)) {
            return new WP_Error('wpab_direct_media_integrity_required', 'expected_bytes and expected_sha256 are required for runtime media uploads.', [
                'status' => 400,
                'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            ]);
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = isset($connection['repository']) ? trim((string) $connection['repository']) : '';
        $branch = isset($connection['runtime_branch']) ? (string) $connection['runtime_branch'] : '';
        if ($installation_id < 1
            || $repository_id < 1
            || $repository === ''
            || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return new WP_Error('wpab_direct_media_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $sources = [];
        foreach ($paths as $index => $path) {
            $sources[] = ['path' => $path, 'sha' => $blob_shas[$index]];
        }

        // One branch/tree snapshot validates all staged paths before any side effect.
        $snapshot = self::snapshot_sources($token, $repository, $branch, $sources);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }

        $binary = '';
        foreach ($sources as $index => $source) {
            $source_text = self::read_blob_text($token, $repository, $source['sha']);
            if (is_wp_error($source_text)) {
                self::memzero($binary);
                return $source_text;
            }
            $chunk = self::decode_base64($source_text);
            self::memzero($source_text);
            if (is_wp_error($chunk)) {
                self::memzero($binary);
                return $chunk;
            }

            $actual_chunk_bytes = strlen($chunk);
            $actual_chunk_sha256 = hash('sha256', $chunk);
            if ($chunk_integrity !== null) {
                $declared = $chunk_integrity[$index];
                if ($actual_chunk_bytes !== $declared['expected_bytes']
                    || !hash_equals($declared['expected_sha256'], $actual_chunk_sha256)) {
                    self::memzero($chunk);
                    self::memzero($binary);
                    return new WP_Error('wpab_direct_media_chunk_integrity_mismatch', 'A staged media chunk does not match its declared integrity metadata.', [
                        'status' => 409,
                        'index' => $index,
                        'path' => $source['path'],
                        'blob_sha' => $source['sha'],
                        'expected_bytes' => $declared['expected_bytes'],
                        'actual_bytes' => $actual_chunk_bytes,
                        'expected_sha256' => $declared['expected_sha256'],
                        'actual_sha256' => $actual_chunk_sha256,
                    ]);
                }
            }

            if (strlen($binary) + $actual_chunk_bytes > self::MAX_MEDIA_BYTES) {
                self::memzero($chunk);
                self::memzero($binary);
                return new WP_Error('wpab_direct_media_size', 'Decoded media exceeds the size limit.', [
                    'status' => 413,
                    'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
                ]);
            }
            $binary .= $chunk;
            self::memzero($chunk);
        }

        $actual_bytes = strlen($binary);
        $actual_sha256 = hash('sha256', $binary);
        if ($actual_bytes !== $expected_bytes || !hash_equals($expected_sha256, $actual_sha256)) {
            self::memzero($binary);
            return new WP_Error('wpab_direct_media_integrity_mismatch', 'Runtime media payload does not match the declared byte count or SHA-256.', [
                'status' => 409,
                'expected_bytes' => $expected_bytes,
                'actual_bytes' => $actual_bytes,
                'expected_sha256' => $expected_sha256,
                'actual_sha256' => $actual_sha256,
                'fast_path' => true,
            ]);
        }

        $uploaded = self::sideload($binary, $filename, $json);
        self::memzero($binary);
        if (is_wp_error($uploaded)) {
            return $uploaded;
        }

        $cleanup = self::cleanup_sources_atomic($token, $repository, $branch, $sources, $snapshot);
        $uploaded['source_cleanup'] = !is_wp_error($cleanup);
        $uploaded['source_cleanup_mode'] = 'single-git-tree-commit';
        $uploaded['source_mapping_verification'] = 'single-recursive-tree-read';
        $uploaded['transport_fast_path'] = 'git-blob-sha-direct';
        $uploaded['source_paths'] = array_values($paths);
        $uploaded['source_blob_shas'] = array_values($blob_shas);
        $uploaded['sha256'] = $actual_sha256;
        if (is_wp_error($cleanup)) {
            $uploaded['source_cleanup_errors'] = ['atomic_cleanup' => $cleanup->get_error_message()];
        } else {
            $uploaded['source_cleanup_commit'] = $cleanup['commit_sha'] ?? null;
            $uploaded['source_cleanup_attempts'] = $cleanup['attempts'] ?? 1;
        }
        return rest_ensure_response($uploaded);
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
            return new WP_Error('wpab_direct_media_paths', 'Provide data_path or 1-' . self::MAX_CHUNKS . ' data_paths.', ['status' => 400]);
        }
        $seen = [];
        foreach ($paths as $path) {
            if (!preg_match('#^wordpress-bridge/media/pending/[A-Za-z0-9._-]{1,120}\.b64$#', $path)) {
                return new WP_Error('wpab_direct_media_path', 'Media payload paths must match wordpress-bridge/media/pending/<id>.b64.', ['status' => 400]);
            }
            if (isset($seen[$path])) {
                return new WP_Error('wpab_direct_media_duplicate_path', 'Duplicate media payload path.', ['status' => 400, 'path' => $path]);
            }
            $seen[$path] = true;
        }
        return $paths;
    }

    private static function payload_blob_shas(array $json, array $paths)
    {
        if (!isset($json['data_blob_shas']) || !is_array($json['data_blob_shas']) || count($json['data_blob_shas']) !== count($paths)) {
            return new WP_Error('wpab_direct_media_blob_shas', 'data_blob_shas must contain one Git blob SHA for every data_path.', ['status' => 400]);
        }
        $out = [];
        foreach ($json['data_blob_shas'] as $index => $sha) {
            $sha = is_string($sha) ? strtolower(trim($sha)) : '';
            if (!preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
                return new WP_Error('wpab_direct_media_blob_sha', 'Each data_blob_shas entry must be a Git blob SHA.', [
                    'status' => 400,
                    'index' => $index,
                ]);
            }
            $out[] = $sha;
        }
        return $out;
    }

    private static function chunk_integrity(array $json, array $paths)
    {
        if (!array_key_exists('chunk_integrity', $json)) {
            return null;
        }
        if (!is_array($json['chunk_integrity']) || count($json['chunk_integrity']) !== count($paths)) {
            return new WP_Error('wpab_direct_media_chunk_manifest', 'chunk_integrity must contain one entry for every data_path.', ['status' => 400]);
        }
        $out = [];
        foreach ($paths as $index => $path) {
            $entry = $json['chunk_integrity'][$index] ?? null;
            if (!is_array($entry)) {
                return new WP_Error('wpab_direct_media_chunk_entry', 'Each chunk_integrity entry must be an object.', ['status' => 400, 'index' => $index]);
            }
            if (isset($entry['path']) && (!is_string($entry['path']) || trim($entry['path']) !== $path)) {
                return new WP_Error('wpab_direct_media_chunk_path', 'chunk_integrity path does not match data_paths ordering.', ['status' => 400, 'index' => $index]);
            }
            $bytes = isset($entry['expected_bytes']) ? (int) $entry['expected_bytes'] : 0;
            $sha = isset($entry['expected_sha256']) && is_string($entry['expected_sha256'])
                ? strtolower(trim($entry['expected_sha256']))
                : '';
            if ($bytes < 1 || $bytes > self::MAX_MEDIA_BYTES || !preg_match('/^[a-f0-9]{64}$/', $sha)) {
                return new WP_Error('wpab_direct_media_chunk_values', 'Each chunk_integrity entry requires expected_bytes and expected_sha256.', ['status' => 400, 'index' => $index]);
            }
            $out[] = ['expected_bytes' => $bytes, 'expected_sha256' => $sha];
        }
        return $out;
    }

    private static function read_blob_text(string $token, string $repository, string $blob_sha)
    {
        $blob = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/git/blobs/' . $blob_sha,
            $token
        );
        if (is_wp_error($blob)) {
            return $blob;
        }
        $data = isset($blob['data']) && is_array($blob['data']) ? $blob['data'] : [];
        $returned_sha = isset($data['sha']) && is_string($data['sha']) ? strtolower(trim($data['sha'])) : '';
        if ($returned_sha !== '' && !hash_equals($blob_sha, $returned_sha)) {
            return new WP_Error('wpab_direct_media_blob_sha_mismatch', 'GitHub returned a different blob SHA than requested.', ['status' => 502]);
        }
        $encoding = isset($data['encoding']) ? strtolower((string) $data['encoding']) : '';
        $content = isset($data['content']) && is_string($data['content']) ? preg_replace('/\s+/', '', $data['content']) : '';
        if ($encoding !== 'base64' || !is_string($content) || $content === '') {
            return new WP_Error('wpab_direct_media_blob_content', 'GitHub media blob content is missing or not Base64 encoded.', ['status' => 502]);
        }
        $text = base64_decode($content, true);
        if (!is_string($text)) {
            return new WP_Error('wpab_direct_media_blob_decode', 'Could not decode the GitHub blob payload.', ['status' => 502]);
        }
        if (strlen($text) < 1 || strlen($text) > self::MAX_SOURCE_TEXT_BYTES) {
            self::memzero($text);
            return new WP_Error('wpab_direct_media_source_size', 'Runtime media payload file is empty or too large.', [
                'status' => 413,
                'max_source_text_bytes' => self::MAX_SOURCE_TEXT_BYTES,
            ]);
        }
        return $text;
    }

    private static function snapshot_sources(string $token, string $repository, string $branch, array $sources)
    {
        $ref = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/git/ref/heads/' . rawurlencode($branch),
            $token
        );
        if (is_wp_error($ref)) {
            return $ref;
        }
        $head = isset($ref['data']['object']['sha']) ? strtolower((string) $ref['data']['object']['sha']) : '';
        if (!preg_match('/^[a-f0-9]{40,64}$/', $head)) {
            return new WP_Error('wpab_direct_media_fast_head', 'GitHub did not return a valid runtime branch head.', ['status' => 502]);
        }

        $commit = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/git/commits/' . $head,
            $token
        );
        if (is_wp_error($commit)) {
            return $commit;
        }
        $base_tree = isset($commit['data']['tree']['sha']) ? strtolower((string) $commit['data']['tree']['sha']) : '';
        if (!preg_match('/^[a-f0-9]{40,64}$/', $base_tree)) {
            return new WP_Error('wpab_direct_media_fast_tree', 'GitHub did not return a valid runtime tree SHA.', ['status' => 502]);
        }

        $tree = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository . '/git/trees/' . $base_tree . '?recursive=1',
            $token
        );
        if (is_wp_error($tree)) {
            return $tree;
        }
        $entries = isset($tree['data']['tree']) && is_array($tree['data']['tree']) ? $tree['data']['tree'] : [];
        if (!empty($tree['data']['truncated'])) {
            return new WP_Error('wpab_direct_media_fast_tree_truncated', 'Runtime tree listing was truncated; fast-path source verification cannot continue.', ['status' => 503]);
        }
        $map = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = isset($entry['path']) ? (string) $entry['path'] : '';
            $sha = isset($entry['sha']) ? strtolower((string) $entry['sha']) : '';
            if ($path !== '' && preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
                $map[$path] = $sha;
            }
        }
        foreach ($sources as $source) {
            $path = $source['path'];
            $expected = $source['sha'];
            $actual = $map[$path] ?? '';
            if ($actual === '' || !hash_equals($expected, $actual)) {
                return new WP_Error('wpab_direct_media_fast_source_mapping', 'A staged media path does not point to the declared Git blob SHA.', [
                    'status' => 409,
                    'path' => $path,
                    'expected_blob_sha' => $expected,
                    'actual_blob_sha' => $actual !== '' ? $actual : null,
                ]);
            }
        }
        return ['head' => $head, 'base_tree' => $base_tree];
    }

    private static function cleanup_sources_atomic(string $token, string $repository, string $branch, array $sources, array $initial_snapshot)
    {
        for ($attempt = 1; $attempt <= self::CLEANUP_RETRIES; $attempt++) {
            $snapshot = $attempt === 1
                ? $initial_snapshot
                : self::snapshot_sources($token, $repository, $branch, $sources);
            if (is_wp_error($snapshot)) {
                return $snapshot;
            }
            $head = (string) $snapshot['head'];
            $base_tree = (string) $snapshot['base_tree'];

            $tree_entries = [];
            foreach ($sources as $source) {
                $tree_entries[] = [
                    'path' => $source['path'],
                    'mode' => '100644',
                    'type' => 'blob',
                    'sha' => null,
                ];
            }
            $tree = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'POST',
                '/repos/' . $repository . '/git/trees',
                $token,
                ['base_tree' => $base_tree, 'tree' => $tree_entries]
            );
            if (is_wp_error($tree)) {
                return $tree;
            }
            $tree_sha = isset($tree['data']['sha']) ? strtolower((string) $tree['data']['sha']) : '';
            if (!preg_match('/^[a-f0-9]{40,64}$/', $tree_sha)) {
                return new WP_Error('wpab_direct_media_fast_cleanup_tree', 'GitHub did not return a valid cleanup tree SHA.', ['status' => 502]);
            }

            $commit = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'POST',
                '/repos/' . $repository . '/git/commits',
                $token,
                [
                    'message' => 'WP Agent Bridge: remove uploaded media payloads',
                    'tree' => $tree_sha,
                    'parents' => [$head],
                ]
            );
            if (is_wp_error($commit)) {
                return $commit;
            }
            $commit_sha = isset($commit['data']['sha']) ? strtolower((string) $commit['data']['sha']) : '';
            if (!preg_match('/^[a-f0-9]{40,64}$/', $commit_sha)) {
                return new WP_Error('wpab_direct_media_fast_cleanup_commit', 'GitHub did not return a valid cleanup commit SHA.', ['status' => 502]);
            }

            $update = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
                'PATCH',
                '/repos/' . $repository . '/git/refs/heads/' . rawurlencode($branch),
                $token,
                ['sha' => $commit_sha, 'force' => false]
            );
            if (!is_wp_error($update)) {
                return ['ok' => true, 'commit_sha' => $commit_sha, 'attempts' => $attempt];
            }
            $error_data = $update->get_error_data();
            $status = is_array($error_data) ? (int) ($error_data['status'] ?? 0) : 0;
            if (($status === 409 || $status === 422) && $attempt < self::CLEANUP_RETRIES) {
                continue;
            }
            return $update;
        }
        return new WP_Error('wpab_direct_media_fast_cleanup_retry', 'Could not atomically clean up media payloads after retries.', ['status' => 409]);
    }

    private static function decode_base64(string $value)
    {
        $value = trim($value);
        if (preg_match('#^data:[^,]*;base64,#i', $value, $matches)) {
            $value = substr($value, strlen($matches[0]));
        }
        $value = preg_replace('/\s+/', '', $value);
        if (!is_string($value) || $value === '') {
            return new WP_Error('wpab_direct_media_base64_empty', 'Base64 media payload is empty.', ['status' => 400]);
        }
        $value = strtr($value, '-_', '+/');
        if (preg_match('/[^A-Za-z0-9+\/=]/', $value)) {
            return new WP_Error('wpab_direct_media_base64_chars', 'Base64 media payload contains invalid characters.', ['status' => 400]);
        }
        $unpadded = rtrim($value, '=');
        $existing_padding = strlen($value) - strlen($unpadded);
        if ($existing_padding > 2 || strpos($unpadded, '=') !== false) {
            return new WP_Error('wpab_direct_media_base64_padding', 'Base64 media payload has invalid padding.', ['status' => 400]);
        }
        $mod = strlen($unpadded) % 4;
        if ($mod === 1) {
            return new WP_Error('wpab_direct_media_base64_length', 'Base64 media payload has an invalid length.', ['status' => 400]);
        }
        $normalized = $unpadded . str_repeat('=', (4 - $mod) % 4);
        $binary = base64_decode($normalized, true);
        if (!is_string($binary)) {
            return new WP_Error('wpab_direct_media_base64_decode', 'Base64 media payload could not be decoded.', ['status' => 400]);
        }
        if (strlen($binary) < 1 || strlen($binary) > self::MAX_MEDIA_BYTES) {
            self::memzero($binary);
            return new WP_Error('wpab_direct_media_chunk_size', 'Decoded media chunk is empty or too large.', ['status' => 413]);
        }
        return $binary;
    }

    private static function sideload(string $binary, string $filename, array $params)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $tmp = wp_tempnam($filename);
        if (!$tmp || file_put_contents($tmp, $binary, LOCK_EX) === false) {
            if ($tmp) {
                @unlink($tmp);
            }
            return new WP_Error('wpab_direct_media_temp_write', 'Could not create temporary upload file.', ['status' => 500]);
        }
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $file_array = ['name' => $filename, 'tmp_name' => $tmp];
        $description = isset($params['description']) && is_string($params['description']) ? $params['description'] : null;
        $attachment_id = media_handle_sideload($file_array, $post_id, $description);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }
        if (isset($params['alt_text']) && is_string($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }
        $post_update = ['ID' => $attachment_id];
        foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $input => $field) {
            if (isset($params[$input]) && is_string($params[$input])) {
                $post_update[$field] = $params[$input];
            }
        }
        if (count($post_update) > 1) {
            wp_update_post(wp_slash($post_update));
        }
        return [
            'ok' => true,
            'id' => $attachment_id,
            'filename' => basename((string) get_attached_file($attachment_id)),
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'bytes' => strlen($binary),
        ];
    }

    private static function memzero(&$value): void
    {
        if (is_string($value) && function_exists('sodium_memzero')) {
            sodium_memzero($value);
        } else {
            $value = '';
        }
    }
}
