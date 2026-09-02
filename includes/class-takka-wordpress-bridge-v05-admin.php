<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_V05_Admin
{
    public static function menu_list(): array
    {
        $locations = get_nav_menu_locations();
        $registered = get_registered_nav_menus();
        $menus = [];
        foreach (wp_get_nav_menus(['hide_empty' => false]) as $menu) {
            $assigned = [];
            foreach ($locations as $location => $menu_id) {
                if ((int) $menu_id === (int) $menu->term_id) {
                    $assigned[] = $location;
                }
            }
            $menus[] = [
                'id' => (int) $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'count' => (int) $menu->count,
                'locations' => $assigned,
            ];
        }
        return [
            'menus' => $menus,
            'registered_locations' => $registered,
            'location_assignments' => $locations,
        ];
    }

    public static function menu_get(array $params)
    {
        $menu = self::resolve_menu($params);
        if (is_wp_error($menu)) {
            return $menu;
        }
        $items = [];
        $raw_items = wp_get_nav_menu_items($menu->term_id, ['post_status' => 'any']);
        if (is_array($raw_items)) {
            foreach ($raw_items as $item) {
                $items[] = [
                    'id' => (int) $item->ID,
                    'title' => $item->title,
                    'url' => $item->url,
                    'type' => $item->type,
                    'object' => $item->object,
                    'object_id' => (int) $item->object_id,
                    'parent' => (int) $item->menu_item_parent,
                    'order' => (int) $item->menu_order,
                    'target' => $item->target,
                    'classes' => array_values(array_filter((array) $item->classes)),
                    'xfn' => $item->xfn,
                ];
            }
        }
        return rest_ensure_response([
            'menu' => [
                'id' => (int) $menu->term_id,
                'name' => $menu->name,
                'slug' => $menu->slug,
                'description' => $menu->description,
            ],
            'items' => $items,
        ]);
    }

    public static function menu_create(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu creation requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $name = self::required_string($params, 'name');
        if (is_wp_error($name)) {
            return $name;
        }
        $id = wp_create_nav_menu($name);
        if (is_wp_error($id)) {
            return $id;
        }
        return rest_ensure_response(['ok' => true, 'id' => (int) $id, 'name' => $name]);
    }

    public static function menu_update(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu update requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $menu = self::resolve_menu($params);
        if (is_wp_error($menu)) {
            return $menu;
        }
        $args = [];
        if (isset($params['name']) && is_string($params['name'])) {
            $args['menu-name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['description']) && is_string($params['description'])) {
            $args['description'] = sanitize_textarea_field($params['description']);
        }
        if (!$args) {
            return new WP_Error('takka_bridge_menu_no_changes', 'No menu fields were provided.', ['status' => 400]);
        }
        $result = wp_update_nav_menu_object($menu->term_id, $args);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['ok' => true, 'id' => (int) $result]);
    }

    public static function menu_delete(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu deletion requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $menu = self::resolve_menu($params);
        if (is_wp_error($menu)) {
            return $menu;
        }
        $result = wp_delete_nav_menu($menu->term_id);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['ok' => (bool) $result, 'id' => (int) $menu->term_id]);
    }

    public static function menu_item_upsert(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu item changes require confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $menu = self::resolve_menu($params);
        if (is_wp_error($menu)) {
            return $menu;
        }
        $item_id = isset($params['item_id']) ? absint($params['item_id']) : 0;
        if ($item_id > 0 && !self::menu_contains_item((int) $menu->term_id, $item_id)) {
            return new WP_Error('takka_bridge_menu_item_not_in_menu', 'item_id does not belong to the selected menu.', ['status' => 409]);
        }
        $type = isset($params['type']) && is_string($params['type']) ? sanitize_key($params['type']) : 'custom';
        if (!in_array($type, ['custom', 'post_type', 'taxonomy'], true)) {
            return new WP_Error('takka_bridge_menu_item_type', 'Unsupported menu item type.', ['status' => 400]);
        }
        $args = [
            'menu-item-status' => 'publish',
            'menu-item-type' => $type,
            'menu-item-title' => isset($params['title']) && is_string($params['title']) ? sanitize_text_field($params['title']) : '',
            'menu-item-parent-id' => isset($params['parent']) ? absint($params['parent']) : 0,
            'menu-item-position' => isset($params['position']) ? (int) $params['position'] : 0,
            'menu-item-target' => isset($params['target']) && is_string($params['target']) ? sanitize_text_field($params['target']) : '',
            'menu-item-xfn' => isset($params['xfn']) && is_string($params['xfn']) ? sanitize_text_field($params['xfn']) : '',
            'menu-item-classes' => isset($params['classes']) && is_array($params['classes']) ? implode(' ', array_map('sanitize_html_class', $params['classes'])) : '',
        ];
        if ($type === 'custom') {
            if (!isset($params['url']) || !is_string($params['url']) || trim($params['url']) === '') {
                return new WP_Error('takka_bridge_menu_item_url', 'Custom menu items require url.', ['status' => 400]);
            }
            $args['menu-item-url'] = esc_url_raw($params['url']);
        } else {
            if (!isset($params['object']) || !is_string($params['object']) || !isset($params['object_id'])) {
                return new WP_Error('takka_bridge_menu_item_object', 'post_type/taxonomy menu items require object and object_id.', ['status' => 400]);
            }
            $args['menu-item-object'] = sanitize_key($params['object']);
            $args['menu-item-object-id'] = absint($params['object_id']);
        }
        $result = wp_update_nav_menu_item($menu->term_id, $item_id, $args);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['ok' => true, 'menu_id' => (int) $menu->term_id, 'item_id' => (int) $result]);
    }

    public static function menu_item_delete(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu item deletion requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $menu = self::resolve_menu($params);
        if (is_wp_error($menu)) {
            return $menu;
        }
        $item_id = isset($params['item_id']) ? absint($params['item_id']) : 0;
        if ($item_id < 1 || !self::menu_contains_item((int) $menu->term_id, $item_id)) {
            return new WP_Error('takka_bridge_menu_item_not_found', 'Menu item was not found in the selected menu.', ['status' => 404]);
        }
        $deleted = wp_delete_post($item_id, true);
        return rest_ensure_response(['ok' => (bool) $deleted, 'menu_id' => (int) $menu->term_id, 'item_id' => $item_id]);
    }

    public static function menu_locations_set(array $params)
    {
        $confirm = self::require_confirm($params, 'Menu location changes require confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        if (!isset($params['locations']) || !is_array($params['locations'])) {
            return new WP_Error('takka_bridge_menu_locations', 'locations must be an object/map.', ['status' => 400]);
        }
        $registered = get_registered_nav_menus();
        $current = get_nav_menu_locations();
        $replace_all = !empty($params['replace_all']);
        $next = $replace_all ? [] : $current;
        foreach ($params['locations'] as $location => $menu_id) {
            if (!is_string($location) || !array_key_exists($location, $registered)) {
                return new WP_Error('takka_bridge_menu_location_unknown', 'Unknown registered menu location.', ['status' => 400, 'location' => $location]);
            }
            $menu_id = absint($menu_id);
            if ($menu_id > 0 && !wp_get_nav_menu_object($menu_id)) {
                return new WP_Error('takka_bridge_menu_location_menu', 'Assigned menu does not exist.', ['status' => 404, 'menu_id' => $menu_id]);
            }
            $next[$location] = $menu_id;
        }
        set_theme_mod('nav_menu_locations', $next);
        return rest_ensure_response(['ok' => true, 'location_assignments' => get_nav_menu_locations()]);
    }

    private static function resolve_menu(array $params)
    {
        $selector = null;
        if (isset($params['menu_id'])) {
            $selector = absint($params['menu_id']);
        } elseif (isset($params['menu']) && (is_string($params['menu']) || is_int($params['menu']))) {
            $selector = $params['menu'];
        }
        if ($selector === null || $selector === '' || $selector === 0) {
            return new WP_Error('takka_bridge_menu_required', 'menu_id or menu is required.', ['status' => 400]);
        }
        $menu = wp_get_nav_menu_object($selector);
        if (!$menu) {
            return new WP_Error('takka_bridge_menu_not_found', 'Menu was not found.', ['status' => 404]);
        }
        return $menu;
    }

    private static function menu_contains_item(int $menu_id, int $item_id): bool
    {
        $items = wp_get_nav_menu_items($menu_id, ['post_status' => 'any']);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ((int) $item->ID === $item_id) {
                return true;
            }
        }
        return false;
    }

    public static function updates_status(bool $refresh): array
    {
        global $wp_version;
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        if ($refresh) {
            wp_version_check([], true);
            wp_update_plugins();
            wp_update_themes();
        }

        $core_transient = get_site_transient('update_core');
        $plugin_transient = get_site_transient('update_plugins');
        $theme_transient = get_site_transient('update_themes');
        $plugins = get_plugins();
        $themes = wp_get_themes();

        $core = [];
        if (is_object($core_transient) && isset($core_transient->updates) && is_array($core_transient->updates)) {
            foreach ($core_transient->updates as $update) {
                if (!is_object($update)) {
                    continue;
                }
                $core[] = [
                    'response' => $update->response ?? null,
                    'version' => $update->current ?? null,
                    'locale' => $update->locale ?? null,
                    'php_version' => $update->php_version ?? null,
                    'mysql_version' => $update->mysql_version ?? null,
                ];
            }
        }

        $plugin_updates = [];
        if (is_object($plugin_transient) && isset($plugin_transient->response) && is_array($plugin_transient->response)) {
            foreach ($plugin_transient->response as $file => $update) {
                $plugin_updates[] = [
                    'file' => $file,
                    'name' => $plugins[$file]['Name'] ?? $file,
                    'installed_version' => $plugins[$file]['Version'] ?? null,
                    'new_version' => is_object($update) ? ($update->new_version ?? null) : null,
                    'requires_php' => is_object($update) ? ($update->requires_php ?? null) : null,
                    'requires_wp' => is_object($update) ? ($update->requires ?? null) : null,
                ];
            }
        }

        $theme_updates = [];
        if (is_object($theme_transient) && isset($theme_transient->response) && is_array($theme_transient->response)) {
            foreach ($theme_transient->response as $stylesheet => $update) {
                $theme = $themes[$stylesheet] ?? null;
                $theme_updates[] = [
                    'stylesheet' => $stylesheet,
                    'name' => $theme ? $theme->get('Name') : $stylesheet,
                    'installed_version' => $theme ? $theme->get('Version') : null,
                    'new_version' => is_array($update) ? ($update['new_version'] ?? null) : null,
                    'requires_php' => is_array($update) ? ($update['requires_php'] ?? null) : null,
                    'requires_wp' => is_array($update) ? ($update['requires'] ?? null) : null,
                ];
            }
        }

        return [
            'wordpress_version' => $wp_version,
            'checked_at' => time(),
            'refreshed' => $refresh,
            'core' => $core,
            'plugins' => $plugin_updates,
            'themes' => $theme_updates,
            'counts' => [
                'plugins' => count($plugin_updates),
                'themes' => count($theme_updates),
            ],
        ];
    }

    private static function required_string(array $params, string $key, bool $trim = true)
    {
        if (!array_key_exists($key, $params) || !is_string($params[$key])) {
            return new WP_Error('takka_bridge_required_string', "{$key} must be a string.", ['status' => 400]);
        }
        $value = $trim ? trim($params[$key]) : $params[$key];
        if ($trim && $value === '') {
            return new WP_Error('takka_bridge_required_string_empty', "{$key} must not be empty.", ['status' => 400]);
        }
        return $value;
    }

    private static function require_confirm(array $params, string $message)
    {
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_confirmation_required', $message, ['status' => 400]);
        }
        return true;
    }
}
