<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adds non-sensitive execution status metadata to encrypted secure responses.
 *
 * The secure payload/result remains encrypted. Only the effective numeric
 * status and a boolean success flag are copied to the outer response so
 * GitHub Actions can distinguish an inner 4xx/5xx from a successful command.
 */
final class TakKa_WordPress_Bridge_V090_Secure_Status
{
    private const VERSION = '0.9.1';
    private const EXECUTE_ROUTE = '/takka-bridge/v1/execute';
    private const SECURE_ROUTE = '/takka-bridge/v1/secure';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';

    /** @var array<string,int> */
    private static $inner_status = [];

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'capture_inner_status'], 490, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_secure_response'], 495, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 500, 3);
    }

    public static function capture_inner_status($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::EXECUTE_ROUTE || strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }

        $request_id = self::request_id_from_execute_body((string) $request->get_body());
        if ($request_id === '') {
            return $response;
        }

        $rest = rest_ensure_response($response);
        $status = (int) $rest->get_status();
        $data = $rest->get_data();

        // /execute normally returns HTTP 200 around rest.call while the actual
        // WordPress REST status is carried in data.status. Prefer that effective
        // status when it is a valid HTTP status code.
        if (
            is_array($data) &&
            isset($data['status']) &&
            is_numeric($data['status'])
        ) {
            $nested_status = (int) $data['status'];
            if ($nested_status >= 100 && $nested_status <= 599) {
                $status = $nested_status;
            }
        }

        self::$inner_status[$request_id] = $status;
        return $response;
    }

    public static function annotate_secure_response($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::SECURE_ROUTE || is_wp_error($response)) {
            return $response;
        }

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $request_id = isset($data['request_id']) && is_string($data['request_id'])
            ? trim($data['request_id'])
            : '';
        if ($request_id === '' || !array_key_exists($request_id, self::$inner_status)) {
            return $response;
        }

        $status = (int) self::$inner_status[$request_id];
        unset(self::$inner_status[$request_id]);
        $data['inner_status'] = $status;
        $data['inner_ok'] = $status >= 200 && $status < 400;
        $rest->set_data($data);
        return $rest;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE || is_wp_error($response)) {
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
            'secure_inner_status_metadata',
            'secure_actions_failure_propagation',
            'secure_effective_nested_status',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    private static function request_id_from_execute_body(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $outer = json_decode($body, true);
        if (!is_array($outer)) {
            return '';
        }

        $payload_b64 = '';
        if (
            isset($outer['action'], $outer['params']) &&
            $outer['action'] === 'envelope' &&
            is_array($outer['params']) &&
            isset($outer['params']['payload_b64']) &&
            is_string($outer['params']['payload_b64'])
        ) {
            $payload_b64 = $outer['params']['payload_b64'];
        }
        if ($payload_b64 === '') {
            return '';
        }

        $payload = base64_decode($payload_b64, true);
        if (!is_string($payload)) {
            return '';
        }
        $inner = json_decode($payload, true);
        if (!is_array($inner) || !isset($inner['request_id']) || !is_string($inner['request_id'])) {
            return '';
        }

        $request_id = trim($inner['request_id']);
        if ($request_id === '' || strlen($request_id) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $request_id)) {
            return '';
        }
        return $request_id;
    }
}
