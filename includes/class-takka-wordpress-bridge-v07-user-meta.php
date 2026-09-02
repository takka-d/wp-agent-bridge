<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_User_Meta
{
    private const MAX_VALUE_BYTES = 131072;

    public static function list(array $params)
    {
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $all = get_user_meta((int) $user->ID);
        $out = [];
        foreach ($all as $key => $values) {
            if (self::sensitive($key)) continue;
            $out[$key] = array_map([self::class, 'safe_value'], (array) $values);
        }
        ksort($out, SORT_STRING);
        return rest_ensure_response(['user_id' => (int) $user->ID, 'meta' => $out]);
    }

    public static function get(array $params)
    {
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive($key)) return self::sensitive_error('read');
        if (!metadata_exists('user', (int) $user->ID, $key)) {
            return new WP_Error('takka_bridge_user_meta_not_found', 'User meta was not found.', ['status' => 404]);
        }
        $single = !isset($params['single']) || !empty($params['single']);
        return rest_ensure_response([
            'user_id' => (int) $user->ID,
            'key' => $key,
            'exists' => true,
            'value' => self::safe_value(get_user_meta((int) $user->ID, $key, $single)),
        ]);
    }

    public static function add(array $params)
    {
        return self::write('add', $params);
    }

    public static function update(array $params)
    {
        return self::write('update', $params);
    }

    public static function delete(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive($key)) return self::sensitive_error('delete');
        if (!metadata_exists('user', (int) $user->ID, $key)) {
            return new WP_Error('takka_bridge_user_meta_not_found', 'User meta was not found.', ['status' => 404]);
        }
        $result = delete_user_meta((int) $user->ID, $key);
        return rest_ensure_response(['ok' => (bool) $result, 'user_id' => (int) $user->ID, 'key' => $key]);
    }

    private static function write(string $mode, array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive($key)) return self::sensitive_error('write');
        if (!array_key_exists('value', $params)) return new WP_Error('takka_bridge_user_meta_value_required', 'value is required.', ['status' => 400]);
        if (self::contains_sensitive_nested_key($params['value'])) {
            return new WP_Error(
                'takka_bridge_user_meta_nested_sensitive_write',
                'User meta values containing credential/session-like nested keys cannot be written through the GitHub-backed Bridge.',
                ['status' => 400]
            );
        }
        $serialized = maybe_serialize($params['value']);
        if (!is_string($serialized) || strlen($serialized) > self::MAX_VALUE_BYTES) return new WP_Error('takka_bridge_user_meta_value_size', 'User meta value is too large.', ['status' => 413]);
        $result = $mode === 'add'
            ? add_user_meta((int) $user->ID, $key, $params['value'], !empty($params['unique']))
            : update_user_meta((int) $user->ID, $key, $params['value']);
        return rest_ensure_response(['ok' => $result !== false, 'user_id' => (int) $user->ID, 'key' => $key]);
    }

    private static function key(array $params)
    {
        if (!isset($params['key']) || !is_string($params['key'])) return new WP_Error('takka_bridge_user_meta_key_required', 'key is required.', ['status' => 400]);
        $key = trim($params['key']);
        if ($key === '' || strlen($key) > 191 || strpos($key, "\0") !== false) return new WP_Error('takka_bridge_user_meta_key_invalid', 'Invalid user meta key.', ['status' => 400]);
        return $key;
    }

    private static function sensitive(string $key): bool
    {
        global $wpdb;
        $lower = strtolower($key);
        $exact = [strtolower($wpdb->prefix . 'capabilities'), strtolower($wpdb->prefix . 'user_level'), 'session_tokens', '_application_passwords', 'application_passwords'];
        if (in_array($lower, $exact, true)) return true;
        if (preg_match('/(?:^|_)capabilities$|(?:^|_)user_level$/', $lower)) return true;
        return (bool) preg_match('/pass(word)?|secret|token|api[_-]?key|access[_-]?key|credential|private[_-]?key|client[_-]?secret|otp|2fa|two[_-]?factor|recovery|authenticator|totp|webauthn|session/i', $lower);
    }

    private static function contains_sensitive_nested_key($value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (is_string($key) && self::sensitive($key)) return true;
            if (self::contains_sensitive_nested_key($child)) return true;
        }
        return false;
    }

    private static function safe_value($value)
    {
        if (is_string($value) && strlen($value) > 20000) return substr($value, 0, 20000) . '<truncated>';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && self::sensitive($key)) {
                    $out[$key] = '<redacted>';
                    continue;
                }
                $out[$key] = self::safe_value($child);
            }
            return $out;
        }
        if (is_object($value)) return '<object omitted>';
        return $value;
    }

    private static function confirm_error(): WP_Error
    {
        return new WP_Error('takka_bridge_confirmation_required', 'User meta changes require confirm=true.', ['status' => 400]);
    }

    private static function sensitive_error(string $verb): WP_Error
    {
        return new WP_Error('takka_bridge_user_meta_sensitive', "Sensitive capability/session/credential meta cannot be {$verb} through the Bridge.", ['status' => 403]);
    }
}
