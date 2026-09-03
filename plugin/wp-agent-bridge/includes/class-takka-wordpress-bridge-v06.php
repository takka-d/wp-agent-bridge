<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V06
{
    private const VERSION = '0.6.2';
    private const ROUTE_NAMESPACE = 'takka-bridge/v1';
    private const ROUTE = '/takka-bridge/v1/v06';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_ENVELOPE_BYTES = 12582912;

    private const ACTIONS = [
        'v06.capabilities',
        'bridge.self_update.status',
        'bridge.self_update.apply',
        'bridge.self_update.rollback',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 160, 3);
        TakKa_WordPress_Bridge_V06_Idempotency::init();
    }

    public static function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/v06', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'dispatch'],
            'permission_callback' => [self::class, 'authorize_request'],
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
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
            'signed_self_update_manifest',
            'self_update_preflight_php_parse',
            'self_update_backup_rollback',
            'self_update_full_manifest_required',
            'self_update_explicit_delete_paths',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    public static function authorize_request(WP_REST_Request $request)
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1) {
            return new WP_Error('takka_bridge_not_configured', 'TakKa WordPress Bridge is not configured.', ['status' => 503]);
        }

        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return new WP_Error('takka_bridge_invalid_signature_headers', 'Missing or invalid bridge signature headers.', ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return new WP_Error('takka_bridge_expired_signature', 'Bridge signature has expired.', ['status' => 401]);
        }

        $body = (string) $request->get_body();
        $payload = $timestamp . "\nPOST\n" . self::ROUTE . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('takka_bridge_bad_signature', 'Invalid bridge signature.', ['status' => 401]);
        }

        wp_set_current_user($user_id);
        if (!current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_user_forbidden', 'Bridge user no longer has administrator capability.', ['status' => 403]);
        }
        return true;
    }

    public static function dispatch(WP_REST_Request $request)
    {
        $payload = self::decode_envelope($request);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $action = isset($payload['action']) && is_string($payload['action']) ? trim($payload['action']) : '';
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
        if ($action === '' || !in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_v06_unknown_action', 'Unknown or blocked v0.6 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v06.capabilities':
                    return rest_ensure_response(self::capabilities());
                case 'bridge.self_update.status':
                    return rest_ensure_response(TakKa_WordPress_Bridge_V06_Self_Update::status());
                case 'bridge.self_update.apply':
                    return TakKa_WordPress_Bridge_V06_Self_Update_Safe::apply($params);
                case 'bridge.self_update.rollback':
                    return TakKa_WordPress_Bridge_V06_Self_Update::rollback($params);
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v06_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }

        return new WP_Error('takka_bridge_v06_dispatch_error', 'v0.6 dispatch fell through unexpectedly.', ['status' => 500]);
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'self_update' => TakKa_WordPress_Bridge_V06_Self_Update_Safe::capabilities(),
            'arbitrary_plugin_package' => false,
            'arbitrary_filesystem_write' => false,
        ];
    }

    private static function decode_envelope(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json) || !isset($json['payload_b64']) || !is_string($json['payload_b64'])) {
            return new WP_Error('takka_bridge_v06_missing_envelope', 'Missing payload_b64.', ['status' => 400]);
        }
        $payload_b64 = trim($json['payload_b64']);
        if ($payload_b64 === '' || strlen($payload_b64) > self::MAX_ENVELOPE_BYTES) {
            return new WP_Error('takka_bridge_v06_envelope_size', 'Envelope is empty or too large.', ['status' => 413]);
        }
        $decoded = base64_decode($payload_b64, true);
        if (!is_string($decoded)) {
            return new WP_Error('takka_bridge_v06_invalid_base64', 'Envelope is not valid Base64.', ['status' => 400]);
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('takka_bridge_v06_invalid_json', 'Decoded envelope is not valid JSON.', ['status' => 400]);
        }
        return $payload;
    }
}
