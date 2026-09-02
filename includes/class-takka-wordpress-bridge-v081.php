<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V081
{
    private const VERSION = '0.8.1';
    private const INTERNAL_ROUTE = '/takka-v08/v1/manage';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'sanitize_v08_response'], 260, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 260, 3);
    }

    public static function sanitize_v08_response($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::INTERNAL_ROUTE || is_wp_error($response)) return $response;

        $json = $request->get_json_params();
        if (!is_array($json)) return $response;
        $action = isset($json['action']) && is_string($json['action']) ? trim($json['action']) : '';

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) return $response;

        if ($action === 'option.safe.patch.preview') {
            unset($data['_after_value']);
            $data['raw_after_value_exposed'] = false;
        } elseif ($action === 'option.safe.delete.preview' && array_key_exists('value_preview', $data)) {
            $data['value_preview'] = self::redact_value($data['value_preview']);
            $data['nested_credentials_redacted'] = true;
        } elseif ($action === 'v08.capabilities') {
            $data['version'] = self::VERSION;
            $data['option_preview_raw_after_value_blocked'] = true;
            $data['option_delete_preview_nested_credentials_redacted'] = true;
        }

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
        foreach (['option_preview_raw_after_value_blocked', 'option_delete_preview_nested_credential_redaction'] as $feature) {
            if (!in_array($feature, $features, true)) $features[] = $feature;
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    private static function redact_value($value)
    {
        if (is_string($value)) return strlen($value) > 20000 ? substr($value, 0, 20000) . '<truncated>' : $value;
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && self::is_sensitive_key($key)) $out[$key] = '<redacted>';
                else $out[$key] = self::redact_value($child);
            }
            return $out;
        }
        if (is_object($value)) return '<object omitted>';
        return $value;
    }

    private static function is_sensitive_key(string $key): bool
    {
        $normalized = strtolower($key);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        if (!is_string($normalized)) return true;
        foreach (['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'accesskey', 'credential', 'private_key', 'privatekey', 'client_secret', 'auth_key', 'salt', 'otp', '2fa', 'recovery', 'totp', 'webauthn', 'session'] as $needle) {
            if (strpos($normalized, $needle) !== false) return true;
        }
        return false;
    }
}
