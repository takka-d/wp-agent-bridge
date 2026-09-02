<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V083
{
    private const VERSION = '0.8.3';
    private const INTERNAL_NAMESPACE = 'takka-v083/v1';
    private const INTERNAL_ROUTE = '/takka-v083/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v083.capabilities',
        'post.meta.list', 'post.meta.get', 'post.meta.add', 'post.meta.update', 'post.meta.delete',
    ];

    private const AUDITED = [
        'post.meta.add', 'post.meta.update', 'post.meta.delete',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_filter('rest_pre_dispatch', [self::class, 'block_legacy_post_meta_actions'], 23, 3);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 45, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 320, 3);
    }

    public static function register_route(): void
    {
        register_rest_route(self::INTERNAL_NAMESPACE, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'internal_permission'],
        ]);
    }

    public static function block_legacy_post_meta_actions($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') return $result;
        if (!self::valid_outer_hmac($request)) return $result;
        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner)) return $result;
        $action = isset($inner['action']) && is_string($inner['action']) ? trim($inner['action']) : '';
        if (!in_array($action, ['post_meta.get', 'post_meta.update', 'post_meta.delete'], true)) return $result;

        return new WP_REST_Response([
            'code' => 'takka_bridge_legacy_post_meta_action_disabled',
            'message' => 'Legacy post_meta.* actions are disabled. Use the protected post.meta.* actions through /takka-v083/v1/manage.',
            'data' => ['status' => 410, 'legacy_action' => $action],
        ], 410);
    }

    public static function prepare_internal_call($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') return $result;
        if (!self::valid_outer_hmac($request)) return $result;
        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner) || ($inner['action'] ?? '') !== 'rest.call') return $result;
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];
        $method = isset($params['method']) ? strtoupper((string) $params['method']) : 'GET';
        $route = isset($params['route']) && is_string($params['route']) ? $params['route'] : '';
        if ($method !== 'POST' || $route !== self::INTERNAL_ROUTE) return $result;
        self::$request_id = isset($inner['request_id']) && is_string($inner['request_id']) ? trim($inner['request_id']) : '';
        self::$internal_allowed = true;
        return $result;
    }

    public static function internal_permission()
    {
        if (!self::$internal_allowed || !current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_v083_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) return new WP_Error('takka_bridge_v083_json', 'JSON body is required.', ['status' => 400]);
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v083_unknown_action', 'Unknown or blocked v0.8.3 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            $response = self::run($action, $params);
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v083_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        if (in_array($action, self::AUDITED, true)) {
            TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, $params, $response);
        }
        return $response;
    }

    private static function run(string $action, array $params)
    {
        switch ($action) {
            case 'v083.capabilities': return rest_ensure_response(self::capabilities());
            case 'post.meta.list': return TakKa_WordPress_Bridge_V083_Post_Meta::list($params);
            case 'post.meta.get': return TakKa_WordPress_Bridge_V083_Post_Meta::get($params);
            case 'post.meta.add': return TakKa_WordPress_Bridge_V083_Post_Meta::add($params);
            case 'post.meta.update': return TakKa_WordPress_Bridge_V083_Post_Meta::update($params);
            case 'post.meta.delete': return TakKa_WordPress_Bridge_V083_Post_Meta::delete($params);
        }
        return new WP_Error('takka_bridge_v083_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['protected_post_meta_management', 'legacy_post_meta_bypass_disabled', 'nested_post_meta_credential_redaction'] as $feature) {
            if (!in_array($feature, $features, true)) $features[] = $feature;
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'internal_route' => self::INTERNAL_ROUTE,
            'actions' => self::ACTIONS,
            'all_reads_require_edit_post' => true,
            'credential_session_keys_blocked' => true,
            'nested_credentials_redacted_on_read' => true,
            'nested_credentials_rejected_on_write' => true,
            'protected_meta_writes_require_confirm_sensitive' => true,
            'write_responses_do_not_return_raw_values' => true,
            'legacy_post_meta_actions_disabled' => true,
        ];
    }

    private static function valid_outer_hmac(WP_REST_Request $request): bool
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) return false;
        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) return false;
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) return false;
        $payload = $timestamp . "\nPOST\n" . self::OUTER_ROUTE . "\n" . hash('sha256', (string) $request->get_body());
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function decode_outer_envelope(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer) || ($outer['action'] ?? '') !== 'envelope' || !isset($outer['params']['payload_b64'])) return null;
        $decoded = base64_decode((string) $outer['params']['payload_b64'], true);
        if (!is_string($decoded)) return null;
        $inner = json_decode($decoded, true);
        return is_array($inner) ? $inner : null;
    }
}
