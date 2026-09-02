<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08_Option_Delete
{
    public static function preview(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $impact_hash = hash('sha256', wp_json_encode([$context['name'], $context['sha256'], $context['autoload'], $context['value_bytes']]));
        return rest_ensure_response([
            'name' => $context['name'],
            'value_type' => gettype($context['value']),
            'value_preview' => self::safe_value($context['value']),
            'sha256' => $context['sha256'],
            'autoload' => $context['autoload'],
            'value_bytes' => $context['value_bytes'],
            'impact_hash' => $impact_hash,
        ]);
    }

    public static function delete(array $params)
    {
        if (empty($params['confirm'])) return new WP_Error('takka_bridge_confirmation_required', 'Option deletion requires confirm=true.', ['status' => 400]);
        $preview = self::preview($params);
        if (is_wp_error($preview)) return $preview;
        $data = $preview->get_data();
        $expected = isset($params['expected_impact_hash']) && is_string($params['expected_impact_hash']) ? trim($params['expected_impact_hash']) : '';
        if ($expected === '' || !hash_equals((string) $data['impact_hash'], $expected)) {
            return new WP_Error('takka_bridge_option_delete_impact_changed', 'Option deletion impact changed or expected_impact_hash is missing.', ['status' => 409, 'current_impact_hash' => $data['impact_hash']]);
        }
        $deleted = delete_option((string) $data['name']);
        if (self::exists((string) $data['name'])) return new WP_Error('takka_bridge_option_delete_failed', 'Option still exists after deletion.', ['status' => 500]);
        return rest_ensure_response(['ok' => (bool) $deleted, 'deleted_name' => (string) $data['name'], 'impact_hash' => (string) $data['impact_hash']]);
    }

    private static function context(array $params)
    {
        global $wpdb;
        if (!isset($params['name']) || !is_string($params['name'])) return new WP_Error('takka_bridge_option_name_required', 'name is required.', ['status' => 400]);
        $name = trim($params['name']);
        if ($name === '' || strlen($name) > 191 || strpos($name, "\0") !== false) return new WP_Error('takka_bridge_option_name_invalid', 'Invalid option name.', ['status' => 400]);
        if (!self::deletable($name)) return new WP_Error('takka_bridge_option_delete_protected', 'This option is protected from Bridge deletion.', ['status' => 403, 'name' => $name]);
        $row = $wpdb->get_row($wpdb->prepare("SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name), ARRAY_A);
        if (!is_array($row)) return new WP_Error('takka_bridge_option_not_found', 'Option was not found.', ['status' => 404, 'name' => $name]);
        $raw = (string) $row['option_value'];
        $value = maybe_unserialize($raw);
        return [
            'name' => $name,
            'value' => $value,
            'sha256' => hash('sha256', maybe_serialize($value)),
            'autoload' => (string) $row['autoload'],
            'value_bytes' => strlen($raw),
        ];
    }

    private static function deletable(string $name): bool
    {
        if (strpos($name, 'takka_bridge_') === 0) return false;
        if (in_array($name, ['siteurl', 'home', 'active_plugins', 'stylesheet', 'template', 'cron', 'users_can_register', 'default_role'], true)) return false;
        $normalized = strtolower($name);
        $normalized = preg_replace('/[^a-z0-9]+/', '_', $normalized);
        if (!is_string($normalized)) return false;
        foreach (['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'access_key', 'accesskey', 'credential', 'private_key', 'privatekey', 'client_secret', 'auth_key', 'salt', 'otp', '2fa', 'recovery', 'totp', 'webauthn', 'session'] as $needle) {
            if (strpos($normalized, $needle) !== false) return false;
        }
        return true;
    }

    private static function exists(string $name): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    }

    private static function safe_value($value)
    {
        if (is_string($value)) return strlen($value) > 500 ? substr($value, 0, 500) . '<truncated>' : $value;
        if (is_array($value)) {
            $out = [];
            $count = 0;
            foreach ($value as $key => $child) {
                if (++$count > 30) { $out['<truncated>'] = true; break; }
                $out[$key] = self::safe_value($child);
            }
            return $out;
        }
        if (is_object($value)) return '<object omitted>';
        return $value;
    }
}
