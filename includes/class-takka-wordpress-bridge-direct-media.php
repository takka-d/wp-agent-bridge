<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Media transport for Direct Runtime.
 *
 * Binary media is not embedded in the command JSON. ChatGPT writes a Base64
 * payload to wordpress-bridge/media/pending/<id>.b64 and submits a small REST
 * command that references that file. The plugin fetches the payload with its
 * site-specific GitHub App, verifies byte count + SHA-256, uploads it to the
 * WordPress media library, then removes the temporary payload from GitHub.
 */
final class TakKa_WordPress_Bridge_Direct_Media
{
    private const NAMESPACE = 'wp-agent-bridge-runtime/v1';
    private const ROUTE = '/wp-agent-bridge-runtime/v1/media-upload';
    private const MAX_MEDIA_BYTES = 6291456; // 6 MiB decoded.
    private const MAX_SOURCE_TEXT_BYTES = 10485760; // Base64 + small formatting overhead.

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 540, 3);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/media-upload', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'upload'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpab_direct_media_forbidden', 'Administrator capability is required.', ['status' => 403]);
        }
        return true;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest_response = rest_ensure_response($response);
        $data = $rest_response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('media_runtime_file_upload', $features, true)) {
            $features[] = 'media_runtime_file_upload';
        }
        $data['features'] = $features;
        $data['media_runtime_file_upload'] = [
            'route' => self::ROUTE,
            'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            'integrity' => ['expected_bytes', 'expected_sha256'],
            'source_cleanup' => true,
        ];
        $rest_response->set_data($data);
        return $rest_response;
    }

    public static function upload(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('wpab_direct_media_json', 'JSON body is required.', ['status' => 400]);
        }

        $data_path = isset($json['data_path']) && is_string($json['data_path']) ? trim($json['data_path']) : '';
        if (!preg_match('#^wordpress-bridge/media/pending/[A-Za-z0-9._-]{1,120}\.b64$#', $data_path)) {
            return new WP_Error('wpab_direct_media_path', 'data_path must point to wordpress-bridge/media/pending/<id>.b64.', ['status' => 400]);
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
            return new WP_Error(
                'wpab_direct_media_integrity_required',
                'expected_bytes and expected_sha256 are required for runtime media uploads.',
                ['status' => 400, 'max_decoded_bytes' => self::MAX_MEDIA_BYTES]
            );
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = isset($connection['repository']) ? trim((string) $connection['repository']) : '';
        $branch = isset($connection['runtime_branch']) ? (string) $connection['runtime_branch'] : '';
        if ($installation_id < 1 || $repository_id < 1 || $repository === '' || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return new WP_Error('wpab_direct_media_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $source = self::read_runtime_file($token, $repository, $branch, $data_path);
        if (is_wp_error($source)) {
            return $source;
        }

        $binary = self::decode_base64((string) $source['text']);
        if (is_wp_error($binary)) {
            return $binary;
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
            ]);
        }

        $uploaded = self::sideload($binary, $filename, $json);
        self::memzero($binary);
        if (is_wp_error($uploaded)) {
            return $uploaded;
        }

        $cleanup = TakKa_WordPress_Bridge_Direct_GitHub::delete_file(
            $token,
            $repository,
            $branch,
            $data_path,
            (string) $source['sha'],
            'WP Agent Bridge: remove uploaded media payload'
        );
        if (is_wp_error($cleanup)) {
            $uploaded['source_cleanup'] = false;
            $uploaded['source_cleanup_error'] = $cleanup->get_error_message();
        } else {
            $uploaded['source_cleanup'] = true;
        }
        $uploaded['source_path'] = $data_path;
        $uploaded['sha256'] = $actual_sha256;
        return rest_ensure_response($uploaded);
    }

    private static function read_runtime_file(string $token, string $repository, string $ref, string $path)
    {
        $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $ref, $path);
        if (is_wp_error($meta)) {
            return $meta;
        }
        $sha = isset($meta['sha']) && is_string($meta['sha']) ? strtolower(trim((string) $meta['sha'])) : '';
        if (!preg_match('/^[a-f0-9]{40,64}$/', $sha)) {
            return new WP_Error('wpab_direct_media_source_sha', 'GitHub did not return a valid source blob SHA.', ['status' => 502]);
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
        if ($encoded === '') {
            return new WP_Error('wpab_direct_media_source_content', 'GitHub media payload content is missing.', ['status' => 502]);
        }

        $text = base64_decode($encoded, true);
        if (!is_string($text)) {
            return new WP_Error('wpab_direct_media_source_decode', 'Could not decode the GitHub file content.', ['status' => 502]);
        }
        if (strlen($text) < 1 || strlen($text) > self::MAX_SOURCE_TEXT_BYTES) {
            return new WP_Error('wpab_direct_media_source_size', 'Runtime media payload file is empty or too large.', ['status' => 413, 'max_source_text_bytes' => self::MAX_SOURCE_TEXT_BYTES]);
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
            return new WP_Error('wpab_direct_media_size', 'Decoded media is empty or exceeds the size limit.', ['status' => 413, 'max_decoded_bytes' => self::MAX_MEDIA_BYTES]);
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
