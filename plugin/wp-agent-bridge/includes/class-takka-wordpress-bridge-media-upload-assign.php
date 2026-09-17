<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Upload a small inline media object and assign it as featured media with one
 * guarded Bridge operation. If assignment cannot be completed, the newly
 * created attachment is removed; a field conflict after upload never overwrites
 * the newer featured-media choice.
 */
final class TakKa_WordPress_Bridge_Media_Upload_Assign
{
    private const ROUTE = '/takka-v099/v1/operate';
    private const HEALTH = '/takka-bridge/v1/health';
    private const OPERATION = 'media.upload_and_assign.inline';
    private const MAX_INLINE_BYTES = 1048576;

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 76, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_contract'], 677, 3);
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null
            || $request->get_route() !== self::ROUTE
            || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        $json = $request->get_json_params();
        if (!is_array($json) || ($json['operation'] ?? '') !== self::OPERATION) {
            return $result;
        }

        $permission = TakKa_WordPress_Bridge_V099_Operations::permission();
        if (is_wp_error($permission)) {
            return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation($permission, [], $request);
        }
        if ($permission !== true) {
            $denied = new WP_Error('wpab_media_assign_internal_only', 'Upload-and-assign is only callable through the signed Bridge operation path.', [
                'status' => 403,
                'side_effects' => false,
            ]);
            return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation($denied, [], $request);
        }

        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        $handled = self::execute($params);
        $response = is_wp_error($handled)
            ? $handled
            : rest_ensure_response([
                'ok' => true,
                'operation' => self::OPERATION,
                'status' => 200,
                'result' => ['ok' => true, 'status' => 200, 'data' => $handled],
                'side_effects' => true,
            ]);
        return TakKa_WordPress_Bridge_V099_Response_Contract::normalize_operation($response, [], $request);
    }

    public static function annotate_contract($response, array $handler, WP_REST_Request $request)
    {
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        if ($request->get_route() === self::ROUTE) {
            $json = $request->get_json_params();
            if (is_array($json) && ($json['operation'] ?? '') === 'catalog') {
                $ops = isset($data['operations']) && is_array($data['operations']) ? $data['operations'] : [];
                if (!in_array(self::OPERATION, $ops, true)) {
                    $ops[] = self::OPERATION;
                }
                $data['operations'] = $ops;
                $data['media_upload_and_assign_inline'] = self::contract();
                $rest->set_data($data);
                return $rest;
            }
        }

        if ($request->get_route() === self::HEALTH) {
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            foreach (['media_inline_upload_assign', 'media_assignment_cleanup_on_failure'] as $feature) {
                if (!in_array($feature, $features, true)) {
                    $features[] = $feature;
                }
            }
            $data['features'] = $features;
            $data['media_upload_and_assign_inline'] = self::contract();
            $rest->set_data($data);
            return $rest;
        }

        return $response;
    }

    public static function contract(): array
    {
        return [
            'operation' => self::OPERATION,
            'purpose' => 'small_inline_upload_plus_featured_media_assignment',
            'max_decoded_bytes' => self::MAX_INLINE_BYTES,
            'requires' => ['confirm', 'post_id|target_url', 'expected_featured_media_sha256', 'filename', 'data_b64'],
            'recommended_integrity' => ['expected_bytes', 'expected_sha256'],
            'non_draft_requires_confirm_live' => true,
            'rechecks_featured_media_after_upload_before_assignment' => true,
            'assignment_conflict_status' => 409,
            'new_attachment_cleanup_on_assignment_failure' => true,
            'cleanup_never_restores_over_a_post_upload_field_conflict' => true,
            'larger_files_use_staged_media_then_guarded_post_update' => true,
        ];
    }

    private static function execute(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('wpab_media_assign_confirmation', 'Upload-and-assign requires confirm=true.', ['status' => 400, 'side_effects' => false]);
        }
        $post = self::resolve_post($params);
        if (is_wp_error($post)) {
            return $post;
        }
        if (!current_user_can('edit_post', $post->ID) || !current_user_can('upload_files')) {
            return new WP_Error('wpab_media_assign_forbidden', 'Connected user cannot edit the target or upload media.', ['status' => 403, 'side_effects' => false]);
        }
        if (!in_array((string) $post->post_status, ['draft', 'pending', 'auto-draft'], true) && empty($params['confirm_live'])) {
            return new WP_Error('wpab_media_assign_live_confirmation', 'Assigning featured media to a non-draft post requires confirm_live=true.', [
                'status' => 400,
                'status_value' => (string) $post->post_status,
                'side_effects' => false,
            ]);
        }

        $expected_field_hash = isset($params['expected_featured_media_sha256']) && is_string($params['expected_featured_media_sha256'])
            ? strtolower(trim($params['expected_featured_media_sha256']))
            : '';
        if (!preg_match('/^[a-f0-9]{64}$/D', $expected_field_hash)) {
            return new WP_Error('wpab_media_assign_field_hash', 'expected_featured_media_sha256 must come from post.get concurrency.field_hashes.featured_media.', [
                'status' => 400,
                'side_effects' => false,
            ]);
        }
        $before_id = (int) get_post_thumbnail_id($post->ID);
        $before_hash = self::value_hash($before_id);
        if (!hash_equals($expected_field_hash, $before_hash)) {
            return new WP_Error('wpab_media_assign_featured_media_conflict', 'Featured media changed after the client read it; upload was not attempted.', [
                'status' => 409,
                'expected_sha256' => $expected_field_hash,
                'current_sha256' => $before_hash,
                'current_featured_media' => $before_id,
                'side_effects' => false,
            ]);
        }

        $prepared = self::validate_media($params);
        if (is_wp_error($prepared)) {
            return $prepared;
        }
        $upload_params = $prepared['upload_params'];
        $upload_params['post_id'] = (int) $post->ID;
        $uploaded = self::upload_v04($upload_params);
        if (is_wp_error($uploaded)) {
            $data = $uploaded->get_error_data();
            $data = is_array($data) ? $data : [];
            $data['status'] = isset($data['status']) ? (int) $data['status'] : 500;
            $data['side_effects'] = null;
            return new WP_Error($uploaded->get_error_code(), $uploaded->get_error_message(), $data);
        }
        $attachment_id = isset($uploaded['id']) ? absint($uploaded['id']) : 0;
        if ($attachment_id < 1 || get_post_type($attachment_id) !== 'attachment') {
            return new WP_Error('wpab_media_assign_upload_result', 'Media upload did not return a valid attachment ID.', [
                'status' => 500,
                'side_effects' => true,
            ]);
        }

        // Re-check after upload. A human/plugin or another non-Bridge actor may
        // have changed the field while media processing was running. Do not
        // overwrite that newer choice merely because the upload succeeded.
        $current_id = (int) get_post_thumbnail_id($post->ID);
        $current_hash = self::value_hash($current_id);
        if (!hash_equals($before_hash, $current_hash)) {
            $cleanup = self::delete_new_attachment($attachment_id);
            return new WP_Error('wpab_media_assign_featured_media_conflict_after_upload', 'Featured media changed while the new attachment was uploading. The Bridge did not assign over it.', [
                'status' => 409,
                'previous_featured_media' => $before_id,
                'current_featured_media' => $current_id,
                'uploaded_attachment_id' => $attachment_id,
                'cleanup' => $cleanup,
                'durable_uploaded_attachment_deleted' => !empty($cleanup['deleted']),
                'side_effects' => true,
            ]);
        }

        $set = set_post_thumbnail((int) $post->ID, $attachment_id);
        $actual_id = (int) get_post_thumbnail_id($post->ID);
        if (!$set || $actual_id !== $attachment_id) {
            $restore = self::restore_featured_media((int) $post->ID, $before_id);
            $cleanup = self::delete_new_attachment($attachment_id);
            return new WP_Error('wpab_media_assign_failed', 'New attachment was uploaded but featured-media assignment failed.', [
                'status' => 500,
                'uploaded_attachment_id' => $attachment_id,
                'previous_featured_media' => $before_id,
                'actual_featured_media' => $actual_id,
                'previous_featured_media_restored' => $restore,
                'cleanup' => $cleanup,
                'durable_uploaded_attachment_deleted' => !empty($cleanup['deleted']),
                'side_effects' => true,
            ]);
        }

        return [
            'post_id' => (int) $post->ID,
            'previous_featured_media' => $before_id,
            'featured_media' => $attachment_id,
            'featured_media_sha256' => self::value_hash($attachment_id),
            'attachment' => $uploaded,
            'integrity' => [
                'bytes' => $prepared['bytes'],
                'sha256' => $prepared['sha256'],
            ],
            'side_effects' => true,
        ];
    }

    private static function validate_media(array $params)
    {
        $filename = isset($params['filename']) && is_string($params['filename']) ? trim($params['filename']) : '';
        $data_b64 = isset($params['data_b64']) && is_string($params['data_b64']) ? trim($params['data_b64']) : '';
        if ($filename === '' || $data_b64 === '') {
            return new WP_Error('wpab_media_assign_payload', 'filename and data_b64 are required.', ['status' => 400, 'side_effects' => false]);
        }
        $binary = base64_decode($data_b64, true);
        if (!is_string($binary)) {
            return new WP_Error('wpab_media_assign_base64', 'data_b64 is invalid Base64.', ['status' => 400, 'side_effects' => false]);
        }
        $bytes = strlen($binary);
        if ($bytes < 1 || $bytes > self::MAX_INLINE_BYTES) {
            return new WP_Error('wpab_media_assign_inline_size', 'Decoded media is empty or exceeds the 1 MiB integrated inline path. Use staged media then guarded post.update for larger files.', [
                'status' => 413,
                'bytes' => $bytes,
                'inline_max_bytes' => self::MAX_INLINE_BYTES,
                'side_effects' => false,
            ]);
        }
        $sha = hash('sha256', $binary);
        if (isset($params['expected_bytes']) && (int) $params['expected_bytes'] !== $bytes) {
            return new WP_Error('wpab_media_assign_bytes', 'Decoded media byte count does not match expected_bytes.', [
                'status' => 409,
                'expected_bytes' => (int) $params['expected_bytes'],
                'actual_bytes' => $bytes,
                'side_effects' => false,
            ]);
        }
        if (isset($params['expected_sha256']) && is_string($params['expected_sha256']) && trim($params['expected_sha256']) !== '') {
            $expected = strtolower(trim($params['expected_sha256']));
            if (!preg_match('/^[a-f0-9]{64}$/D', $expected)) {
                return new WP_Error('wpab_media_assign_sha_format', 'expected_sha256 must be a SHA-256 hex digest.', ['status' => 400, 'side_effects' => false]);
            }
            if (!hash_equals($expected, $sha)) {
                return new WP_Error('wpab_media_assign_sha', 'Decoded media SHA-256 does not match expected_sha256.', [
                    'status' => 409,
                    'expected_sha256' => $expected,
                    'actual_sha256' => $sha,
                    'side_effects' => false,
                ]);
            }
        }

        $allowed = ['filename', 'data_b64', 'alt_text', 'title', 'caption', 'description'];
        $upload_params = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $params)) {
                $upload_params[$key] = $params[$key];
            }
        }
        return [
            'bytes' => $bytes,
            'sha256' => $sha,
            'upload_params' => $upload_params,
        ];
    }

    private static function upload_v04(array $params)
    {
        $payload = wp_json_encode([
            'action' => 'media.upload_base64',
            'params' => $params,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($payload)) {
            return new WP_Error('wpab_media_assign_encode', 'Could not encode the internal media upload request.', ['status' => 500]);
        }
        $body = wp_json_encode(['payload_b64' => base64_encode($payload)], JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return new WP_Error('wpab_media_assign_encode', 'Could not encode the internal media management envelope.', ['status' => 500]);
        }
        $request = new WP_REST_Request('POST', '/takka-bridge/v1/manage');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body($body);
        $response = TakKa_WordPress_Bridge_V04::manage($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $data = rest_ensure_response($response)->get_data();
        return is_array($data)
            ? $data
            : new WP_Error('wpab_media_assign_upload_response', 'Internal media upload returned an invalid response.', ['status' => 500]);
    }

    private static function restore_featured_media(int $post_id, int $before_id): bool
    {
        if ($before_id > 0) {
            set_post_thumbnail($post_id, $before_id);
            return (int) get_post_thumbnail_id($post_id) === $before_id;
        }
        delete_post_thumbnail($post_id);
        return (int) get_post_thumbnail_id($post_id) === 0;
    }

    private static function delete_new_attachment(int $attachment_id): array
    {
        $deleted = wp_delete_attachment($attachment_id, true);
        return [
            'attachment_id' => $attachment_id,
            'deleted' => $deleted !== false && get_post($attachment_id) === null,
        ];
    }

    private static function resolve_post(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $target_url = isset($params['target_url']) && is_string($params['target_url']) ? trim($params['target_url']) : '';
        if ($target_url !== '') {
            $parts = wp_parse_url($target_url);
            $home = wp_parse_url(home_url('/'));
            if (!is_array($parts) || !is_array($home)
                || empty($parts['host'])
                || strtolower((string) $parts['host']) !== strtolower((string) ($home['host'] ?? ''))
                || isset($parts['user']) || isset($parts['pass'])) {
                return new WP_Error('wpab_media_assign_target_url', 'target_url must resolve to this WordPress site without credentials.', ['status' => 400, 'side_effects' => false]);
            }
            $resolved = (int) url_to_postid($target_url);
            if ($resolved < 1) {
                return new WP_Error('wpab_media_assign_target_missing', 'target_url did not resolve to a post or page.', ['status' => 404, 'side_effects' => false]);
            }
            if ($post_id > 0 && $post_id !== $resolved) {
                return new WP_Error('wpab_media_assign_target_conflict', 'post_id and target_url identify different objects.', [
                    'status' => 409,
                    'post_id' => $post_id,
                    'resolved_post_id' => $resolved,
                    'side_effects' => false,
                ]);
            }
            $post_id = $resolved;
        }
        if ($post_id < 1) {
            return new WP_Error('wpab_media_assign_post_id', 'post_id or target_url is required.', ['status' => 400, 'side_effects' => false]);
        }
        $post = get_post($post_id);
        if (!$post || !in_array($post->post_type, ['post', 'page'], true) || $post->post_status === 'trash') {
            return new WP_Error('wpab_media_assign_post_missing', 'Post or page was not found.', ['status' => 404, 'side_effects' => false]);
        }
        return $post;
    }

    private static function value_hash($value): string
    {
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        return hash('sha256', is_string($encoded) ? $encoded : 'null');
    }
}
