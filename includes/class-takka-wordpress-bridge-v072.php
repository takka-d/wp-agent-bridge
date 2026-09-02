<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V072
{
    private const VERSION = '0.7.2';

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_responses'], 200, 3);
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
            foreach (['sensitive_theme_mod_filtering', 'nested_credential_write_rejection', 'nested_user_meta_redaction'] as $feature) {
                if (!in_array($feature, $features, true)) $features[] = $feature;
            }
            $data['features'] = $features;
            $rest->set_data($data);
            return $rest;
        }

        $json = $request->get_json_params();
        $action = is_array($json) && isset($json['action']) && is_string($json['action']) ? $json['action'] : '';
        if ($action === 'v07.capabilities') {
            $data['version'] = self::VERSION;
            $data['sensitive_theme_mod_filtering'] = true;
            $data['nested_credential_write_rejection'] = true;
            $data['nested_user_meta_redaction'] = true;
            $rest->set_data($data);
        }
        return $rest;
    }
}
