<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08_Options
{
    private const MAX_VALUE_BYTES = 1048576;
    private const MAX_LIST = 200;
    private const MAX_PATH_DEPTH = 16;

    public static function list(array $params): array
    {
        global $wpdb;
        $search = isset($params['search']) && is_string($params['search']) ? trim($params['search']) : '';
        $limit = isset($params['limit']) ? max(1, min(self::MAX_LIST, (int) $params['limit'])) : 100;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;
        $autoload = isset($params['autoload']) && is_string($params['autoload']) ? strtolower(trim($params['autoload'])) : '';

        $where = ['1=1'];
        $args = [];
        if ($search !== '') {
            $where[] = 'option_name LIKE %s';
            $args[] = '%' . $wpdb->esc_like($search) . '%';
        }
        if ($autoload !== '') {
            if (!in_array($autoload, ['on', 'off'], true)) return ['error' => 'autoload must be on or off'];
            $where[] = $autoload === 'on'
                ? "autoload IN ('yes','on','auto-on','auto')"
                : "autoload IN ('no','off','auto-off')";
        }
        $where_sql = implode(' AND ', $where);
        $count_sql = "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where_sql}";
        $list_sql = "SELECT option_name, autoload, LENGTH(option_value) AS value_bytes FROM {$wpdb->options} WHERE {$where_sql} ORDER BY option_name ASC LIMIT %d OFFSET %d";
        if ($args) {
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $args));
            $query_args = array_merge($args, [$limit, $offset]);
            $rows = $wpdb->get_results($wpdb->prepare($list_sql, $query_args), ARRAY_A);
        } else {
            $total = (int) $wpdb->get_var($count_sql);
            $rows = $wpdb->get_results($wpdb->prepare($list_sql, $limit, $offset), ARRAY_A);
        }
        $out = [];
        foreach ((array) $rows as $row) {
            $name = (string) $row['option_name'];
            $out[] = [
                'name' => $name,
                'autoload' => (string) $row['autoload'],
                'value_bytes' => (int) $row['value_bytes'],
                'readable' => !self::is_sensitive_name($name),
                'patchable' => self::is_patchable_name($name),
            ];
        }
        return [
            'options' => $out,
            'returned' => count($out),
            'total_matching' => $total,
            'offset' => $offset,
            'truncated' => ($offset + count($out)) < $total,
        ];
    }

    public static function get(array $params)
    {
        $name = self::option_name($params);
        if (is_wp_error($name)) return $name;
        $guard = self::read_guard($name);
        if (is_wp_error($guard)) return $guard;
        if (!self::exists($name)) return self::not_found($name);
        $value = get_option($name);
        return rest_ensure_response(self::describe($name, $value));
    }

    public static function pluck(array $params)
    {
        $name = self::option_name($params);
        if (is_wp_error($name)) return $name;
        $guard = self::read_guard($name);
        if (is_wp_error($guard)) return $guard;
        if (!self::exists($name)) return self::not_found($name);
        $path = self::path($params);
        if (is_wp_error($path)) return $path;
        $value = get_option($name);
        $result = self::path_get($value, $path);
        if (!$result['found']) return new WP_Error('takka_bridge_option_path_not_found', 'Option path was not found.', ['status' => 404, 'name' => $name, 'path' => $path]);
        return rest_ensure_response([
            'name' => $name,
            'path' => $path,
            'value' => self::safe_value($result['value']),
            'value_type' => gettype($result['value']),
        ]);
    }

    public static function patch_preview(array $params)
    {
        $plan = self::build_plan($params);
        if (is_wp_error($plan)) return $plan;
        return rest_ensure_response($plan);
    }

    public static function patch(array $params)
    {
        if (empty($params['confirm'])) return new WP_Error('takka_bridge_confirmation_required', 'Option patch requires confirm=true.', ['status' => 400]);
        $plan = self::build_plan($params);
        if (is_wp_error($plan)) return $plan;
        $expected = isset($params['expected_plan_hash']) && is_string($params['expected_plan_hash']) ? trim($params['expected_plan_hash']) : '';
        if ($expected === '' || !hash_equals((string) $plan['plan_hash'], $expected)) {
            return new WP_Error('takka_bridge_option_plan_changed', 'Option patch plan changed or expected_plan_hash is missing.', ['status' => 409, 'current_plan_hash' => $plan['plan_hash']]);
        }
        $updated = update_option((string) $plan['name'], $plan['_after_value'], null);
        $current = get_option((string) $plan['name']);
        $after_hash = self::value_hash($current);
        if (!hash_equals((string) $plan['after_sha256'], $after_hash)) {
            return new WP_Error('takka_bridge_option_patch_verify_failed', 'Option value did not match the planned value after write.', ['status' => 500]);
        }
        $response = $plan;
        unset($response['_after_value']);
        $response['ok'] = true;
        $response['update_option_returned'] = (bool) $updated;
        return rest_ensure_response($response);
    }

    private static function build_plan(array $params)
    {
        $name = self::option_name($params);
        if (is_wp_error($name)) return $name;
        if (!self::is_patchable_name($name)) {
            return new WP_Error('takka_bridge_option_patch_protected', 'This option is protected from Bridge patching.', ['status' => 403, 'name' => $name]);
        }
        if (!self::exists($name)) return self::not_found($name);
        $path = self::path($params);
        if (is_wp_error($path)) return $path;
        foreach ($path as $segment) {
            if (is_string($segment) && self::is_sensitive_key($segment)) {
                return new WP_Error('takka_bridge_option_sensitive_path', 'Credential/session-like option paths cannot be accessed.', ['status' => 403]);
            }
        }
        $operation = isset($params['operation']) && is_string($params['operation']) ? strtolower(trim($params['operation'])) : '';
        if (!in_array($operation, ['insert', 'update', 'delete'], true)) {
            return new WP_Error('takka_bridge_option_patch_operation', 'operation must be insert, update, or delete.', ['status' => 400]);
        }
        $before = get_option($name);
        if (!is_array($before)) {
            return new WP_Error('takka_bridge_option_patch_array_only', 'Surgical option patching currently supports array-valued options only.', ['status' => 409, 'stored_type' => gettype($before)]);
        }
        if (strlen(maybe_serialize($before)) > self::MAX_VALUE_BYTES) {
            return new WP_Error('takka_bridge_option_value_too_large', 'Option is too large for surgical patching.', ['status' => 413]);
        }
        $current = self::path_get($before, $path);
        if ($operation === 'insert' && $current['found']) return new WP_Error('takka_bridge_option_path_exists', 'Insert target already exists.', ['status' => 409]);
        if (in_array($operation, ['update', 'delete'], true) && !$current['found']) return new WP_Error('takka_bridge_option_path_not_found', 'Patch target does not exist.', ['status' => 404]);
        if ($operation !== 'delete' && !array_key_exists('value', $params)) return new WP_Error('takka_bridge_option_patch_value_required', 'value is required for insert/update.', ['status' => 400]);
        if ($operation !== 'delete' && self::contains_sensitive_nested_key($params['value'])) {
            return new WP_Error('takka_bridge_option_nested_sensitive_write', 'Option values containing credential/session-like nested keys cannot be written through the GitHub-backed Bridge.', ['status' => 400]);
        }

        $after = $before;
        $ok = $operation === 'delete'
            ? self::path_delete($after, $path)
            : self::path_set($after, $path, $params['value'], $operation === 'insert');
        if (!$ok) return new WP_Error('takka_bridge_option_patch_failed', 'Could not construct option patch.', ['status' => 409]);
        if (strlen(maybe_serialize($after)) > self::MAX_VALUE_BYTES) return new WP_Error('takka_bridge_option_value_too_large', 'Patched option would exceed the Bridge size limit.', ['status' => 413]);

        $before_hash = self::value_hash($before);
        $after_hash = self::value_hash($after);
        $canonical = wp_json_encode([$name, $operation, $path, $before_hash, $after_hash]);
        return [
            'name' => $name,
            'operation' => $operation,
            'path' => $path,
            'before_sha256' => $before_hash,
            'after_sha256' => $after_hash,
            'target_before' => $current['found'] ? self::safe_value($current['value']) : null,
            'target_after' => $operation === 'delete' ? null : self::safe_value($params['value']),
            'plan_hash' => hash('sha256', $canonical),
            '_after_value' => $after,
        ];
    }

    private static function describe(string $name, $value): array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT autoload, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name), ARRAY_A);
        $raw = is_array($row) ? (string) $row['option_value'] : '';
        return [
            'name' => $name,
            'value' => self::safe_value($value),
            'value_type' => gettype($value),
            'serialized_storage' => is_serialized($raw),
            'autoload' => is_array($row) ? (string) $row['autoload'] : null,
            'value_bytes' => strlen($raw),
            'sha256' => self::value_hash($value),
        ];
    }

    private static function option_name(array $params)
    {
        if (!isset($params['name']) || !is_string($params['name'])) return new WP_Error('takka_bridge_option_name_required', 'name is required.', ['status' => 400]);
        $name = trim($params['name']);
        if ($name === '' || strlen($name) > 191 || strpos($name, "\0") !== false) return new WP_Error('takka_bridge_option_name_invalid', 'Invalid option name.', ['status' => 400]);
        return $name;
    }

    private static function read_guard(string $name)
    {
        if (!self::is_sensitive_name($name)) return true;
        return new WP_Error('takka_bridge_option_sensitive', 'Sensitive credential/security option values cannot be read through the Bridge.', ['status' => 403, 'name' => $name]);
    }

    private static function is_patchable_name(string $name): bool
    {
        if (self::is_sensitive_name($name)) return false;
        if (strpos($name, 'takka_bridge_') === 0) return false;
        return !in_array($name, ['siteurl', 'home', 'active_plugins', 'stylesheet', 'template', 'cron', 'users_can_register', 'default_role'], true);
    }

    private static function is_sensitive_name(string $name): bool
    {
        if (strpos($name, 'takka_bridge_') === 0) return true;
        return self::is_sensitive_key($name);
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

    private static function contains_sensitive_nested_key($value): bool
    {
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (is_string($key) && self::is_sensitive_key($key)) return true;
            if (self::contains_sensitive_nested_key($child)) return true;
        }
        return false;
    }

    private static function path(array $params)
    {
        if (!array_key_exists('path', $params)) return new WP_Error('takka_bridge_option_path_required', 'path is required.', ['status' => 400]);
        $path = $params['path'];
        if (is_string($path)) {
            if ($path === '') return new WP_Error('takka_bridge_option_path_empty', 'path must not be empty.', ['status' => 400]);
            $path = explode('.', $path);
        }
        if (!is_array($path) || !$path || count($path) > self::MAX_PATH_DEPTH) return new WP_Error('takka_bridge_option_path_invalid', 'path must be a non-empty array or dot path within the depth limit.', ['status' => 400]);
        $out = [];
        foreach ($path as $segment) {
            if (is_int($segment)) {
                $out[] = $segment;
                continue;
            }
            if (!is_string($segment) || $segment === '' || strlen($segment) > 191 || strpos($segment, "\0") !== false) return new WP_Error('takka_bridge_option_path_segment', 'Invalid option path segment.', ['status' => 400]);
            if (ctype_digit($segment) && (string) (int) $segment === $segment) $out[] = (int) $segment;
            else $out[] = $segment;
        }
        return $out;
    }

    private static function path_get($root, array $path): array
    {
        $cursor = $root;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) return ['found' => false, 'value' => null];
            $cursor = $cursor[$segment];
        }
        return ['found' => true, 'value' => $cursor];
    }

    private static function path_set(array &$root, array $path, $value, bool $insert): bool
    {
        $cursor =& $root;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) return false;
            $cursor =& $cursor[$segment];
        }
        if ($insert && array_key_exists($last, $cursor)) return false;
        $cursor[$last] = $value;
        return true;
    }

    private static function path_delete(array &$root, array $path): bool
    {
        $cursor =& $root;
        $last = array_pop($path);
        foreach ($path as $segment) {
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) return false;
            $cursor =& $cursor[$segment];
        }
        if (!array_key_exists($last, $cursor)) return false;
        unset($cursor[$last]);
        return true;
    }

    private static function safe_value($value)
    {
        if (is_string($value) && strlen($value) > 20000) return substr($value, 0, 20000) . '<truncated>';
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && self::is_sensitive_key($key)) $out[$key] = '<redacted>';
                else $out[$key] = self::safe_value($child);
            }
            return $out;
        }
        if (is_object($value)) return '<object omitted>';
        return $value;
    }

    private static function exists(string $name): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name));
    }

    private static function not_found(string $name): WP_Error
    {
        return new WP_Error('takka_bridge_option_not_found', 'Option was not found.', ['status' => 404, 'name' => $name]);
    }

    private static function value_hash($value): string
    {
        return hash('sha256', maybe_serialize($value));
    }
}
