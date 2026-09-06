<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.9.6 explicit site-icon/media task surface.
 *
 * WordPress stores the Site Icon as a scalar attachment ID in the site_icon
 * option. The generic safe option patcher intentionally only patches nested
 * array values, so favicon changes need a dedicated guarded action.
 */
final class TakKa_WordPress_Bridge_V096_Site
{
    private const VERSION = '0.9.6';
    private const NS = 'takka-v096/v1';
    private const ROUTE = '/takka-v096/v1/manage';
    private const OUTER = '/takka-bridge/v1/execute';
    private const HEALTH = '/takka-bridge/v1/health';
    private const SECRET = 'takka_bridge_secret';
    private const USER = 'takka_bridge_user_id';
    private const SKEW = 300;
    private const MAX_RUNTIME_MEDIA_BYTES = 6291456;
    private const MAX_RUNTIME_MEDIA_CHUNKS = 32;

    private static $allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v096.capabilities',
        'media.upload.capabilities',
        'site.icon.get',
        'site.icon.set',
        'site.icon.clear',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare'], 67, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'health'], 405, 3);
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
                'takka_bridge_v096_internal_only',
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
            return new WP_Error('takka_bridge_v096_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = is_string($json['action'] ?? null) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v096_action', 'Unknown or blocked v0.9.6 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v096.capabilities':
                    $response = rest_ensure_response(self::capabilities());
                    break;
                case 'media.upload.capabilities':
                    $response = rest_ensure_response(self::media_capabilities());
                    break;
                case 'site.icon.get':
                    $response = rest_ensure_response(self::icon_state());
                    break;
                case 'site.icon.set':
                    $response = self::set_icon($params);
                    break;
                case 'site.icon.clear':
                    $response = self::clear_icon($params);
                    break;
                default:
                    $response = new WP_Error('takka_bridge_v096_dispatch', 'Dispatch fell through.', ['status' => 500]);
            }
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v096_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }

        if (in_array($action, ['site.icon.set', 'site.icon.clear'], true)
            && class_exists('TakKa_WordPress_Bridge_V07_Audit')) {
            TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, self::audit_params($params), $response);
        }
        return $response;
    }

    public static function set_icon(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_v096_confirmation_required', 'site.icon.set requires confirm=true.', ['status' => 400]);
        }
        $attachment_id = isset($params['attachment_id']) ? absint($params['attachment_id']) : 0;
        if ($attachment_id < 1) {
            return new WP_Error('takka_bridge_v096_attachment_required', 'attachment_id is required.', ['status' => 400]);
        }

        $attachment = self::attachment_state($attachment_id);
        if (is_wp_error($attachment)) {
            return $attachment;
        }
        $current = (int) get_option('site_icon', 0);
        $expected = isset($params['expected_current_id']) ? (int) $params['expected_current_id'] : null;
        if ($expected !== null && $expected !== $current) {
            return new WP_Error('takka_bridge_v096_site_icon_changed', 'Site Icon changed after it was inspected.', [
                'status' => 409,
                'expected_current_id' => $expected,
                'current_id' => $current,
            ]);
        }

        $previous = self::icon_state();
        if ($current !== $attachment_id) {
            update_option('site_icon', $attachment_id, false);
        }
        $actual = (int) get_option('site_icon', 0);
        if ($actual !== $attachment_id) {
            return new WP_Error('takka_bridge_v096_site_icon_verify_failed', 'WordPress did not retain the requested Site Icon attachment ID.', [
                'status' => 500,
                'requested_id' => $attachment_id,
                'actual_id' => $actual,
            ]);
        }

        $state = self::icon_state();
        $state['ok'] = true;
        $state['changed'] = $current !== $attachment_id;
        $state['previous'] = $previous;
        $state['attachment'] = $attachment;
        return rest_ensure_response($state);
    }

    public static function clear_icon(array $params)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_v096_confirmation_required', 'site.icon.clear requires confirm=true.', ['status' => 400]);
        }
        $current = (int) get_option('site_icon', 0);
        $expected = isset($params['expected_current_id']) ? (int) $params['expected_current_id'] : null;
        if ($expected !== null && $expected !== $current) {
            return new WP_Error('takka_bridge_v096_site_icon_changed', 'Site Icon changed after it was inspected.', [
                'status' => 409,
                'expected_current_id' => $expected,
                'current_id' => $current,
            ]);
        }
        $previous = self::icon_state();
        if ($current > 0) {
            delete_option('site_icon');
        }
        $actual = (int) get_option('site_icon', 0);
        if ($actual !== 0) {
            return new WP_Error('takka_bridge_v096_site_icon_clear_verify_failed', 'WordPress did not clear the Site Icon option.', [
                'status' => 500,
                'actual_id' => $actual,
            ]);
        }
        return rest_ensure_response([
            'ok' => true,
            'changed' => $current > 0,
            'current' => self::icon_state(),
            'previous' => $previous,
            'attachment_deleted' => false,
        ]);
    }

    /**
     * Called by Direct Media after a successful upload when the upload command
     * explicitly requests favicon assignment.
     */
    public static function set_uploaded_attachment(int $attachment_id, array $params)
    {
        $set = [
            'attachment_id' => $attachment_id,
            'confirm' => !empty($params['confirm_site_icon']),
        ];
        if (array_key_exists('expected_site_icon_id', $params)) {
            $set['expected_current_id'] = (int) $params['expected_site_icon_id'];
        }
        return self::set_icon($set);
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
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'explicit_site_icon_management',
            'site_icon_assignment_during_runtime_media_upload',
            'local_and_connector_media_staging_guidance',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $data['site_icon'] = [
            'route' => self::ROUTE,
            'actions' => ['site.icon.get', 'site.icon.set', 'site.icon.clear'],
            'upload_and_set_supported' => true,
        ];
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'site_icon' => [
                'stored_as_attachment_id' => true,
                'get' => 'site.icon.get',
                'set' => 'site.icon.set',
                'clear' => 'site.icon.clear',
                'set_requires_confirm' => true,
                'clear_requires_confirm' => true,
                'stale_write_guard' => 'expected_current_id',
                'attachment_is_not_deleted_when_cleared' => true,
            ],
            'runtime_media' => self::media_capabilities(),
        ];
    }

    private static function media_capabilities(): array
    {
        return [
            'route' => '/wp-agent-bridge-runtime/v1/media-upload',
            'source_kinds' => [
                'chatgpt_local_file',
                'conversation_attachment',
                'connector_downloaded_file',
                'google_drive_file_after_agent_retrieval',
                'github_staged_binary',
            ],
            'transport' => 'ordered_base64_data_paths',
            'max_decoded_bytes' => self::MAX_RUNTIME_MEDIA_BYTES,
            'max_chunks' => self::MAX_RUNTIME_MEDIA_CHUNKS,
            'one_upload_command_after_staging' => true,
            'git_local_file_parameter_required' => false,
            'set_site_icon_during_upload' => true,
            'set_site_icon_fields' => ['set_site_icon', 'confirm_site_icon', 'expected_site_icon_id'],
            'fallback_route' => '/wp-agent-bridge-media/v1/upload-chunk',
        ];
    }

    private static function icon_state(): array
    {
        $id = (int) get_option('site_icon', 0);
        $state = [
            'attachment_id' => $id,
            'configured' => $id > 0,
            'icon_url_32' => function_exists('get_site_icon_url') ? get_site_icon_url(32) : '',
            'icon_url_192' => function_exists('get_site_icon_url') ? get_site_icon_url(192) : '',
            'icon_url_512' => function_exists('get_site_icon_url') ? get_site_icon_url(512) : '',
            'attachment' => null,
        ];
        if ($id > 0) {
            $attachment = self::attachment_state($id);
            if (!is_wp_error($attachment)) {
                $state['attachment'] = $attachment;
            } else {
                $state['attachment_error'] = $attachment->get_error_message();
            }
        }
        return $state;
    }

    private static function attachment_state(int $attachment_id)
    {
        $post = get_post($attachment_id);
        if (!$post || $post->post_type !== 'attachment') {
            return new WP_Error('takka_bridge_v096_attachment_not_found', 'Attachment was not found.', [
                'status' => 404,
                'attachment_id' => $attachment_id,
            ]);
        }
        if (!wp_attachment_is_image($attachment_id)) {
            return new WP_Error('takka_bridge_v096_attachment_not_image', 'Site Icon must reference an image attachment.', [
                'status' => 400,
                'attachment_id' => $attachment_id,
                'mime_type' => get_post_mime_type($attachment_id),
            ]);
        }
        $file = get_attached_file($attachment_id);
        if (!is_string($file) || $file === '' || !is_file($file)) {
            return new WP_Error('takka_bridge_v096_attachment_file_missing', 'Image attachment file is missing.', [
                'status' => 410,
                'attachment_id' => $attachment_id,
            ]);
        }
        $meta = wp_get_attachment_metadata($attachment_id);
        $width = is_array($meta) && isset($meta['width']) ? (int) $meta['width'] : 0;
        $height = is_array($meta) && isset($meta['height']) ? (int) $meta['height'] : 0;
        if (($width < 1 || $height < 1) && function_exists('wp_getimagesize')) {
            $size = wp_getimagesize($file);
            if (is_array($size)) {
                $width = (int) ($size[0] ?? 0);
                $height = (int) ($size[1] ?? 0);
            }
        }
        return [
            'id' => $attachment_id,
            'title' => get_the_title($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'url' => wp_get_attachment_url($attachment_id),
            'filename' => basename($file),
            'bytes' => (int) filesize($file),
            'width' => $width,
            'height' => $height,
            'square' => $width > 0 && $height > 0 ? $width === $height : null,
            'recommended_512_or_larger' => $width > 0 && $height > 0 ? ($width >= 512 && $height >= 512) : null,
        ];
    }

    private static function audit_params(array $params): array
    {
        $out = [];
        foreach (['attachment_id', 'expected_current_id', 'confirm'] as $key) {
            if (array_key_exists($key, $params)) {
                $out[$key] = $params[$key];
            }
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
