<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Transport compatibility layer for WAF-safe opaque command envelopes.
 *
 * The existing /execute route continues to receive the schema it already
 * expects (action + params), but GitHub Actions can send action=envelope with
 * the real command JSON base64-encoded inside params.payload_b64. The envelope
 * is decoded only after WordPress has matched the bridge route, before the
 * permission callback and endpoint callback run.
 */
final class TakKa_WordPress_Bridge_Envelope
{
    private const EXECUTE_ROUTE = '/takka-bridge/v1/execute';
    private const HEALTH_ROUTE = '/takka-bridge/v1/health';
    private const TRANSPORT_VERSION = '0.3.1';
    private const MAX_ENVELOPE_BYTES = 8388608;

    public static function init(): void
    {
        add_filter('rest_request_before_callbacks', [self::class, 'decode_envelope'], 1, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_response'], 99, 3);
    }

    public static function decode_envelope($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::EXECUTE_ROUTE) {
            return $response;
        }

        if ((string) $request->get_param('action') !== 'envelope') {
            return $response;
        }

        $params = $request->get_param('params');
        if (!is_array($params) || !isset($params['payload_b64']) || !is_string($params['payload_b64'])) {
            return new WP_Error(
                'takka_bridge_envelope_missing',
                'Missing payload_b64 in bridge envelope.',
                ['status' => 400]
            );
        }

        $payload_b64 = trim($params['payload_b64']);
        if ($payload_b64 === '' || strlen($payload_b64) > self::MAX_ENVELOPE_BYTES) {
            return new WP_Error(
                'takka_bridge_envelope_invalid_size',
                'Bridge envelope is empty or exceeds the size limit.',
                ['status' => 413]
            );
        }

        $decoded = base64_decode($payload_b64, true);
        if (!is_string($decoded)) {
            return new WP_Error(
                'takka_bridge_envelope_invalid_base64',
                'Bridge envelope is not valid Base64.',
                ['status' => 400]
            );
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error(
                'takka_bridge_envelope_invalid_json',
                'Decoded bridge envelope is not valid JSON.',
                ['status' => 400]
            );
        }

        if (!isset($payload['action']) || !is_string($payload['action']) || trim($payload['action']) === '') {
            return new WP_Error(
                'takka_bridge_envelope_missing_action',
                'Decoded bridge envelope does not contain a valid action.',
                ['status' => 400]
            );
        }

        $decoded_params = $payload['params'] ?? [];
        if (!is_array($decoded_params)) {
            return new WP_Error(
                'takka_bridge_envelope_invalid_params',
                'Decoded bridge envelope params must be an object.',
                ['status' => 400]
            );
        }

        $request->set_param('action', trim($payload['action']));
        $request->set_param('params', $decoded_params);

        return $response;
    }

    public static function annotate_response($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== self::HEALTH_ROUTE && $request->get_route() !== self::EXECUTE_ROUTE) {
            return $response;
        }
        if (is_wp_error($response)) {
            return $response;
        }

        $rest_response = rest_ensure_response($response);
        $data = $rest_response->get_data();
        if (!is_array($data)) {
            return $response;
        }

        if ($request->get_route() === self::HEALTH_ROUTE) {
            $data['bridge_version'] = self::TRANSPORT_VERSION;
            $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
            if (!in_array('waf_safe_payload_envelope', $features, true)) {
                $features[] = 'waf_safe_payload_envelope';
            }
            $data['features'] = $features;
        } elseif (array_key_exists('bridge_version', $data)) {
            $data['bridge_version'] = self::TRANSPORT_VERSION;
        }

        $rest_response->set_data($data);
        return $rest_response;
    }
}
