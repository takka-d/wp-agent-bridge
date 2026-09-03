<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Chunked media transport for runtimes whose command JSON is limited to 2 MiB.
 *
 * The runtime sends Base64 in bounded chunks through the existing authenticated
 * Bridge `rest.call` action. Chunks are staged outside the public uploads tree,
 * verified individually, reassembled, verified against the original byte count
 * and SHA-256, then sideloaded into the WordPress media library.
 */
final class TakKa_WordPress_Bridge_Runtime_Media_Chunks
{
    private const NAMESPACE = 'wp-agent-bridge-media/v1';
    private const ROUTE = '/upload-chunk';
    private const MAX_MEDIA_BYTES = 6291456; // 6 MiB decoded.
    private const MAX_CHUNK_BYTES = 1200000; // Base64 + JSON remains comfortably below 2 MiB.
    private const MAX_CHUNKS = 32;
    private const STALE_SECONDS = 86400;
    private const COMPLETED_PREFIX = 'wpab_media_chunk_done_';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 550, 3);
    }

    public static function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'receive_chunk'],
            'permission_callback' => [self::class, 'permission'],
        ]);
    }

    public static function permission()
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('wpab_media_chunk_forbidden', 'Administrator capability is required.', ['status' => 403]);
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
        if (!in_array('media_runtime_chunk_upload', $features, true)) {
            $features[] = 'media_runtime_chunk_upload';
        }
        $data['features'] = $features;
        $data['media_runtime_chunk_upload'] = [
            'route' => '/' . self::NAMESPACE . self::ROUTE,
            'transport' => 'bridge.rest_call',
            'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            'max_chunk_decoded_bytes' => self::MAX_CHUNK_BYTES,
            'max_chunks' => self::MAX_CHUNKS,
            'integrity' => [
                'expected_bytes',
                'expected_sha256',
                'chunk_bytes',
                'chunk_sha256',
            ],
            'automatic_finalize' => true,
        ];
        $rest_response->set_data($data);
        return $rest_response;
    }

    public static function receive_chunk(WP_REST_Request $request)
    {
        self::cleanup_stale();

        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('wpab_media_chunk_json', 'JSON body is required.', ['status' => 400]);
        }

        $upload_id = isset($json['upload_id']) && is_string($json['upload_id']) ? trim($json['upload_id']) : '';
        if (!preg_match('/^[A-Za-z0-9._-]{1,120}$/', $upload_id)) {
            return new WP_Error('wpab_media_chunk_upload_id', 'upload_id must contain only letters, numbers, dot, underscore, or hyphen.', ['status' => 400]);
        }

        $completed_key = self::COMPLETED_PREFIX . hash('sha256', home_url('/') . "\n" . $upload_id);
        $completed = get_transient($completed_key);
        if (is_array($completed) && !empty($completed['ok'])) {
            $completed['duplicate'] = true;
            return rest_ensure_response($completed);
        }

        $chunk_index = isset($json['chunk_index']) ? (int) $json['chunk_index'] : -1;
        $chunk_count = isset($json['chunk_count']) ? (int) $json['chunk_count'] : 0;
        if ($chunk_count < 1 || $chunk_count > self::MAX_CHUNKS || $chunk_index < 0 || $chunk_index >= $chunk_count) {
            return new WP_Error('wpab_media_chunk_index', 'chunk_index/chunk_count are out of range.', [
                'status' => 400,
                'max_chunks' => self::MAX_CHUNKS,
            ]);
        }

        $filename = isset($json['filename']) && is_string($json['filename']) ? sanitize_file_name($json['filename']) : '';
        if ($filename === '' || strpos($filename, '.') === false) {
            return new WP_Error('wpab_media_chunk_filename', 'filename must include an allowed file extension.', ['status' => 400]);
        }

        $expected_bytes = isset($json['expected_bytes']) ? (int) $json['expected_bytes'] : 0;
        $expected_sha256 = isset($json['expected_sha256']) && is_string($json['expected_sha256'])
            ? strtolower(trim($json['expected_sha256']))
            : '';
        $chunk_bytes = isset($json['chunk_bytes']) ? (int) $json['chunk_bytes'] : 0;
        $chunk_sha256 = isset($json['chunk_sha256']) && is_string($json['chunk_sha256'])
            ? strtolower(trim($json['chunk_sha256']))
            : '';

        if ($expected_bytes < 1 || $expected_bytes > self::MAX_MEDIA_BYTES || !preg_match('/^[a-f0-9]{64}$/', $expected_sha256)) {
            return new WP_Error('wpab_media_chunk_integrity', 'expected_bytes and expected_sha256 are required and must describe the original file.', [
                'status' => 400,
                'max_decoded_bytes' => self::MAX_MEDIA_BYTES,
            ]);
        }
        if ($chunk_bytes < 1 || $chunk_bytes > self::MAX_CHUNK_BYTES || !preg_match('/^[a-f0-9]{64}$/', $chunk_sha256)) {
            return new WP_Error('wpab_media_chunk_integrity_chunk', 'chunk_bytes and chunk_sha256 are required for every chunk.', [
                'status' => 400,
                'max_chunk_decoded_bytes' => self::MAX_CHUNK_BYTES,
            ]);
        }

        $data_b64 = isset($json['data_b64']) && is_string($json['data_b64']) ? $json['data_b64'] : '';
        $binary = self::decode_base64($data_b64);
        if (is_wp_error($binary)) {
            return $binary;
        }

        $actual_chunk_bytes = strlen($binary);
        $actual_chunk_sha256 = hash('sha256', $binary);
        if ($actual_chunk_bytes !== $chunk_bytes || !hash_equals($chunk_sha256, $actual_chunk_sha256)) {
            self::memzero($binary);
            return new WP_Error('wpab_media_chunk_mismatch', 'Chunk does not match its declared byte count or SHA-256.', [
                'status' => 409,
                'chunk_index' => $chunk_index,
                'expected_chunk_bytes' => $chunk_bytes,
                'actual_chunk_bytes' => $actual_chunk_bytes,
                'expected_chunk_sha256' => $chunk_sha256,
                'actual_chunk_sha256' => $actual_chunk_sha256,
            ]);
        }

        $dir = self::staging_dir();
        if (is_wp_error($dir)) {
            self::memzero($binary);
            return $dir;
        }
        $key = hash('sha256', home_url('/') . "\n" . $upload_id);
        $meta_path = $dir . '/' . $key . '.json';
        $part_path = $dir . '/' . $key . '.' . $chunk_index . '.part';
        $lock_path = $dir . '/' . $key . '.lock';

        $lock = fopen($lock_path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            self::memzero($binary);
            if (is_resource($lock)) fclose($lock);
            return new WP_Error('wpab_media_chunk_lock', 'Could not lock the staged upload.', ['status' => 503]);
        }

        try {
            $metadata = self::metadata_from_json($json, $upload_id, $filename, $chunk_count, $expected_bytes, $expected_sha256);
            $existing_meta = self::read_meta($meta_path);
            if (is_wp_error($existing_meta)) {
                self::memzero($binary);
                return $existing_meta;
            }
            if (is_array($existing_meta)) {
                if (!hash_equals(hash('sha256', wp_json_encode($existing_meta)), hash('sha256', wp_json_encode($metadata)))) {
                    self::memzero($binary);
                    return new WP_Error('wpab_media_chunk_metadata_conflict', 'Upload metadata changed between chunks. Use a new upload_id.', ['status' => 409]);
                }
            } else {
                $written_meta = file_put_contents($meta_path, wp_json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
                if ($written_meta === false) {
                    self::memzero($binary);
                    return new WP_Error('wpab_media_chunk_meta_write', 'Could not write staged upload metadata.', ['status' => 500]);
                }
            }

            if (is_file($part_path)) {
                $existing_bytes = filesize($part_path);
                $existing_sha = hash_file('sha256', $part_path);
                if ($existing_bytes !== $actual_chunk_bytes || !is_string($existing_sha) || !hash_equals($actual_chunk_sha256, $existing_sha)) {
                    self::memzero($binary);
                    return new WP_Error('wpab_media_chunk_existing_conflict', 'A different payload is already staged for this chunk index.', [
                        'status' => 409,
                        'chunk_index' => $chunk_index,
                    ]);
                }
            } else {
                if (file_put_contents($part_path, $binary, LOCK_EX) === false) {
                    self::memzero($binary);
                    return new WP_Error('wpab_media_chunk_write', 'Could not write staged media chunk.', ['status' => 500]);
                }
            }
            self::memzero($binary);

            $received = self::received_indices($dir, $key, $chunk_count);
            if (count($received) < $chunk_count) {
                return rest_ensure_response([
                    'ok' => true,
                    'staged' => true,
                    'upload_id' => $upload_id,
                    'chunk_index' => $chunk_index,
                    'chunk_count' => $chunk_count,
                    'received_chunks' => $received,
                    'remaining_chunks' => $chunk_count - count($received),
                ]);
            }

            $uploaded = self::finalize($dir, $key, $metadata);
            if (is_wp_error($uploaded)) {
                return $uploaded;
            }
            set_transient($completed_key, $uploaded, DAY_IN_SECONDS);
            self::cleanup_upload($dir, $key, $chunk_count);
            return rest_ensure_response($uploaded);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lock_path);
        }
    }

    private static function metadata_from_json(array $json, string $upload_id, string $filename, int $chunk_count, int $expected_bytes, string $expected_sha256): array
    {
        $metadata = [
            'upload_id' => $upload_id,
            'filename' => $filename,
            'chunk_count' => $chunk_count,
            'expected_bytes' => $expected_bytes,
            'expected_sha256' => $expected_sha256,
            'post_id' => isset($json['post_id']) ? absint($json['post_id']) : 0,
        ];
        foreach (['title', 'caption', 'description', 'alt_text'] as $field) {
            $metadata[$field] = isset($json[$field]) && is_string($json[$field]) ? $json[$field] : '';
        }
        return $metadata;
    }

    private static function read_meta(string $path)
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return new WP_Error('wpab_media_chunk_meta_invalid', 'Staged upload metadata is invalid.', ['status' => 500]);
        }
        return $data;
    }

    private static function received_indices(string $dir, string $key, int $count): array
    {
        $received = [];
        for ($i = 0; $i < $count; $i++) {
            if (is_file($dir . '/' . $key . '.' . $i . '.part')) {
                $received[] = $i;
            }
        }
        return $received;
    }

    private static function finalize(string $dir, string $key, array $meta)
    {
        $final = $dir . '/' . $key . '.assembled';
        $out = fopen($final, 'wb');
        if ($out === false) {
            return new WP_Error('wpab_media_chunk_assemble_open', 'Could not create the assembled media file.', ['status' => 500]);
        }
        $hash = hash_init('sha256');
        $total = 0;
        try {
            for ($i = 0; $i < (int) $meta['chunk_count']; $i++) {
                $part = $dir . '/' . $key . '.' . $i . '.part';
                $in = fopen($part, 'rb');
                if ($in === false) {
                    return new WP_Error('wpab_media_chunk_missing', 'A staged chunk disappeared before finalization.', ['status' => 409, 'chunk_index' => $i]);
                }
                while (!feof($in)) {
                    $buffer = fread($in, 262144);
                    if ($buffer === false) {
                        fclose($in);
                        return new WP_Error('wpab_media_chunk_read', 'Could not read a staged media chunk.', ['status' => 500, 'chunk_index' => $i]);
                    }
                    if ($buffer === '') {
                        continue;
                    }
                    $length = strlen($buffer);
                    $total += $length;
                    if ($total > self::MAX_MEDIA_BYTES || fwrite($out, $buffer) !== $length) {
                        fclose($in);
                        return new WP_Error('wpab_media_chunk_assemble_write', 'Assembled media exceeds the limit or could not be written.', ['status' => 413]);
                    }
                    hash_update($hash, $buffer);
                }
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $actual_sha256 = hash_final($hash);
        if ($total !== (int) $meta['expected_bytes'] || !hash_equals((string) $meta['expected_sha256'], $actual_sha256)) {
            @unlink($final);
            return new WP_Error('wpab_media_chunk_final_integrity', 'Reassembled media does not match the original byte count or SHA-256.', [
                'status' => 409,
                'expected_bytes' => (int) $meta['expected_bytes'],
                'actual_bytes' => $total,
                'expected_sha256' => (string) $meta['expected_sha256'],
                'actual_sha256' => $actual_sha256,
            ]);
        }

        $uploaded = self::sideload_file($final, $meta);
        if (is_wp_error($uploaded)) {
            @unlink($final);
            return $uploaded;
        }
        $uploaded['upload_id'] = (string) $meta['upload_id'];
        $uploaded['chunk_count'] = (int) $meta['chunk_count'];
        $uploaded['sha256'] = $actual_sha256;
        $uploaded['source_cleanup'] = true;
        return $uploaded;
    }

    private static function sideload_file(string $path, array $meta)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $file_array = [
            'name' => (string) $meta['filename'],
            'tmp_name' => $path,
        ];
        $description = (string) ($meta['description'] ?? '');
        $attachment_id = media_handle_sideload($file_array, (int) ($meta['post_id'] ?? 0), $description !== '' ? $description : null);
        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        if ((string) ($meta['alt_text'] ?? '') !== '') {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $meta['alt_text']));
        }
        $post_update = ['ID' => $attachment_id];
        foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $input => $field) {
            if ((string) ($meta[$input] ?? '') !== '') {
                $post_update[$field] = (string) $meta[$input];
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
            'bytes' => (int) $meta['expected_bytes'],
        ];
    }

    private static function decode_base64(string $value)
    {
        $value = trim($value);
        if (preg_match('#^data:[^,]*;base64,#i', $value, $matches)) {
            $value = substr($value, strlen($matches[0]));
        }
        $value = preg_replace('/\s+/', '', $value);
        if (!is_string($value) || $value === '') {
            return new WP_Error('wpab_media_chunk_base64_empty', 'Base64 media chunk is empty.', ['status' => 400]);
        }
        $value = strtr($value, '-_', '+/');
        if (preg_match('/[^A-Za-z0-9+\/=]/', $value)) {
            return new WP_Error('wpab_media_chunk_base64_chars', 'Base64 media chunk contains invalid characters.', ['status' => 400]);
        }
        $unpadded = rtrim($value, '=');
        $existing_padding = strlen($value) - strlen($unpadded);
        if ($existing_padding > 2 || strpos($unpadded, '=') !== false) {
            return new WP_Error('wpab_media_chunk_base64_padding', 'Base64 media chunk has invalid padding.', ['status' => 400]);
        }
        $mod = strlen($unpadded) % 4;
        if ($mod === 1) {
            return new WP_Error('wpab_media_chunk_base64_length', 'Base64 media chunk has an invalid length.', ['status' => 400]);
        }
        $normalized = $unpadded . str_repeat('=', (4 - $mod) % 4);
        $binary = base64_decode($normalized, true);
        if (!is_string($binary)) {
            return new WP_Error('wpab_media_chunk_base64_decode', 'Base64 media chunk could not be decoded.', ['status' => 400]);
        }
        if (strlen($binary) < 1 || strlen($binary) > self::MAX_CHUNK_BYTES) {
            self::memzero($binary);
            return new WP_Error('wpab_media_chunk_size', 'Decoded media chunk is empty or exceeds the chunk limit.', [
                'status' => 413,
                'max_chunk_decoded_bytes' => self::MAX_CHUNK_BYTES,
            ]);
        }
        return $binary;
    }

    private static function staging_dir()
    {
        $base = rtrim(get_temp_dir(), '/\\') . '/wp-agent-bridge-media';
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            return new WP_Error('wpab_media_chunk_staging_dir', 'Could not create the media staging directory.', ['status' => 500]);
        }
        return $base;
    }

    private static function cleanup_upload(string $dir, string $key, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            @unlink($dir . '/' . $key . '.' . $i . '.part');
        }
        @unlink($dir . '/' . $key . '.json');
        @unlink($dir . '/' . $key . '.assembled');
    }

    private static function cleanup_stale(): void
    {
        $dir = rtrim(get_temp_dir(), '/\\') . '/wp-agent-bridge-media';
        if (!is_dir($dir)) {
            return;
        }
        $cutoff = time() - self::STALE_SECONDS;
        $entries = glob($dir . '/*');
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $path) {
            $mtime = @filemtime($path);
            if (is_file($path) && is_int($mtime) && $mtime < $cutoff) {
                @unlink($path);
            }
        }
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
