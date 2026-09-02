<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V06_Idempotency
{
    private const ROUTE = '/takka-bridge/v1/v06';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const PREFIX = 'takka_bridge_idem_';
    private const INDEX = 'takka_bridge_idem_index';
    private const TTL = 86400;
    private const MAX_RECORDS = 500;
    private const IN_PROGRESS_TTL = 600;
    private const MAX_CLOCK_SKEW = 300;

    public static function init(): void
    {
        add_filter('rest_pre_dispatch', [self::class, 'before'], 20, 3);
        add_filter('rest_request_after_callbacks', [self::class, 'after'], 125, 3);
    }

    public static function before($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        if ($result !== null || strtoupper($request->get_method()) !== 'POST' || $request->get_route() !== self::ROUTE) {
            return $result;
        }
        if (!self::valid_auth($request)) {
            return $result;
        }
        $context = self::context($request);
        if (is_wp_error($context) || $context === null) {
            return $context === null ? $result : $context;
        }

        self::prune();
        $option = self::option_name($context['request_id']);
        $existing = get_option($option, null);
        if (is_array($existing)) {
            if (!isset($existing['payload_hash']) || !hash_equals((string) $existing['payload_hash'], $context['payload_hash'])) {
                return self::error('takka_bridge_idempotency_payload_conflict', 'request_id was already used for a different payload.', 409, [
                    'request_id' => $context['request_id'],
                ]);
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
                return self::error('takka_bridge_idempotency_in_progress', 'The same request_id is already in progress.', 409, [
                    'request_id' => $context['request_id'],
                ]);
            }
            update_option($option, self::lock($context), false);
            self::touch($option);
            return $result;
        }

        if (!add_option($option, self::lock($context), '', false)) {
            return self::error('takka_bridge_idempotency_lock_race', 'Could not acquire idempotency lock.', 409, [
                'request_id' => $context['request_id'],
            ]);
        }
        self::touch($option);
        return $result;
    }

    public static function after($response, array $handler, WP_REST_Request $request)
    {
        if (strtoupper($request->get_method()) !== 'POST' || $request->get_route() !== self::ROUTE) {
            return $response;
        }
        $context = self::context($request);
        if (is_wp_error($context) || $context === null) {
            return $response;
        }
        if (is_wp_error($response)) {
            $response = rest_convert_error_to_response($response);
        }
        $rest = rest_ensure_response($response);
        $headers = $rest->get_headers();
        if (isset($headers['X-TakKa-Idempotency-Internal'])) {
            return $response;
        }

        $existing = get_option(self::option_name($context['request_id']), null);
        update_option(self::option_name($context['request_id']), [
            'state' => 'completed',
            'request_id' => $context['request_id'],
            'payload_hash' => $context['payload_hash'],
            'action' => $context['action'],
            'created_at' => is_array($existing) && isset($existing['created_at']) ? (int) $existing['created_at'] : time(),
            'completed_at' => time(),
            'status' => $rest->get_status(),
            'headers' => $rest->get_headers(),
            'data' => $rest->get_data(),
        ], false);
        self::touch(self::option_name($context['request_id']));
        return $rest;
    }

    private static function valid_auth(WP_REST_Request $request): bool
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
        $payload = $timestamp . "\nPOST\n" . self::ROUTE . "\n" . hash('sha256', (string) $request->get_body());
        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }

    private static function context(WP_REST_Request $request)
    {
        $outer = json_decode((string) $request->get_body(), true);
        if (!is_array($outer) || !isset($outer['payload_b64']) || !is_string($outer['payload_b64'])) {
            return null;
        }
        $decoded = base64_decode($outer['payload_b64'], true);
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
            return new WP_Error('takka_bridge_invalid_request_id', 'request_id contains invalid characters or is too long.', ['status' => 400]);
        }
        return [
            'request_id' => $request_id,
            'payload_hash' => hash('sha256', $decoded),
            'action' => isset($inner['action']) && is_string($inner['action']) ? $inner['action'] : 'unknown',
        ];
    }

    private static function error(string $code, string $message, int $status, array $extra): WP_REST_Response
    {
        $response = new WP_REST_Response([
            'code' => $code,
            'message' => $message,
            'data' => array_merge(['status' => $status], $extra),
        ], $status);
        $response->header('X-TakKa-Idempotency-Internal', '1');
        return $response;
    }

    private static function lock(array $context): array
    {
        return [
            'state' => 'running',
            'request_id' => $context['request_id'],
            'payload_hash' => $context['payload_hash'],
            'action' => $context['action'],
            'created_at' => time(),
        ];
    }

    private static function option_name(string $request_id): string
    {
        return self::PREFIX . substr(hash('sha256', $request_id), 0, 40);
    }

    private static function touch(string $option): void
    {
        $index = get_option(self::INDEX, []);
        if (!is_array($index)) {
            $index = [];
        }
        $index[$option] = time();
        update_option(self::INDEX, $index, false);
    }

    private static function prune(): void
    {
        $index = get_option(self::INDEX, []);
        if (!is_array($index) || !$index) {
            return;
        }
        asort($index, SORT_NUMERIC);
        $cutoff = time() - self::TTL;
        while ($index && (count($index) > self::MAX_RECORDS || (int) reset($index) < $cutoff)) {
            $option = (string) key($index);
            delete_option($option);
            unset($index[$option]);
        }
        update_option(self::INDEX, $index, false);
    }
}
