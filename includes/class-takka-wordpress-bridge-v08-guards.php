<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08_Guards
{
    public static function init(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'validate_request'], 20, 3);
    }

    public static function validate_request($response, array $handler, WP_REST_Request $request)
    {
        if ($response !== null || $request->get_route() !== '/takka-v08/v1/manage') return $response;
        $json = $request->get_json_params();
        if (!is_array($json) || ($json['action'] ?? '') !== 'option.safe.list') return $response;
        $params = isset($json['params']) && is_array($json['params']) ? $json['params'] : [];
        if (!isset($params['autoload']) || $params['autoload'] === '') return $response;
        if (!is_string($params['autoload']) || !in_array(strtolower(trim($params['autoload'])), ['on', 'off'], true)) {
            return new WP_Error('takka_bridge_option_autoload_invalid', 'autoload must be on or off.', ['status' => 400]);
        }
        return $response;
    }
}
