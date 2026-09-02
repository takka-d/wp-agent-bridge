<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V073
{
    private const VERSION = '0.7.3';

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_responses'], 210, 3);
    }

    public static function annotate_responses($response, array $handler, WP_REST_Request $request)
    {
        if (is_wp_error($response)) return $response;
        $route = $request->get_route();
        if ($route !== '/takka-bridge/v1/health' && $route !== '/takka-v07/v1/manage') return $response;
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;

        if ($route === '/takka-bridge/v1/health') {
            $data['bridge_version'] = self::VERSION;
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            if (!in_array('user_meta_existence_semantics', $features, true)) $features[] = 'user_meta_existence_semantics';
            $data['features'] = $features;
            $rest->set_data($data);
            return $rest;
        }

        $json = $request->get_json_params();
        $action = is_array($json) && isset($json['action']) && is_string($json['action']) ? $json['action'] : '';
        if ($action === 'v07.capabilities') {
            $data['version'] = self::VERSION;
            $data['user_meta_existence_semantics'] = true;
            $rest->set_data($data);
        }
        return $rest;
    }
}
