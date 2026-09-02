<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes WP_Error callback results before v0.5 idempotency persistence.
 *
 * WordPress normally converts WP_Error later in REST response handling, but the
 * v0.5 result-cache filter runs earlier and needs a concrete WP_REST_Response.
 */
final class TakKa_WordPress_Bridge_Error_Normalizer
{
    private const VERSION = '0.5.2';

    private const ROUTES = [
        '/takka-bridge/v1/execute',
        '/takka-bridge/v1/manage',
        '/takka-bridge/v1/v05',
    ];

    public static function init(): void
    {
        // v0.5 idempotency persistence runs at priority 125.
        add_filter('rest_request_after_callbacks', [self::class, 'normalize'], 124, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 150, 3);
    }

    public static function normalize($response, array $handler, WP_REST_Request $request)
    {
        if (strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }
        if (!in_array($request->get_route(), self::ROUTES, true)) {
            return $response;
        }
        if (!is_wp_error($response)) {
            return $response;
        }

        return rest_convert_error_to_response($response);
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
        if (!in_array('error_response_normalization', $features, true)) {
            $features[] = 'error_response_normalization';
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }
}
