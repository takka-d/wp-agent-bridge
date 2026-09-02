<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_Roles
{
    public static function set_role(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $role = self::role($params);
        if (is_wp_error($role)) return $role;
        $was_admin = in_array('administrator', (array) $user->roles, true);
        $will_admin = $role === 'administrator';
        $guard = self::guard_admin_change($user, $was_admin, $will_admin, $params);
        if (is_wp_error($guard)) return $guard;
        $user->set_role($role);
        return rest_ensure_response(['ok' => true, 'user' => TakKa_WordPress_Bridge_V07_Users::public_user(get_userdata((int) $user->ID))]);
    }

    public static function add_role(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $role = self::role($params);
        if (is_wp_error($role)) return $role;
        if ($role === 'administrator' && empty($params['confirm_sensitive'])) {
            return new WP_Error('takka_bridge_sensitive_confirmation_required', 'Granting administrator requires confirm_sensitive=true.', ['status' => 400]);
        }
        $user->add_role($role);
        return rest_ensure_response(['ok' => true, 'user' => TakKa_WordPress_Bridge_V07_Users::public_user(get_userdata((int) $user->ID))]);
    }

    public static function remove_role(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $user = TakKa_WordPress_Bridge_V07_Users::resolve($params);
        if (is_wp_error($user)) return $user;
        $role = self::role($params);
        if (is_wp_error($role)) return $role;
        if (!in_array($role, (array) $user->roles, true)) return new WP_Error('takka_bridge_user_role_missing', 'User does not have that role.', ['status' => 409]);
        if ($role === 'administrator') {
            $guard = self::guard_admin_change($user, true, false, $params);
            if (is_wp_error($guard)) return $guard;
        }
        $user->remove_role($role);
        return rest_ensure_response(['ok' => true, 'user' => TakKa_WordPress_Bridge_V07_Users::public_user(get_userdata((int) $user->ID))]);
    }

    private static function role(array $params)
    {
        $role = isset($params['role']) && is_string($params['role']) ? sanitize_key($params['role']) : '';
        if ($role === '' || !isset(wp_roles()->roles[$role])) return new WP_Error('takka_bridge_user_role_unknown', 'Unknown WordPress role.', ['status' => 400, 'role' => $role]);
        return $role;
    }

    private static function guard_admin_change(WP_User $user, bool $was_admin, bool $will_admin, array $params)
    {
        if ($was_admin === $will_admin) return true;
        if (empty($params['confirm_sensitive'])) return new WP_Error('takka_bridge_sensitive_confirmation_required', 'Administrator role changes require confirm_sensitive=true.', ['status' => 400]);
        if ($was_admin && !$will_admin) {
            if ((int) $user->ID === (int) get_option('takka_bridge_user_id', 0)) {
                return new WP_Error('takka_bridge_connected_admin_protected', 'The connected Bridge administrator cannot be demoted through the Bridge.', ['status' => 409]);
            }
            $admins = get_users(['role' => 'administrator', 'fields' => 'ID']);
            if (count($admins) <= 1) return new WP_Error('takka_bridge_last_admin_protected', 'The last administrator cannot be demoted.', ['status' => 409]);
        }
        return true;
    }

    private static function confirm_error(): WP_Error
    {
        return new WP_Error('takka_bridge_confirmation_required', 'Role changes require confirm=true.', ['status' => 400]);
    }
}
