<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08
{
    private const VERSION = '0.8.0';
    private const INTERNAL_NAMESPACE = 'takka-v08/v1';
    private const INTERNAL_ROUTE = '/takka-v08/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v08.capabilities',
        'role.list', 'role.get', 'role.create', 'role.delete.preview', 'role.delete',
        'role.cap.add', 'role.cap.remove',
        'user.cap.list', 'user.cap.add', 'user.cap.remove',
        'option.safe.list', 'option.safe.get', 'option.safe.pluck',
        'option.safe.patch.preview', 'option.safe.patch', 'option.safe.delete.preview', 'option.safe.delete',
        'post.term.list', 'post.term.preview', 'post.term.apply',
    ];

    private const AUDITED = [
        'role.create', 'role.delete', 'role.cap.add', 'role.cap.remove', 'user.cap.add', 'user.cap.remove',
        'option.safe.patch', 'option.safe.delete', 'post.term.apply',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 35, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 230, 3);
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
            return new WP_Error('takka_bridge_v08_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) return new WP_Error('takka_bridge_v08_json', 'JSON body is required.', ['status' => 400]);
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) return new WP_Error('takka_bridge_v08_unknown_action', 'Unknown or blocked v0.8 action.', ['status' => 400, 'action' => $action]);
        try {
            $response = self::run($action, $params);
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v08_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        if (in_array($action, self::AUDITED, true)) TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, $params, $response);
        return $response;
    }

    private static function run(string $action, array $params)
    {
        switch ($action) {
            case 'v08.capabilities': return rest_ensure_response(self::capabilities());
            case 'role.list': return rest_ensure_response(TakKa_WordPress_Bridge_V08_Roles::role_list());
            case 'role.get': return TakKa_WordPress_Bridge_V08_Roles::role_get($params);
            case 'role.create': return TakKa_WordPress_Bridge_V08_Roles::role_create($params);
            case 'role.delete.preview': return TakKa_WordPress_Bridge_V08_Roles::role_delete_preview($params);
            case 'role.delete': return TakKa_WordPress_Bridge_V08_Roles::role_delete($params);
            case 'role.cap.add': return TakKa_WordPress_Bridge_V08_Roles::role_cap_add($params);
            case 'role.cap.remove': return TakKa_WordPress_Bridge_V08_Roles::role_cap_remove($params);
            case 'user.cap.list': return TakKa_WordPress_Bridge_V08_Roles::user_cap_list($params);
            case 'user.cap.add': return TakKa_WordPress_Bridge_V08_Roles::user_cap_add($params);
            case 'user.cap.remove': return TakKa_WordPress_Bridge_V08_Roles::user_cap_remove($params);
            case 'option.safe.list': return rest_ensure_response(TakKa_WordPress_Bridge_V08_Options::list($params));
            case 'option.safe.get': return TakKa_WordPress_Bridge_V08_Options::get($params);
            case 'option.safe.pluck': return TakKa_WordPress_Bridge_V08_Options::pluck($params);
            case 'option.safe.patch.preview': return TakKa_WordPress_Bridge_V08_Options::patch_preview($params);
            case 'option.safe.patch': return TakKa_WordPress_Bridge_V08_Options::patch($params);
            case 'option.safe.delete.preview': return TakKa_WordPress_Bridge_V08_Option_Delete::preview($params);
            case 'option.safe.delete': return TakKa_WordPress_Bridge_V08_Option_Delete::delete($params);
            case 'post.term.list': return TakKa_WordPress_Bridge_V08_Post_Terms::list($params);
            case 'post.term.preview': return TakKa_WordPress_Bridge_V08_Post_Terms::preview($params);
            case 'post.term.apply': return TakKa_WordPress_Bridge_V08_Post_Terms::apply($params);
        }
        return new WP_Error('takka_bridge_v08_dispatch', 'Dispatch fell through.', ['status' => 500]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['role_capability_management', 'safe_nested_option_patch', 'previewed_option_delete', 'private_taxonomy_post_assignment'] as $feature) {
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
            'administrator_core_capability_removal_blocked' => true,
            'critical_capability_grants_require_confirm_sensitive' => true,
            'role_delete_requires_zero_assigned_users_and_impact_hash' => true,
            'option_patch_array_only' => true,
            'option_patch_requires_plan_hash' => true,
            'option_delete_requires_impact_hash' => true,
            'credential_and_bridge_options_protected' => true,
            'post_terms_never_created_implicitly' => true,
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
