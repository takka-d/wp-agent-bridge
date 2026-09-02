<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reliable request-id short-circuiting for signed bridge POST requests.
 *
 * v0.5 originally used rest_request_before_callbacks for the replay decision.
 * Result persistence worked there, but the response did not short-circuit the
 * callback path in the observed WordPress execution flow. This class moves the
 * replay/conflict decision to rest_pre_dispatch, whose contract explicitly
 * supports returning a response before route dispatch.
 *
 * Authentication is re-verified here before any cached response is exposed.
 */
final class TakKa_WordPress_Bridge_Idempotency
{
    private const VERSION = '0.5.1';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const IDEMPOTENCY_PREFIX = 'takka_bridge_idem_';
    private const IDEMPOTENCY_INDEX = 'takka_bridge_idem_index';
    private const IDEMPOTENCY_TTL = 86400;
    private const IDEMPOTENCY_MAX = 500;
    private const MAX_CLOCK_SKEW = 300;
    private const IN_PROGRESS_TTL = 600;

    private const ROUTES = [
        '/takka-bridge/v1/execute',
        '/takka-bridge/v1/manage',
        '/takka-bridge/v1/v05',
    ];

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'pre_dispatch'], 20, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 140, 3);
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
        if (!in_array('pre_dispatch_idempotency', $features, true)) {
            $features[] = 'pre_dispatch_idempotency';
        }
        $data['features'] = $features;
        $rest->set_data($data);
        return $rest;
    }

    public static function pre_dispatch($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || strtoupper($request->get_method()) !== 'POST') {
            return $result;
        }
        $route = (string) $request->get_route();
        if (!in_array($route, self::ROUTES, true)) {
            return $result;
        }

        // Never expose or mutate idempotency state unless this request itself
        // carries a valid bridge HMAC and the configured bridge user is still
        // an administrator. The normal route permission callback remains the
        // authority for returning authentication errors.
        if (!self::has_valid_bridge_auth($request)) {
            return $result;
        }

        $context = self::extract_request_context($request);
        if (is_wp_error($context)) {
            return $context;
        }
        if ($context === null) {
            return $result;
        }

        self::prune_idempotency();
        $option = self::idempotency_option($context['request_id']);
        $existing = get_option($option, null);

        if (is_array($existing)) {
            if (!isset($existing['payload_hash']) || !hash_equals((string) $existing['payload_hash'], $context['payload_hash'])) {
                return self::short_circuit_error(
                    'takka_bridge_idempotency_payload_conflict',
                    'request_id was already used for a different payload.',
                    409,
                    ['request_id' => $context['request_id']]
                );
            }

            if (($existing['state'] ?? '') === 'completed') {
                $headers = isset($existing['headers']) && is_array($existing['headers']) ? $existing['headers'] : [];
                unset($headers['X-TakKa-Idempotency-Internal'], $headers['X-TakKa-Idempotent-Replay']);
                $cached = new WP_REST_Response(
                    $existing['data'] ?? null,
                    isset($existing['status']) ? (int) $existing['status'] : 200,
                    $headers
                );
                $cached->header('X-TakKa-Idempotent-Replay', '1');
                $cached->header('X-TakKa-Idempotency-Internal', '1');
                return $cached;
            }

            $created_at = isset($existing['created_at']) ? (int) $existing['created_at'] : 0;
            if ($created_at > 0 && (time() - $created_at) < self::IN_PROGRESS_TTL) {
                return self::short_circuit_error(
                    'takka_bridge_idempotency_in_progress',
                    'The same request_id is already in progress.',
                    409,
                    ['request_id' => $context['request_id']]
                );
            }

            update_option($option, self::new_lock($context), false);
            self::touch_index($option);
            return $result;
        }

        if (!add_option($option, self::new_lock($context), '', false)) {
            return self::short_circuit_error(
                'takka_bridge_idempotency_lock_race',
                'Could not acquire idempotency lock.',
                409,
                ['request_id' => $context['request_id']]
            );
        }
        self::touch_index($option);
        return $result;
    }

    private static function has_valid_bridge_auth(WP_REST_Request $request): bool
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1 || !user_can($user_id, 'manage_options')) {
            return false;
        }

        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return false;
        }

        $payload = $timestamp . "\n"
            . strtoupper((string) $request->get_method()) . "\n"
            . (string) $request->get_route() . "\n"
            . hash('sha256', (string) $request->get_body());
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    private static function extract_request_context(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer)) {
            return null;
        }

        if ($request->get_route() === '/takka-bridge/v1/execute') {
            if (($outer['action'] ?? null) !== 'envelope'
                || !isset($outer['params']['payload_b64'])
                || !is_string($outer['params']['payload_b64'])) {
                return null;
            }
            $decoded = base64_decode($outer['params']['payload_b64'], true);
        } else {
            if (!isset($outer['payload_b64']) || !is_string($outer['payload_b64'])) {
                return null;
            }
            $decoded = base64_decode($outer['payload_b64'], true);
        }

        if (!is_string($decoded)) {
            return null;
        }
        $inner = json_decode($decoded, true);
        if (!is_array($inner) || !isset($inner['request_id']) || !is_string($inner['request_id'])) {
            return null;
        }

        $request_id = trim($inner['request_id']);
        if ($request_id === '') {
            return null;
        }
        if (strlen($request_id) > 120 || !preg_match('/^[A-Za-z0-9._:-]+$/', $request_id)) {
            return new WP_Error(
                'takka_bridge_invalid_request_id',
                'request_id contains invalid characters or is too long.',
                ['status' => 400]
            );
        }

        return [
            'request_id' => $request_id,
            'payload_hash' => hash('sha256', $decoded),
            'action' => isset($inner['action']) && is_string($inner['action']) ? $inner['action'] : 'unknown',
        ];
    }

    private static function short_circuit_error(string $code, string $message, int $status, array $extra = []): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code' => $code,
            'message' => $message,
            'data' => array_merge(['status' => $status], $extra),
        ], $status);
        $response->header('X-TakKa-Idempotency-Internal', '1');
        return $response;
    }

    private static function new_lock(array $context): array
    {
        return [
            'state' => 'running',
            'request_id' => $context['request_id'],
            'payload_hash' => $context['payload_hash'],
            'action' => $context['action'],
            'created_at' => time(),
        ];
    }

    private static function idempotency_option(string $request_id): string
    {
        return self::IDEMPOTENCY_PREFIX . substr(hash('sha256', $request_id), 0, 40);
    }

    private static function touch_index(string $option): void
    {
        $index = get_option(self::IDEMPOTENCY_INDEX, []);
        if (!is_array($index)) {
            $index = [];
        }
        $index[$option] = time();
        update_option(self::IDEMPOTENCY_INDEX, $index, false);
    }

    private static function prune_idempotency(): void
    {
        $index = get_option(self::IDEMPOTENCY_INDEX, []);
        if (!is_array($index) || !$index) {
            return;
        }
        asort($index, SORT_NUMERIC);
        $cutoff = time() - self::IDEMPOTENCY_TTL;
        while ($index && (count($index) > self::IDEMPOTENCY_MAX || (int) reset($index) < $cutoff)) {
            $option = (string) key($index);
            delete_option($option);
            unset($index[$option]);
        }
        update_option(self::IDEMPOTENCY_INDEX, $index, false);
    }
}
