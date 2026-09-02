<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V088
{
    private const VERSION = '0.8.8';
    private const INTERNAL_NAMESPACE = 'takka-v088/v1';
    private const INTERNAL_ROUTE = '/takka-v088/v1/manage';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    private static $internal_allowed = false;
    private static $request_id = '';

    private const ACTIONS = [
        'v088.capabilities',
        'post.table.headers.inspect',
        'post.table.header.patch.preview',
        'post.table.header.patch.apply',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_filter('rest_pre_dispatch', [self::class, 'prepare_internal_call'], 53, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 370, 3);
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
            return new WP_Error('takka_bridge_v088_internal_only', 'This route is only callable through the signed Bridge REST proxy.', ['status' => 403]);
        }
        self::$internal_allowed = false;
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json)) return new WP_Error('takka_bridge_v088_json', 'JSON body is required.', ['status' => 400]);
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v088_unknown_action', 'Unknown or blocked v0.8.8 action.', ['status' => 400, 'action' => $action]);
        }

        try {
            switch ($action) {
                case 'v088.capabilities':
                    $response = rest_ensure_response(self::capabilities());
                    break;
                case 'post.table.headers.inspect':
                    $response = TakKa_WordPress_Bridge_V088_Table_Headers::inspect($params);
                    break;
                case 'post.table.header.patch.preview':
                    $response = TakKa_WordPress_Bridge_V088_Table_Headers::preview($params);
                    break;
                case 'post.table.header.patch.apply':
                    $response = TakKa_WordPress_Bridge_V088_Table_Headers::apply($params);
                    TakKa_WordPress_Bridge_V07_Audit::record(self::$request_id, $action, self::audit_params($params), $response);
                    break;
                default:
                    $response = new WP_Error('takka_bridge_v088_dispatch', 'Dispatch fell through.', ['status' => 500]);
            }
        } catch (Throwable $e) {
            $response = new WP_Error('takka_bridge_v088_exception', $e->getMessage(), ['status' => 500, 'type' => get_class($e)]);
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
        foreach (['table_header_discovery', 'header_named_table_cell_editing', 'header_edit_reuses_smart_atomic_guards'] as $feature) {
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
            'auto_header_row' => 'first_row_containing_th',
            'explicit_header_row_index_supported' => true,
            'header_names_are_exact_normalized_matches' => true,
            'header_case_sensitive_default' => false,
            'set_cell_column_header_supported' => true,
            'set_cells_cells_by_header_supported' => true,
            'insert_row_cells_by_header_supported' => true,
            'key_header_supported' => true,
            'ambiguous_or_missing_header_returns_409' => true,
            'header_patch_reuses_v087_smart_resolution' => true,
            'header_patch_reuses_v086_semantic_patch' => true,
            'header_patch_reuses_v085_atomic_sha_plan_guards' => true,
        ];
    }

    private static function audit_params(array $params): array
    {
        $out = $params;
        if (!isset($out['operations']) || !is_array($out['operations'])) return $out;
        $safe = [];
        foreach ($out['operations'] as $index => $operation) {
            if (!is_array($operation)) {
                $safe[] = ['index' => $index, 'invalid' => true];
                continue;
            }
            $item = $operation;
            foreach (['row_key', 'content', 'column_header', 'key_header'] as $key) {
                if (isset($item[$key]) && is_string($item[$key])) $item[$key] = self::digest($item[$key]);
            }
            foreach (['cells', 'cells_by_header'] as $cells_key) {
                if (!isset($item[$cells_key]) || !is_array($item[$cells_key])) continue;
                $cell_safe = [];
                foreach ($item[$cells_key] as $column => $spec) {
                    $safe_key = is_string($column) ? self::digest($column) : $column;
                    if (is_string($spec) || is_int($spec) || is_float($spec)) {
                        $cell_safe[$safe_key] = self::digest((string) $spec);
                    } elseif (is_array($spec)) {
                        $copy = $spec;
                        if (isset($copy['content']) && is_string($copy['content'])) $copy['content'] = self::digest($copy['content']);
                        $cell_safe[$safe_key] = $copy;
                    } else {
                        $cell_safe[$safe_key] = '<non-scalar>';
                    }
                }
                $item[$cells_key] = $cell_safe;
            }
            $safe[] = $item;
        }
        $out['operations'] = $safe;
        return $out;
    }

    private static function digest(string $value): string
    {
        return '<sha256:' . hash('sha256', $value) . ';bytes=' . strlen($value) . '>';
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
