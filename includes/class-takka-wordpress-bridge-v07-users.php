<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V07_Users
{
    public static function list(array $params): array
    {
        $args = ['number' => max(1, min(200, (int) ($params['number'] ?? 50))), 'orderby' => 'ID', 'order' => 'ASC'];
        if (!empty($params['role']) && is_string($params['role'])) $args['role'] = sanitize_key($params['role']);
        if (!empty($params['search']) && is_string($params['search'])) {
            $args['search'] = '*' . trim($params['search']) . '*';
            $args['search_columns'] = ['user_login', 'user_nicename', 'user_email', 'display_name'];
        }
        $users = get_users($args);
        return ['users' => array_map([self::class, 'public_user'], $users), 'count' => count($users)];
    }

    public static function get(array $params)
    {
        $user = self::resolve($params);
        return is_wp_error($user) ? $user : rest_ensure_response(self::public_user($user));
    }

    public static function create(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('User creation requires confirm=true.');
        $login = isset($params['user_login']) && is_string($params['user_login']) ? sanitize_user($params['user_login'], true) : '';
        $email = isset($params['user_email']) && is_string($params['user_email']) ? sanitize_email($params['user_email']) : '';
        if ($login === '' || !is_email($email)) return new WP_Error('takka_bridge_user_create_fields', 'user_login and valid user_email are required.', ['status' => 400]);
        if (username_exists($login) || email_exists($email)) return new WP_Error('takka_bridge_user_exists', 'Login or email already exists.', ['status' => 409]);
        if (array_key_exists('password', $params)) return self::password_transport_error();

        $role = isset($params['role']) && is_string($params['role']) ? sanitize_key($params['role']) : get_option('default_role', 'subscriber');
        if (!isset(wp_roles()->roles[$role])) return new WP_Error('takka_bridge_user_role_unknown', 'Unknown WordPress role.', ['status' => 400, 'role' => $role]);
        if ($role === 'administrator' && empty($params['confirm_sensitive'])) {
            return new WP_Error('takka_bridge_sensitive_confirmation_required', 'Creating an administrator requires confirm_sensitive=true.', ['status' => 400]);
        }

        $data = ['user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(32, true, true), 'role' => $role];
        foreach (['display_name', 'first_name', 'last_name', 'description'] as $field) {
            if (isset($params[$field]) && is_string($params[$field])) $data[$field] = sanitize_text_field($params[$field]);
        }
        if (isset($params['user_url']) && is_string($params['user_url'])) $data['user_url'] = esc_url_raw($params['user_url']);
        $id = wp_insert_user($data);
        if (is_wp_error($id)) return $id;
        $notify = !array_key_exists('send_notification', $params) || !empty($params['send_notification']);
        if ($notify) wp_send_new_user_notifications((int) $id, 'user');
        return rest_ensure_response(['ok' => true, 'user' => self::public_user(get_userdata((int) $id)), 'password_returned' => false, 'notification_requested' => $notify]);
    }

    public static function update(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('User update requires confirm=true.');
        $user = self::resolve($params);
        if (is_wp_error($user)) return $user;
        if (array_key_exists('password', $params)) return self::password_transport_error();
        $data = ['ID' => (int) $user->ID];
        $sensitive = false;

        if (isset($params['user_email']) && is_string($params['user_email'])) {
            $email = sanitize_email($params['user_email']);
            if (!is_email($email)) return new WP_Error('takka_bridge_user_email_invalid', 'user_email is invalid.', ['status' => 400]);
            if (strcasecmp($email, (string) $user->user_email) !== 0) {
                $existing = email_exists($email);
                if ($existing && (int) $existing !== (int) $user->ID) return new WP_Error('takka_bridge_user_email_exists', 'Email is used by another account.', ['status' => 409]);
                $data['user_email'] = $email;
                $sensitive = true;
            }
        }
        foreach (['display_name', 'first_name', 'last_name', 'description'] as $field) {
            if (isset($params[$field]) && is_string($params[$field])) $data[$field] = sanitize_text_field($params[$field]);
        }
        if (isset($params['user_url']) && is_string($params['user_url'])) $data['user_url'] = esc_url_raw($params['user_url']);
        if (count($data) === 1) return new WP_Error('takka_bridge_user_no_changes', 'No supported user fields were provided.', ['status' => 400]);
        if ($sensitive && empty($params['confirm_sensitive'])) return new WP_Error('takka_bridge_sensitive_confirmation_required', 'Changing email requires confirm_sensitive=true.', ['status' => 400]);
        $id = wp_update_user($data);
        if (is_wp_error($id)) return $id;
        return rest_ensure_response(['ok' => true, 'user' => self::public_user(get_userdata((int) $id))]);
    }

    public static function password_reset_email(array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error('Password reset email requires confirm=true.');
        if (empty($params['confirm_sensitive'])) return new WP_Error('takka_bridge_sensitive_confirmation_required', 'Password reset email requires confirm_sensitive=true.', ['status' => 400]);
        $user = self::resolve($params);
        if (is_wp_error($user)) return $user;
        $sent = retrieve_password($user->user_login);
        if ($sent !== true) return is_wp_error($sent) ? $sent : new WP_Error('takka_bridge_password_reset_failed', 'WordPress could not send the password reset email.', ['status' => 500]);
        return rest_ensure_response(['ok' => true, 'user_id' => (int) $user->ID, 'email_sent' => true, 'reset_key_returned' => false]);
    }

    public static function resolve(array $params)
    {
        $user = false;
        if (isset($params['user_id'])) $user = get_userdata(absint($params['user_id']));
        elseif (!empty($params['user_login']) && is_string($params['user_login'])) $user = get_user_by('login', sanitize_user($params['user_login'], true));
        elseif (!empty($params['user_email']) && is_string($params['user_email'])) $user = get_user_by('email', sanitize_email($params['user_email']));
        return $user instanceof WP_User ? $user : new WP_Error('takka_bridge_user_not_found', 'User was not found.', ['status' => 404]);
    }

    public static function public_user(WP_User $user): array
    {
        return [
            'id' => (int) $user->ID, 'login' => $user->user_login, 'nicename' => $user->user_nicename,
            'email' => $user->user_email, 'display_name' => $user->display_name, 'first_name' => $user->first_name,
            'last_name' => $user->last_name, 'url' => $user->user_url, 'registered' => $user->user_registered,
            'roles' => array_values((array) $user->roles),
        ];
    }

    private static function confirm_error(string $message): WP_Error
    {
        return new WP_Error('takka_bridge_confirmation_required', $message, ['status' => 400]);
    }

    private static function password_transport_error(): WP_Error
    {
        return new WP_Error('takka_bridge_user_password_transport_blocked', 'Plaintext password values are blocked on the GitHub queue transport. Use generated passwords and user.password.reset_email.', ['status' => 400]);
    }
}
