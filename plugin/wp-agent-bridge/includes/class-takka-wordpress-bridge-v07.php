<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07
{
    private const VERSION = '0.7.0';
    private const INTERNAL_NAMESPACE = 'takka-v07/v1';
    private const INTERNAL_ROUTE = '/takka-v07/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v07.capabilities', 'audit.list',
        'term.list', 'term.get', 'term.create', 'term.update', 'term.delete.preview', 'term.delete',
        'theme_mod.list', 'theme_mod.get', 'theme_mod.set', 'theme_mod.remove',
        'rewrite.status', 'rewrite.set',
        'user.list', 'user.get', 'user.create', 'user.update',
        'user.set_role', 'user.add_role', 'user.remove_role', 'user.password.reset_email',
        'user.meta.list', 'user.meta.get', 'user.meta.add', 'user.meta.update', 'user.meta.delete',
    ];

    private const AUDITED = [
        'term.create', 'term.update', 'term.delete',
        'theme_mod.set', 'theme_mod.remove', 'rewrite.set',
        'user.create', 'user.update', 'user.set_role', 'user.add_role', 'user.remove_role',
        'user.password.reset_email', 'user.meta.add', 'user.meta.update', 'user.meta.delete',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 30, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 170, 3);
    }

    public static function register_route(): void
    {
        register_rest_route(self::INTERNAL_NAMESPACE, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'internal_permission'],
        ]);
    }

    public static function prepare_internal_call($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        if (!self::valid_outer_hmac($request)) {
            return $result;
        }
        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner)) {
            return $result;
        }

        $action = isset($inner['action']) && is_string($inner['action']) ? $inner['action'] : '';
        $params = isset($inner['params']) && is_array($inner['params']) ? $inner['params'] : [];

        if (in_array($action, ['option.get', 'option.update'], true)) {
            $name = isset($params['name']) && is_string($params['name']) ? trim($params['name']) : '';
            if (strpos($name, 'takka_bridge_') === 0) {
                return new WP_REST_Response([
                    'code' => 'takka_bridge_internal_option_protected',
                    'message' => 'TakKa Bridge internal options require dedicated Bridge actions.',
                    'data' => ['status' => 403],
                ], 403);
            }
        }

        if ($action !== 'rest.call') {
            return $result;
        }
        $method = isset($params['method']) ? strtoupper((string) $params['method']) : 'GET';
        $route = isset($params['route']) && is_string($params['route']) ? $params['route'] : '';
        if ($method !== 'POST' || $route !== self::INTERNAL_ROUTE) {
            return $result;
        }

        self::$request_id = isset($inner['request_id']) && is_string($inner['request_id']) ? trim($inner['request_id']) : '';
        self::$internal_allowed = true;
        return $result;
    }

    public static function internal_permission()
    {
        if (!self::$internal_allowed || !current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_v07_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            return new WP_Error('takka_bridge_v07_json', 'JSON body is required.', ['status' => 400]);
        }
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v07_unknown_action', 'Unknown or blocked v0.7 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            $response = self::run($action, $params);
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v07_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        if (in_array($action, self::AUDITED, true)) {
            TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, $params, $response);
        }
        return $response;
    }

    private static function run(string $action, array $params)
    {
        switch ($action) {
            case 'v07.capabilities': return rest_ensure_response(self::capabilities());
            case 'audit.list': return rest_ensure_response(TakKa_WordPress_Bridge_V07_Audit::list($params));
            case 'term.list': return TakKa_WordPress_Bridge_V07_Terms::list($params);
            case 'term.get': return TakKa_WordPress_Bridge_V07_Terms::get($params);
            case 'term.create': return TakKa_WordPress_Bridge_V07_Terms::create($params);
            case 'term.update': return TakKa_WordPress_Bridge_V07_Terms::update($params);
            case 'term.delete.preview': return TakKa_WordPress_Bridge_V07_Terms::delete_preview($params);
            case 'term.delete': return TakKa_WordPress_Bridge_V07_Terms::delete($params);
            case 'theme_mod.list': return TakKa_WordPress_Bridge_V07_Settings::theme_mod_list();
            case 'theme_mod.get': return TakKa_WordPress_Bridge_V07_Settings::theme_mod_get($params);
            case 'theme_mod.set': return TakKa_WordPress_Bridge_V07_Settings::theme_mod_set($params);
            case 'theme_mod.remove': return TakKa_WordPress_Bridge_V07_Settings::theme_mod_remove($params);
            case 'rewrite.status': return rest_ensure_response(TakKa_WordPress_Bridge_V07_Settings::rewrite_status());
            case 'rewrite.set': return TakKa_WordPress_Bridge_V07_Settings::rewrite_set($params);
            case 'user.list': return rest_ensure_response(TakKa_WordPress_Bridge_V07_Users::list($params));
            case 'user.get': return TakKa_WordPress_Bridge_V07_Users::get($params);
            case 'user.create': return TakKa_WordPress_Bridge_V07_Users::create($params);
            case 'user.update': return TakKa_WordPress_Bridge_V07_Users::update($params);
            case 'user.set_role': return TakKa_WordPress_Bridge_V07_Roles::set_role($params);
            case 'user.add_role': return TakKa_WordPress_Bridge_V07_Roles::add_role($params);
            case 'user.remove_role': return TakKa_WordPress_Bridge_V07_Roles::remove_role($params);
            case 'user.password.reset_email': return TakKa_WordPress_Bridge_V07_Users::password_reset_email($params);
            case 'user.meta.list': return TakKa_WordPress_Bridge_V07_User_Meta::list($params);
            case 'user.meta.get': return TakKa_WordPress_Bridge_V07_User_Meta::get($params);
            case 'user.meta.add': return TakKa_WordPress_Bridge_V07_User_Meta::add($params);
            case 'user.meta.update': return TakKa_WordPress_Bridge_V07_User_Meta::update($params);
            case 'user.meta.delete': return TakKa_WordPress_Bridge_V07_User_Meta::delete($params);
        }
        return new WP_Error('takka_bridge_v07_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['protected_user_management', 'protected_user_meta_management', 'term_management', 'theme_mod_management', 'rewrite_structure_management', 'bounded_wordpress_audit_log', 'internal_option_namespace_protection'] as $feature) {
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
            'transport' => 'signed outer /execute -> internal REST proxy',
            'internal_route' => self::INTERNAL_ROUTE,
            'actions' => self::ACTIONS,
            'password_plaintext_queue_transport_blocked' => true,
            'connected_admin_demotion_blocked' => true,
            'last_admin_demotion_blocked' => true,
            'sensitive_role_email_changes_require_confirm_sensitive' => true,
            'audit_max_entries' => 1000,
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
