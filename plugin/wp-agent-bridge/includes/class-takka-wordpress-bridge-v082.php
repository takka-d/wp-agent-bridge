<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V082
{
    private const VERSION = '0.8.2';
    private const OUTER_ROUTE = '/takka-bridge/v1/execute';
    private const V08_ROUTE = '/takka-v08/v1/manage';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'block_legacy_option_actions'], 25, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_v08_capabilities'], 280, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 280, 3);
    }

    public static function block_legacy_option_actions($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || $request->get_route() !== self::OUTER_ROUTE || strtoupper($request->get_method()) !== 'POST') return $result;
        if (!self::valid_outer_hmac($request)) return $result;

        $inner = self::decode_outer_envelope($request);
        if (!is_array($inner)) return $result;
        $action = isset($inner['action']) && is_string($inner['action']) ? trim($inner['action']) : '';
        if (!in_array($action, ['option.get', 'option.update'], true)) return $result;

        return new WP_REST_Response([
            'code' => 'takka_bridge_legacy_option_action_disabled',
            'message' => 'Legacy option.get/option.update are disabled. Use the protected option.safe.* actions through /takka-v08/v1/manage.',
            'data' => ['status' => 410, 'legacy_action' => $action],
        ], 410);
    }

    public static function annotate_v08_capabilities($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::V08_ROUTE || is_wp_error($response)) return $response;
        $json = $request->get_json_params();
        if (!is_array($json) || ($json['action'] ?? '') !== 'v08.capabilities') return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['version'] = self::VERSION;
        $data['legacy_option_get_update_disabled'] = true;
        $rest->set_data($data);
        return $rest;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('legacy_option_bypass_disabled', $features, true)) $features[] = 'legacy_option_bypass_disabled';
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
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
