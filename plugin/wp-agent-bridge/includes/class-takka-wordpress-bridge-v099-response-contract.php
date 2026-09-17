<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stable response envelope for the deterministic v0.9.9 operation router.
 *
 * WordPress normally exposes WP_Error objects as {code,message,data}, while
 * successful v0.9.9 operations use {ok,operation,status,result}. Model clients
 * should not need different decoders for success and failure. This layer keeps
 * the original error details but publishes one bounded top-level shape.
 */
final class TakKa_WordPress_Bridge_V099_Response_Contract
{
    private const ROUTE = '/takka-v099/v1/operate';
    private const HEALTH = '/takka-bridge/v1/health';
    private const CONTRACT_VERSION = 1;
    private const RUNTIME_SCHEMA_VERSION = 2;

    public static function init(): void
    {
        add_filter('rest_request_after_callbacks', [self::class, 'normalize_operation'], 680, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 680, 3);
    }

    public static function normalize_operation($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::ROUTE || strtoupper($request->get_method()) !== 'POST') {
            return $response;
        }

        $operation = self::operation($request);
        if (is_wp_error($response)) {
            $code = (string) $response->get_error_code();
            $message = (string) $response->get_error_message($code);
            $raw_data = $response->get_error_data($code);
            $error_data = is_array($raw_data) ? $raw_data : [];
            $status = isset($error_data['status']) && is_numeric($error_data['status'])
                ? (int) $error_data['status']
                : 500;
            $side_effects = array_key_exists('side_effects', $error_data)
                ? (bool) $error_data['side_effects']
                : null;

            return new WP_REST_Response([
                'ok' => false,
                'operation' => $operation !== '' ? $operation : null,
                'status' => $status,
                'code' => $code !== '' ? $code : 'wpab_operation_error',
                'message' => $message,
                'data' => $error_data,
                'side_effects' => $side_effects,
                'response_contract_version' => self::CONTRACT_VERSION,
            ], $status);
        }

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        // Catalog is intentionally a machine-readable contract rather than an
        // operation result. Enrich it without wrapping or changing its status.
        if ($operation === 'catalog') {
            $data['response_contract'] = self::contract();
            $data['version_fields'] = [
                'plugin_version' => self::plugin_version(),
                'operation_api_version' => (string) ($data['version'] ?? '0.9.9'),
                'runtime_schema_version' => self::RUNTIME_SCHEMA_VERSION,
            ];
            $rest->set_data($data);
            return $rest;
        }

        $status = isset($data['status']) && is_numeric($data['status'])
            ? (int) $data['status']
            : $rest->get_status();
        if (!array_key_exists('ok', $data)) {
            $data['ok'] = $status >= 200 && $status < 300;
        }
        if (!array_key_exists('operation', $data)) {
            $data['operation'] = $operation !== '' ? $operation : null;
        }
        if (!array_key_exists('status', $data)) {
            $data['status'] = $status;
        }
        if (!array_key_exists('code', $data)) {
            $data['code'] = null;
        }
        if (!array_key_exists('message', $data)) {
            $data['message'] = null;
        }
        if (!array_key_exists('side_effects', $data)) {
            $data['side_effects'] = self::nested_side_effects($data);
        }
        $data['response_contract_version'] = self::CONTRACT_VERSION;
        $rest->set_data($data);
        return $rest;
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }

        $api_compat = isset($data['bridge_version']) && is_string($data['bridge_version'])
            ? trim($data['bridge_version'])
            : '';
        $data['plugin_version'] = self::plugin_version();
        $data['api_compatibility_version'] = $api_compat !== '' ? $api_compat : null;
        $data['runtime_schema_version'] = self::RUNTIME_SCHEMA_VERSION;
        $data['bridge_version_semantics'] = 'legacy_api_compatibility_version';
        $data['operation_response_contract'] = self::contract();

        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach (['deterministic_operation_response_contract', 'explicit_bridge_version_semantics'] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    public static function contract(): array
    {
        return [
            'version' => self::CONTRACT_VERSION,
            'fields' => ['ok', 'operation', 'status', 'code', 'message', 'data|result', 'side_effects'],
            'error_http_status_matches_status' => true,
            'side_effects_values' => [true, false, null],
            'side_effects_null_means' => 'not_proven_by_operation_response',
            'wp_error_details_preserved_in_data' => true,
        ];
    }

    private static function operation(WP_REST_Request $request): string
    {
        $json = $request->get_json_params();
        if (!is_array($json)) {
            $json = json_decode((string) $request->get_body(), true);
        }
        return is_array($json) && is_string($json['operation'] ?? null)
            ? trim($json['operation'])
            : '';
    }

    private static function nested_side_effects(array $data)
    {
        $candidates = [
            $data['result']['data']['side_effects'] ?? null,
            $data['result']['side_effects'] ?? null,
            $data['data']['side_effects'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_bool($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private static function plugin_version(): ?string
    {
        if (!function_exists('get_file_data')) {
            return null;
        }
        $data = get_file_data(dirname(__DIR__) . '/takka-wordpress-bridge.php', ['Version' => 'Version'], 'plugin');
        $version = isset($data['Version']) ? trim((string) $data['Version']) : '';
        return $version !== '' ? $version : null;
    }
}
