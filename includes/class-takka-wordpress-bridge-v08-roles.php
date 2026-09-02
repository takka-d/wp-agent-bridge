<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V08_Roles
{
    private const CRITICAL_CAPS = [
        'manage_options', 'edit_users', 'create_users', 'promote_users', 'delete_users', 'remove_users',
        'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins',
        'install_themes', 'switch_themes', 'update_themes', 'delete_themes', 'edit_themes', 'update_core',
    ];

    public static function role_list(): array
    {
        $roles = wp_roles()->roles;
        $counts = count_users();
        $avail = isset($counts['avail_roles']) && is_array($counts['avail_roles']) ? $counts['avail_roles'] : [];
        $out = [];
        foreach ($roles as $slug => $data) {
            $caps = isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : [];
            $out[] = [
                'role' => $slug,
                'name' => isset($data['name']) ? translate_user_role($data['name']) : $slug,
                'capability_count' => count(array_filter($caps)),
                'assigned_user_count' => isset($avail[$slug]) ? (int) $avail[$slug] : 0,
            ];
        }
        return ['roles' => $out, 'count' => count($out)];
    }

    public static function role_get(array $params)
    {
        $role = self::resolve_role($params);
        if (is_wp_error($role)) return $role;
        $roles = wp_roles()->roles;
        $data = $roles[$role];
        $caps = isset($data['capabilities']) && is_array($data['capabilities']) ? $data['capabilities'] : [];
        ksort($caps, SORT_STRING);
        $users = get_users(['role' => $role, 'fields' => 'ID']);
        return rest_ensure_response([
            'role' => $role,
            'name' => isset($data['name']) ? translate_user_role($data['name']) : $role,
            'capabilities' => $caps,
            'assigned_user_count' => count($users),
        ]);
    }

    public static function role_create(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Role creation requires confirm=true.');
        $role = self::new_role_slug($params);
        if (is_wp_error($role)) return $role;
        if (get_role($role)) return new WP_Error('takka_bridge_role_exists', 'Role already exists.', ['status' => 409]);
        $name = isset($params['name']) && is_string($params['name']) ? trim($params['name']) : '';
        if ($name === '') return new WP_Error('takka_bridge_role_name_required', 'name is required.', ['status' => 400]);

        $caps = [];
        if (isset($params['clone']) && is_string($params['clone']) && trim($params['clone']) !== '') {
            $clone = sanitize_key($params['clone']);
            $source = get_role($clone);
            if (!$source) return new WP_Error('takka_bridge_role_clone_not_found', 'Clone source role was not found.', ['status' => 404]);
            $caps = (array) $source->capabilities;
            if (self::contains_critical_grant($caps) && empty($params['confirm_sensitive'])) {
                return self::sensitive_error('Cloning a role with administrator-equivalent capabilities requires confirm_sensitive=true.');
            }
        }
        $created = add_role($role, sanitize_text_field($name), $caps);
        if (!$created) return new WP_Error('takka_bridge_role_create_failed', 'WordPress did not create the role.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'role' => $role, 'name' => sanitize_text_field($name), 'cloned_capability_count' => count($caps)]);
    }

    public static function role_delete_preview(array $params)
    {
        $role = self::resolve_role($params);
        if (is_wp_error($role)) return $role;
        if ($role === 'administrator') return new WP_Error('takka_bridge_administrator_role_protected', 'The administrator role cannot be deleted.', ['status' => 409]);
        $users = array_map('intval', get_users(['role' => $role, 'fields' => 'ID']));
        sort($users, SORT_NUMERIC);
        $role_obj = get_role($role);
        $caps = $role_obj ? (array) $role_obj->capabilities : [];
        ksort($caps, SORT_STRING);
        $impact_hash = hash('sha256', wp_json_encode([$role, $users, $caps]));
        return rest_ensure_response([
            'role' => $role,
            'assigned_user_count' => count($users),
            'capability_count' => count(array_filter($caps)),
            'impact_hash' => $impact_hash,
            'deletion_allowed' => count($users) === 0,
        ]);
    }

    public static function role_delete(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Role deletion requires confirm=true.');
        $preview = self::role_delete_preview($params);
        if (is_wp_error($preview)) return $preview;
        $data = $preview->get_data();
        if ((int) $data['assigned_user_count'] > 0) {
            return new WP_Error('takka_bridge_role_in_use', 'Role deletion is blocked while users are assigned to it.', ['status' => 409, 'assigned_user_count' => (int) $data['assigned_user_count']]);
        }
        $expected = isset($params['expected_impact_hash']) && is_string($params['expected_impact_hash']) ? trim($params['expected_impact_hash']) : '';
        if ($expected === '' || !hash_equals((string) $data['impact_hash'], $expected)) {
            return new WP_Error('takka_bridge_role_impact_changed', 'Role deletion impact changed or expected_impact_hash is missing.', ['status' => 409]);
        }
        remove_role((string) $data['role']);
        if (get_role((string) $data['role'])) return new WP_Error('takka_bridge_role_delete_failed', 'Role still exists after deletion.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'deleted_role' => (string) $data['role'], 'impact_hash' => (string) $data['impact_hash']]);
    }

    public static function role_cap_add(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Capability changes require confirm=true.');
        $role = self::resolve_role($params);
        if (is_wp_error($role)) return $role;
        $cap = self::capability($params);
        if (is_wp_error($cap)) return $cap;
        if (self::is_critical($cap) && $role !== 'administrator' && empty($params['confirm_sensitive'])) {
            return self::sensitive_error('Granting an administrator-equivalent capability requires confirm_sensitive=true.');
        }
        $role_obj = get_role($role);
        $role_obj->add_cap($cap, true);
        return rest_ensure_response(['ok' => true, 'role' => $role, 'capability' => $cap, 'granted' => true]);
    }

    public static function role_cap_remove(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Capability changes require confirm=true.');
        $role = self::resolve_role($params);
        if (is_wp_error($role)) return $role;
        $cap = self::capability($params);
        if (is_wp_error($cap)) return $cap;
        if ($role === 'administrator' && self::is_critical($cap)) {
            return new WP_Error('takka_bridge_administrator_capability_protected', 'Core administrator capabilities cannot be removed through the Bridge.', ['status' => 409, 'capability' => $cap]);
        }
        $role_obj = get_role($role);
        $role_obj->remove_cap($cap);
        return rest_ensure_response(['ok' => true, 'role' => $role, 'capability' => $cap, 'granted' => false]);
    }

    public static function user_cap_list(array $params)
    {
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $role_names = array_fill_keys((array) $user->roles, true);
        $direct = [];
        foreach ((array) $user->caps as $cap => $grant) {
            if (isset($role_names[$cap])) continue;
            $direct[$cap] = (bool) $grant;
        }
        ksort($direct, SORT_STRING);
        return rest_ensure_response(['user_id' => (int) $user->ID, 'roles' => array_values((array) $user->roles), 'direct_capabilities' => $direct]);
    }

    public static function user_cap_add(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('User capability changes require confirm=true.');
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $cap = self::capability($params);
        if (is_wp_error($cap)) return $cap;
        if (self::is_critical($cap) && empty($params['confirm_sensitive'])) {
            return self::sensitive_error('Granting an administrator-equivalent capability requires confirm_sensitive=true.');
        }
        $user->add_cap($cap, true);
        return rest_ensure_response(['ok' => true, 'user_id' => (int) $user->ID, 'capability' => $cap, 'granted' => true]);
    }

    public static function user_cap_remove(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('User capability changes require confirm=true.');
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $cap = self::capability($params);
        if (is_wp_error($cap)) return $cap;
        if ((int) $user->ID === (int) get_option('takka_bridge_user_id', 0) && self::is_critical($cap)) {
            return new WP_Error('takka_bridge_connected_admin_capability_protected', 'Critical capabilities cannot be removed from the connected Bridge administrator.', ['status' => 409, 'capability' => $cap]);
        }
        $user->remove_cap($cap);
        return rest_ensure_response(['ok' => true, 'user_id' => (int) $user->ID, 'capability' => $cap, 'direct_grant_removed' => true]);
    }

    private static function resolve_role(array $params)
    {
        $role = isset($params['role']) && is_string($params['role']) ? sanitize_key($params['role']) : '';
        if ($role === '' || !get_role($role)) return new WP_Error('takka_bridge_role_not_found', 'Role was not found.', ['status' => 404, 'role' => $role]);
        return $role;
    }

    private static function new_role_slug(array $params)
    {
        if (!isset($params['role']) || !is_string($params['role'])) return new WP_Error('takka_bridge_role_required', 'role is required.', ['status' => 400]);
        $raw = trim($params['role']);
        $role = sanitize_key($raw);
        if ($role === '' || $role !== $raw || strlen($role) > 64) return new WP_Error('takka_bridge_role_invalid', 'role must already be a safe lowercase WordPress role slug.', ['status' => 400]);
        return $role;
    }

    private static function capability(array $params)
    {
        if (!isset($params['capability']) || !is_string($params['capability'])) return new WP_Error('takka_bridge_capability_required', 'capability is required.', ['status' => 400]);
        $raw = trim($params['capability']);
        $cap = sanitize_key($raw);
        if ($cap === '' || $cap !== $raw || strlen($cap) > 100) return new WP_Error('takka_bridge_capability_invalid', 'capability must already be a safe WordPress capability key.', ['status' => 400]);
        return $cap;
    }

    private static function is_critical(string $cap): bool
    {
        return in_array($cap, self::CRITICAL_CAPS, true);
    }

    private static function contains_critical_grant(array $caps): bool
    {
        foreach (self::CRITICAL_CAPS as $cap) if (!empty($caps[$cap])) return true;
        return false;
    }

    private static function confirm_error(string $message): WP_Error
    {
        return new WP_Error('takka_bridge_confirmation_required', $message, ['status' => 400]);
    }

    private static function sensitive_error(string $message): WP_Error
    {
        return new WP_Error('takka_bridge_sensitive_confirmation_required', $message, ['status' => 400]);
    }
}
