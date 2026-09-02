<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V084
{
    private const VERSION = '0.8.4.1';
    private const INTERNAL_NAMESPACE = 'takka-v084/v1';
    private const INTERNAL_ROUTE = '/takka-v084/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v084.capabilities',
        'post.content.inspect',
        'post.content.search',
        'post.content.patch.preview',
        'post.content.patch.apply',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 46, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 330, 3);
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
            return new WP_Error('takka_bridge_v084_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) return new WP_Error('takka_bridge_v084_json', 'JSON body is required.', ['status' => 400]);
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v084_unknown_action', 'Unknown or blocked v0.8.4 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            switch ($action) {
                case 'v084.capabilities':
                    $response = rest_ensure_response(self::capabilities());
                    break;
                case 'post.content.inspect':
                    $response = TakKa_WordPress_Bridge_V084_Post_Content::inspect($params);
                    break;
                case 'post.content.search':
                    $response = TakKa_WordPress_Bridge_V084_Post_Content::search($params);
                    break;
                case 'post.content.patch.preview':
                    $response = TakKa_WordPress_Bridge_V084_Post_Content::preview($params);
                    break;
                case 'post.content.patch.apply':
                    $response = TakKa_WordPress_Bridge_V084_Post_Content::apply($params);
                    TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, self::audit_params($params), $response);
                    break;
                default:
                    $response = new WP_Error('takka_bridge_v084_dispatch', 'Dispatch fell through.', ['status' => 500]);
            }
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v084_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
        }
        return $response;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['conflict_safe_post_content_patch', 'targeted_post_content_search', 'post_content_preview_plan_hash', 'post_content_patch_counter_fix'] as $feature) {
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
            'full_content_not_returned_by_inspect' => true,
            'search_returns_bounded_context_only' => true,
            'patch_preview_requires_exact_match_count' => true,
            'patch_apply_requires_expected_before_sha256' => true,
            'patch_apply_requires_expected_plan_hash' => true,
            'non_draft_apply_requires_confirm_live' => true,
            'post_update_verified_by_sha256' => true,
            'patch_replacement_counter_initialized' => true,
        ];
    }

    private static function audit_params(array $params): array
    {
        $out = $params;
        if (isset($out['find']) && is_string($out['find'])) {
            $out['find'] = '<sha256:' . hash('sha256', $out['find']) . ';bytes=' . strlen($out['find']) . '>';
        }
        if (isset($out['replace']) && is_string($out['replace'])) {
            $out['replace'] = '<sha256:' . hash('sha256', $out['replace']) . ';bytes=' . strlen($out['replace']) . '>';
        }
        return $out;
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
