<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_Settings
{
    public static function theme_mod_list()
    {
        $mods = get_theme_mods();
        if (!is_array($mods)) $mods = [];
        $out = [];
        $redacted_count = 0;
        foreach ($mods as $key => $value) {
            $key = (string) $key;
            if (self::is_sensitive_key($key)) {
                $redacted_count++;
                continue;
            }
            $out[$key] = self::safe_value($value);
        }
        ksort($out, SORT_STRING);
        return rest_ensure_response([
            'stylesheet' => get_stylesheet(),
            'mods' => $out,
            'count' => count($out),
            'redacted_count' => $redacted_count,
        ]);
    }

    public static function theme_mod_get(array $params)
    {
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        $sensitive = self::reject_sensitive_key($key);
        if (is_wp_error($sensitive)) return $sensitive;
        $sentinel = new stdClass();
        $value = get_theme_mod($key, $sentinel);
        if ($value === $sentinel) return new WP_Error('takka_bridge_theme_mod_not_found', 'Theme mod was not found.', ['status' => 404]);
        return rest_ensure_response(['stylesheet' => get_stylesheet(), 'key' => $key, 'value' => self::safe_value($value)]);
    }

    public static function theme_mod_set(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Theme mod changes require confirm=true.');
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        $sensitive = self::reject_sensitive_key($key);
        if (is_wp_error($sensitive)) return $sensitive;
        if ($key === 'nav_menu_locations') return new WP_Error('takka_bridge_theme_mod_menu_locations_blocked', 'Use menu.locations.set for nav_menu_locations.', ['status' => 400]);
        if (!array_key_exists('value', $params)) return new WP_Error('takka_bridge_theme_mod_value_required', 'value is required.', ['status' => 400]);
        if (self::contains_sensitive_nested_key($params['value'])) {
            return new WP_Error(
                'takka_bridge_theme_mod_nested_sensitive_write',
                'Theme mod values containing credential/session-like nested keys cannot be written through the GitHub-backed Bridge.',
                ['status' => 400]
            );
        }
        $serialized = maybe_serialize($params['value']);
        if (!is_string($serialized) || strlen($serialized) > 131072) return new WP_Error('takka_bridge_theme_mod_value_size', 'Theme mod value is too large.', ['status' => 413]);
        $before = get_theme_mod($key, null);
        set_theme_mod($key, $params['value']);
        $after = get_theme_mod($key, null);
        return rest_ensure_response(['ok' => true, 'stylesheet' => get_stylesheet(), 'key' => $key, 'before_sha256' => hash('sha256', maybe_serialize($before)), 'after_sha256' => hash('sha256', maybe_serialize($after))]);
    }

    public static function theme_mod_remove(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Theme mod removal requires confirm=true.');
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        $sensitive = self::reject_sensitive_key($key);
        if (is_wp_error($sensitive)) return $sensitive;
        if ($key === 'nav_menu_locations') return new WP_Error('takka_bridge_theme_mod_menu_locations_blocked', 'Use menu.locations.set for nav_menu_locations.', ['status' => 400]);
        $before = get_theme_mod($key, null);
        remove_theme_mod($key);
        return rest_ensure_response(['ok' => true, 'stylesheet' => get_stylesheet(), 'key' => $key, 'removed_sha256' => hash('sha256', maybe_serialize($before))]);
    }

    public static function rewrite_status(): array
    {
        global $wp_rewrite;
        return [
            'permalink_structure' => (string) get_option('permalink_structure', ''),
            'category_base' => (string) get_option('category_base', ''),
            'tag_base' => (string) get_option('tag_base', ''),
            'using_permalinks' => (bool) $wp_rewrite->using_permalinks(),
        ];
    }

    public static function rewrite_set(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Permalink changes require confirm=true.');
        global $wp_rewrite;
        $before = self::rewrite_status();
        $changed = false;

        if (array_key_exists('structure', $params)) {
            if (!is_string($params['structure'])) return new WP_Error('takka_bridge_rewrite_structure_type', 'structure must be a string.', ['status' => 400]);
            $structure = trim($params['structure']);
            $valid = self::validate_structure($structure);
            if (is_wp_error($valid)) return $valid;
            $wp_rewrite->set_permalink_structure($structure);
            $changed = true;
        }
        foreach (['category_base', 'tag_base'] as $key) {
            if (!array_key_exists($key, $params)) continue;
            if (!is_string($params[$key])) return new WP_Error('takka_bridge_rewrite_base_type', "{$key} must be a string.", ['status' => 400]);
            $value = trim($params[$key], " /\t\n\r\0\x0B");
            if ($value !== '' && !preg_match('/^[A-Za-z0-9._~%+\/-]+$/', $value)) return new WP_Error('takka_bridge_rewrite_base_invalid', "{$key} contains unsupported characters.", ['status' => 400]);
            update_option($key, $value);
            $changed = true;
        }
        if (!$changed) return new WP_Error('takka_bridge_rewrite_no_changes', 'No permalink fields were provided.', ['status' => 400]);
        flush_rewrite_rules(false);
        return rest_ensure_response(['ok' => true, 'before' => $before, 'after' => self::rewrite_status(), 'rewrite_rules_flushed' => true]);
    }

    private static function key(array $params)
    {
        if (!isset($params['key']) || !is_string($params['key'])) return new WP_Error('takka_bridge_theme_mod_key_required', 'key is required.', ['status' => 400]);
        $key = trim($params['key']);
        if ($key === '' || strlen($key) > 191 || strpos($key, "\0") !== false) return new WP_Error('takka_bridge_theme_mod_key_invalid', 'Invalid theme mod key.', ['status' => 400]);
        return $key;
    }

    private static function reject_sensitive_key(string $key)
    {
        if (!self::is_sensitive_key($key)) return true;
        return new WP_Error(
            'takka_bridge_theme_mod_sensitive',
            'Sensitive credential/session theme mods are not accessible through the Bridge.',
            ['status' => 403]
        );
    }

    private static function is_sensitive_key(string $key): bool
    {
        $normalized = strtolower($key);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        if (!is_string($normalized)) return true;
        foreach ([
            'password', 'passwd', 'secret', 'token', 'api_key', 'apikey',
            'access_key', 'accesskey', 'credential', 'private_key', 'privatekey',
            'client_secret', 'otp', '2fa', 'recovery', 'totp', 'webauthn', 'session',
        ] as $needle) {
            if (strpos($normalized, $needle) !== false) return true;
        }
        return false;
    }

    private static function contains_sensitive_nested_key($value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (is_string($key) && self::is_sensitive_key($key)) return true;
            if (self::contains_sensitive_nested_key($child)) return true;
        }
        return false;
    }

    private static function validate_structure(string $structure)
    {
        if ($structure === '') return true;
        if ($structure[0] !== '/' || substr($structure, -1) !== '/') return new WP_Error('takka_bridge_rewrite_structure_shape', 'Non-empty permalink structure must begin and end with /.', ['status' => 400]);
        if (preg_match('/[<>"\'`\\]/', $structure)) return new WP_Error('takka_bridge_rewrite_structure_chars', 'Permalink structure contains blocked characters.', ['status' => 400]);
        preg_match_all('/%([^%]+)%/', $structure, $matches);
        $allowed = ['year', 'monthnum', 'day', 'hour', 'minute', 'second', 'post_id', 'postname', 'category', 'author'];
        foreach ($matches[1] as $token) if (!in_array($token, $allowed, true)) return new WP_Error('takka_bridge_rewrite_structure_token', 'Unsupported rewrite token.', ['status' => 400, 'token' => $token]);
        return true;
    }

    private static function safe_value($value)
    {
        if (is_string($value) && strlen($value) > 20000) return substr($value, 0, 20000) . '<truncated>';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && self::is_sensitive_key($key)) {
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

    private static function confirm_error(string $message): WP_Error { return new WP_Error('takka_bridge_confirmation_required', $message, ['status' => 400]); }
}
