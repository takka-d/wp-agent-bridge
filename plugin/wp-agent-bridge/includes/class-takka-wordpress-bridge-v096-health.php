<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keep the aggregate Bridge health version from being downgraded by an older
 * compatibility layer that annotates the same health response at a later
 * filter priority.
 */
final class TakKa_WordPress_Bridge_V096_Health
{
    private const VERSION = '0.9.6';
    private const HEALTH = '/takka-bridge/v1/health';

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'finalize'], 600, 3);
    }

    public static function finalize($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $current = isset($data['bridge_version']) && is_string($data['bridge_version'])
            ? trim($data['bridge_version'])
            : '';
        if ($current === '' || version_compare($current, self::VERSION, '<')) {
            $data['bridge_version'] = self::VERSION;
            $rest->set_data($data);
        }
        return $rest;
    }
}
