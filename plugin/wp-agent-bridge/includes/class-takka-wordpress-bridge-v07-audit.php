<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_Audit
{
    private const OPTION = 'takka_bridge_audit_log';
    private const MAX_ENTRIES = 1000;

    public static function record(string $request_id, string $action, array $params, $result): void
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }

        $status = 200;
        $ok = true;
        $error_code = null;
        if (is_wp_error($result)) {
            $ok = false;
            $error_code = $result->get_error_code();
            $data = $result->get_error_data();
            if (is_array($data) && isset($data['status'])) {
                $status = (int) $data['status'];
            } else {
                $status = 500;
            }
        } elseif ($result instanceof WP_REST_Response) {
            $status = (int) $result->get_status();
            $ok = $status < 400;
        }

        $entries[] = [
            'id' => self::entry_id(),
            'timestamp' => time(),
            'request_id' => $request_id,
            'action' => $action,
            'bridge_user_id' => (int) get_option('takka_bridge_user_id', 0),
            'status' => $status,
            'ok' => $ok,
            'error_code' => $error_code,
            'params' => self::sanitize_params($params),
        ];

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }
        update_option(self::OPTION, $entries, false);
    }

    public static function list(array $params): array
    {
        $entries = get_option(self::OPTION, []);
        if (!is_array($entries)) {
            $entries = [];
        }
        $action = isset($params['action']) && is_string($params['action']) ? trim($params['action']) : '';
        if ($action !== '') {
            $entries = array_values(array_filter($entries, static function ($entry) use ($action) {
                return is_array($entry) && (($entry['action'] ?? '') === $action);
            }));
        }
        $limit = isset($params['limit']) ? max(1, min(200, (int) $params['limit'])) : 50;
        $entries = array_slice($entries, -$limit);
        $entries = array_reverse($entries);
        return [
            'entries' => $entries,
            'returned' => count($entries),
            'retained_max' => self::MAX_ENTRIES,
            'bridge_delete_supported' => false,
        ];
    }

    private static function sanitize_params(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $name = strtolower((string) $key);
            if (preg_match('/pass(word)?|secret|token|api[_-]?key|nonce|otp|2fa|two[_-]?factor|recovery/i', $name)) {
                $out[$key] = '<redacted>';
                continue;
            }
            if ($name === 'value' || $name === 'meta_value') {
                $out[$key] = '<value omitted>';
                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::sanitize_params($value);
            } elseif (is_scalar($value) || $value === null) {
                $text = is_string($value) ? $value : $value;
                if (is_string($text) && strlen($text) > 500) {
                    $text = substr($text, 0, 500) . '<truncated>';
                }
                $out[$key] = $text;
            } else {
                $out[$key] = '<non-scalar omitted>';
            }
        }
        return $out;
    }

    private static function entry_id(): string
    {
        try {
            $suffix = bin2hex(random_bytes(6));
        } catch (Throwable $e) {
            $suffix = substr(hash('sha256', microtime(true) . wp_rand()), 0, 12);
        }
        return gmdate('YmdHis') . '-' . $suffix;
    }
}
