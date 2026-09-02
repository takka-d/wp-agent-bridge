<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V083_Post_Meta
{
    private const MAX_VALUE_BYTES = 131072;
    private const MAX_LIST = 500;

    public static function list(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;

        $all = get_post_meta($context['post_id']);
        if (!is_array($all)) $all = [];
        ksort($all, SORT_STRING);

        $search = isset($params['search']) && is_string($params['search']) ? strtolower(trim($params['search'])) : '';
        $visible = [];
        $redacted_count = 0;
        foreach ($all as $key => $values) {
            $key = (string) $key;
            if ($search !== '' && strpos(strtolower($key), $search) === false) continue;
            if (self::sensitive_key($key)) {
                $redacted_count++;
                continue;
            }
            $visible[$key] = [
                'protected' => (bool) is_protected_meta($key, 'post'),
                'values' => array_map([self::class, 'safe_value'], (array) $values),
            ];
        }

        $total = count($visible);
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;
        $limit = isset($params['limit']) ? max(1, min(self::MAX_LIST, (int) $params['limit'])) : 200;
        $page = array_slice($visible, $offset, $limit, true);

        return rest_ensure_response([
            'post_id' => $context['post_id'],
            'post_type' => $context['post_type'],
            'meta' => $page,
            'returned' => count($page),
            'total_visible' => $total,
            'redacted_key_count' => $redacted_count,
            'offset' => $offset,
            'truncated' => ($offset + count($page)) < $total,
        ]);
    }

    public static function get(array $params)
    {
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive_key($key)) return self::sensitive_error('read');
        if (!metadata_exists('post', $context['post_id'], $key)) {
            return new WP_Error('takka_bridge_post_meta_not_found', 'Post meta was not found.', ['status' => 404, 'key' => $key]);
        }

        $single = !isset($params['single']) || !empty($params['single']);
        $value = get_post_meta($context['post_id'], $key, $single);
        return rest_ensure_response([
            'post_id' => $context['post_id'],
            'post_type' => $context['post_type'],
            'key' => $key,
            'exists' => true,
            'protected' => (bool) is_protected_meta($key, 'post'),
            'value' => self::safe_value($value),
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
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive_key($key)) return self::sensitive_error('delete');
        $guard = self::protected_write_guard($key, $params);
        if (is_wp_error($guard)) return $guard;
        if (!metadata_exists('post', $context['post_id'], $key)) {
            return new WP_Error('takka_bridge_post_meta_not_found', 'Post meta was not found.', ['status' => 404, 'key' => $key]);
        }

        $before = get_post_meta($context['post_id'], $key, false);
        $before_hash = hash('sha256', maybe_serialize($before));
        $deleted = delete_post_meta($context['post_id'], $key);
        $exists_after = metadata_exists('post', $context['post_id'], $key);

        return rest_ensure_response([
            'ok' => (bool) $deleted && !$exists_after,
            'post_id' => $context['post_id'],
            'key' => $key,
            'protected' => (bool) is_protected_meta($key, 'post'),
            'removed_sha256' => $before_hash,
            'exists_after' => $exists_after,
        ]);
    }

    private static function write(string $mode, array $params)
    {
        if (empty($params['confirm'])) return self::confirm_error();
        $context = self::context($params);
        if (is_wp_error($context)) return $context;
        $key = self::key($params);
        if (is_wp_error($key)) return $key;
        if (self::sensitive_key($key)) return self::sensitive_error('write');
        $guard = self::protected_write_guard($key, $params);
        if (is_wp_error($guard)) return $guard;
        if (!array_key_exists('value', $params)) {
            return new WP_Error('takka_bridge_post_meta_value_required', 'value is required.', ['status' => 400]);
        }
        if (self::contains_sensitive_nested_key($params['value'])) {
            return new WP_Error(
                'takka_bridge_post_meta_nested_sensitive_write',
                'Post meta values containing credential/session-like nested keys cannot be written through the GitHub-backed Bridge.',
                ['status' => 400]
            );
        }

        $serialized = maybe_serialize($params['value']);
        if (!is_string($serialized) || strlen($serialized) > self::MAX_VALUE_BYTES) {
            return new WP_Error('takka_bridge_post_meta_value_size', 'Post meta value is too large.', ['status' => 413]);
        }

        $before = get_post_meta($context['post_id'], $key, false);
        $before_hash = hash('sha256', maybe_serialize($before));
        if ($mode === 'add') {
            $result = add_post_meta($context['post_id'], $key, $params['value'], !empty($params['unique']));
        } else {
            $result = update_post_meta($context['post_id'], $key, $params['value']);
        }
        if ($result === false) {
            return new WP_Error('takka_bridge_post_meta_write_failed', 'Post meta write failed or made no change.', ['status' => 409]);
        }

        $after = get_post_meta($context['post_id'], $key, false);
        return rest_ensure_response([
            'ok' => true,
            'post_id' => $context['post_id'],
            'key' => $key,
            'protected' => (bool) is_protected_meta($key, 'post'),
            'before_sha256' => $before_hash,
            'after_sha256' => hash('sha256', maybe_serialize($after)),
            'exists_after' => metadata_exists('post', $context['post_id'], $key),
        ]);
    }

    private static function context(array $params)
    {
        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        if ($post_id < 1) return new WP_Error('takka_bridge_post_id_required', 'post_id is required.', ['status' => 400]);
        $post = get_post($post_id);
        if (!$post) return new WP_Error('takka_bridge_post_not_found', 'Post was not found.', ['status' => 404]);
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('takka_bridge_post_meta_forbidden', 'Connected user cannot read or change meta for this post.', ['status' => 403, 'post_id' => $post_id]);
        }
        return ['post_id' => $post_id, 'post_type' => $post->post_type];
    }

    private static function key(array $params)
    {
        if (!isset($params['key']) || !is_string($params['key'])) {
            return new WP_Error('takka_bridge_post_meta_key_required', 'key is required.', ['status' => 400]);
        }
        $key = trim($params['key']);
        if ($key === '' || strlen($key) > 191 || strpos($key, "\0") !== false) {
            return new WP_Error('takka_bridge_post_meta_key_invalid', 'Invalid post meta key.', ['status' => 400]);
        }
        return $key;
    }

    private static function protected_write_guard(string $key, array $params)
    {
        if (is_protected_meta($key, 'post') && empty($params['confirm_sensitive'])) {
            return new WP_Error(
                'takka_bridge_post_meta_protected_confirmation_required',
                'Writing or deleting protected/private post meta requires confirm_sensitive=true.',
                ['status' => 400, 'key' => $key]
            );
        }
        return true;
    }

    private static function sensitive_key(string $key): bool
    {
        $lower = strtolower($key);
        return (bool) preg_match('/pass(word)?|passwd|secret|token|api[_-]?key|access[_-]?key|credential|private[_-]?key|client[_-]?secret|auth[_-]?key|nonce|otp|2fa|two[_-]?factor|recovery|authenticator|totp|webauthn|session|cookie/i', $lower);
    }

    private static function contains_sensitive_nested_key($value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && self::sensitive_key($key)) return true;
                if (self::contains_sensitive_nested_key($child)) return true;
            }
        } elseif (is_object($value)) {
            return self::contains_sensitive_nested_key(get_object_vars($value));
        }
        return false;
    }

    public static function safe_value($value)
    {
        if (is_string($value)) {
            return strlen($value) > 20000 ? substr($value, 0, 20000) . '<truncated>' : $value;
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && self::sensitive_key($key)) $out[$key] = '<redacted>';
                else $out[$key] = self::safe_value($child);
            }
            return $out;
        }
        if (is_object($value)) return '<object omitted>';
        return $value;
    }

    private static function confirm_error(): WP_Error
    {
        return new WP_Error('takka_bridge_confirmation_required', 'Post meta changes require confirm=true.', ['status' => 400]);
    }

    private static function sensitive_error(string $verb): WP_Error
    {
        return new WP_Error(
            'takka_bridge_post_meta_sensitive',
            "Sensitive credential/session post meta cannot be {$verb} through the Bridge.",
            ['status' => 403]
        );
    }
}
