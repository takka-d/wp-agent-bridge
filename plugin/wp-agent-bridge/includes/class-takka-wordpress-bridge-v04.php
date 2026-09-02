<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * v0.4 management surface.
 *
 * Kept separate from the original bridge so the transport and the WordPress
 * operation layer remain independently replaceable. The /manage endpoint only
 * accepts a Base64 envelope, then performs its own HMAC authentication before
 * dispatching an allowlisted action.
 */
final class TakKa_WordPress_Bridge_V04
{
    private const ROUTE_NAMESPACE = 'takka-bridge/v1';
    private const ROUTE = '/takka-bridge/v1/manage';
    private const VERSION = '0.4.0';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const OPTION_DRAFTS = 'takka_bridge_draft_themes';
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_ENVELOPE_BYTES = 12582912;
    private const MAX_MEDIA_BYTES = 6291456;
    private const MAX_PATCH_FILE_BYTES = 2097152;
    private const MAX_DIFF_LINES = 120;

    private const ACTIONS = [
        'v04.capabilities',
        'plugin.list',
        'plugin.install',
        'plugin.activate',
        'plugin.deactivate',
        'plugin.update',
        'plugin.delete',
        'theme.manage.list',
        'theme.manage.install',
        'theme.manage.activate',
        'theme.manage.update',
        'theme.manage.delete',
        'theme.file.patch',
        'media.upload_base64',
        'cron.schedules',
        'cron.schedule',
        'cron.run',
        'cron.unschedule',
        'admin.capabilities',
        'admin.run',
    ];

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_response'], 110, 3);
    }

    public static function annotate_response($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health') {
            return $response;
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $rest_response = rest_ensure_response($response);
        $data = $rest_response->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $data['bridge_version'] = self::VERSION;
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        foreach ([
            'plugin_theme_management',
            'theme_partial_patch',
            'media_base64_upload',
            'cron_management',
            'allowlisted_admin_commands',
        ] as $feature) {
            if (!in_array($feature, $features, true)) {
                $features[] = $feature;
            }
        }
        $data['features'] = $features;
        $rest_response->set_data($data);
        return $rest_response;
    }

    public static function register_routes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/manage', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'manage'],
            'permission_callback' => [self::class, 'authorize_request'],
        ]);
    }

    public static function authorize_request(WP_REST_Request $request)
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1) {
            return new WP_Error('takka_bridge_not_configured', 'TakKa WordPress Bridge is not configured.', ['status' => 503]);
        }

        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '' || !ctype_digit($timestamp)) {
            return new WP_Error('takka_bridge_invalid_signature_headers', 'Missing or invalid bridge signature headers.', ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return new WP_Error('takka_bridge_expired_signature', 'Bridge signature has expired.', ['status' => 401]);
        }

        $body = (string) $request->get_body();
        $payload = $timestamp . "\nPOST\n" . self::ROUTE . "\n" . hash('sha256', $body);
        $expected = hash_hmac('sha256', $payload, $secret);
        if (!hash_equals($expected, $signature)) {
            return new WP_Error('takka_bridge_bad_signature', 'Invalid bridge signature.', ['status' => 401]);
        }

        wp_set_current_user($user_id);
        if (!current_user_can('manage_options')) {
            return new WP_Error('takka_bridge_user_forbidden', 'Bridge user no longer has administrator capability.', ['status' => 403]);
        }

        return true;
    }

    public static function manage(WP_REST_Request $request)
    {
        $json = $request->get_json_params();
        if (!is_array($json) || !isset($json['payload_b64']) || !is_string($json['payload_b64'])) {
            return new WP_Error('takka_bridge_manage_missing_envelope', 'Missing payload_b64.', ['status' => 400]);
        }

        $payload_b64 = trim($json['payload_b64']);
        if ($payload_b64 === '' || strlen($payload_b64) > self::MAX_ENVELOPE_BYTES) {
            return new WP_Error('takka_bridge_manage_envelope_size', 'Management envelope is empty or too large.', ['status' => 413]);
        }

        $decoded = base64_decode($payload_b64, true);
        if (!is_string($decoded)) {
            return new WP_Error('takka_bridge_manage_invalid_base64', 'Management envelope is not valid Base64.', ['status' => 400]);
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('takka_bridge_manage_invalid_json', 'Decoded management envelope is not valid JSON.', ['status' => 400]);
        }

        $action = isset($payload['action']) && is_string($payload['action']) ? trim($payload['action']) : '';
        $params = isset($payload['params']) && is_array($payload['params']) ? $payload['params'] : [];
        if ($action === '' || !in_array($action, self::ACTIONS, true)) {
            return new WP_Error('takka_bridge_manage_unknown_action', 'Unknown or blocked v0.4 action.', [
                'status' => 400,
                'action' => $action,
            ]);
        }

        try {
            switch ($action) {
                case 'v04.capabilities':
                    return rest_ensure_response(self::capabilities());
                case 'plugin.list':
                    return rest_ensure_response(self::plugin_list());
                case 'plugin.install':
                    return self::plugin_install($params);
                case 'plugin.activate':
                    return self::plugin_activate($params);
                case 'plugin.deactivate':
                    return self::plugin_deactivate($params);
                case 'plugin.update':
                    return self::plugin_update($params);
                case 'plugin.delete':
                    return self::plugin_delete($params);
                case 'theme.manage.list':
                    return rest_ensure_response(self::theme_list());
                case 'theme.manage.install':
                    return self::theme_install($params);
                case 'theme.manage.activate':
                    return self::theme_activate($params);
                case 'theme.manage.update':
                    return self::theme_update($params);
                case 'theme.manage.delete':
                    return self::theme_delete($params);
                case 'theme.file.patch':
                    return self::theme_file_patch($params);
                case 'media.upload_base64':
                    return self::media_upload_base64($params);
                case 'cron.schedules':
                    return rest_ensure_response(['schedules' => wp_get_schedules()]);
                case 'cron.schedule':
                    return self::cron_schedule($params);
                case 'cron.run':
                    return self::cron_run($params);
                case 'cron.unschedule':
                    return self::cron_unschedule($params);
                case 'admin.capabilities':
                    return rest_ensure_response(self::admin_capabilities());
                case 'admin.run':
                    return self::admin_run($params);
            }
        } catch (Throwable $e) {
            return new WP_Error('takka_bridge_v04_exception', $e->getMessage(), [
                'status' => 500,
                'type' => get_class($e),
            ]);
        }

        return new WP_Error('takka_bridge_v04_dispatch_error', 'v0.4 dispatch fell through unexpectedly.', ['status' => 500]);
    }

    private static function capabilities(): array
    {
        return [
            'version' => self::VERSION,
            'route' => self::ROUTE,
            'actions' => self::ACTIONS,
            'limits' => [
                'max_envelope_bytes' => self::MAX_ENVELOPE_BYTES,
                'max_media_bytes' => self::MAX_MEDIA_BYTES,
                'max_patch_file_bytes' => self::MAX_PATCH_FILE_BYTES,
            ],
            'security' => [
                'hmac_sha256' => true,
                'opaque_base64_transport' => true,
                'arbitrary_shell' => false,
                'plugin_install_source' => 'wordpress.org slug only',
                'theme_install_source' => 'wordpress.org slug only',
            ],
        ];
    }

    private static function plugin_list(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $items = [];
        foreach (get_plugins() as $file => $data) {
            $items[] = [
                'file' => $file,
                'name' => $data['Name'] ?? $file,
                'version' => $data['Version'] ?? null,
                'description' => $data['Description'] ?? '',
                'author' => $data['AuthorName'] ?? ($data['Author'] ?? ''),
                'active' => is_plugin_active($file),
                'network_active' => is_multisite() ? is_plugin_active_for_network($file) : false,
                'requires_wp' => $data['RequiresWP'] ?? null,
                'requires_php' => $data['RequiresPHP'] ?? null,
            ];
        }
        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return ['plugins' => $items];
    }

    private static function plugin_install(array $params)
    {
        $confirm = self::require_confirm($params, 'Installing a plugin requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $slug = self::required_slug($params, 'slug');
        if (is_wp_error($slug)) {
            return $slug;
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $before = array_keys(get_plugins());
        foreach ($before as $file) {
            if (dirname($file) === $slug || basename($file, '.php') === $slug) {
                return new WP_Error('takka_bridge_plugin_already_installed', 'Plugin appears to be already installed.', [
                    'status' => 409,
                    'file' => $file,
                ]);
            }
        }

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api)) {
            return $api;
        }
        if (empty($api->download_link)) {
            return new WP_Error('takka_bridge_plugin_download_missing', 'WordPress.org did not provide a plugin download URL.', ['status' => 502]);
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->install($api->download_link);
        $checked = self::check_upgrader_result($result, $skin, 'Plugin installation failed.');
        if (is_wp_error($checked)) {
            return $checked;
        }

        wp_clean_plugins_cache(true);
        $after = array_keys(get_plugins());
        $new_files = array_values(array_diff($after, $before));
        return rest_ensure_response([
            'ok' => true,
            'slug' => $slug,
            'installed_files' => $new_files,
            'plugins' => self::plugin_list()['plugins'],
        ]);
    }

    private static function plugin_activate(array $params)
    {
        $confirm = self::require_confirm($params, 'Plugin activation requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $file = self::required_plugin_file($params);
        if (is_wp_error($file)) {
            return $file;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active($file)) {
            return rest_ensure_response(['ok' => true, 'file' => $file, 'active' => true, 'changed' => false]);
        }
        $network = !empty($params['network']) && is_multisite();
        $result = activate_plugin($file, '', $network, false);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response([
            'ok' => true,
            'file' => $file,
            'active' => is_plugin_active($file),
            'network_active' => is_multisite() ? is_plugin_active_for_network($file) : false,
            'changed' => true,
        ]);
    }

    private static function plugin_deactivate(array $params)
    {
        $confirm = self::require_confirm($params, 'Plugin deactivation requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $file = self::required_plugin_file($params);
        if (is_wp_error($file)) {
            return $file;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!is_plugin_active($file) && !(is_multisite() && is_plugin_active_for_network($file))) {
            return rest_ensure_response(['ok' => true, 'file' => $file, 'active' => false, 'changed' => false]);
        }
        $network = !empty($params['network']) && is_multisite();
        deactivate_plugins($file, false, $network);
        return rest_ensure_response([
            'ok' => true,
            'file' => $file,
            'active' => is_plugin_active($file),
            'network_active' => is_multisite() ? is_plugin_active_for_network($file) : false,
            'changed' => true,
        ]);
    }

    private static function plugin_update(array $params)
    {
        $confirm = self::require_confirm($params, 'Plugin update requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $file = self::required_plugin_file($params);
        if (is_wp_error($file)) {
            return $file;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $before = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
        wp_clean_plugins_cache(true);
        wp_update_plugins();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($file);
        $checked = self::check_upgrader_result($result, $skin, 'Plugin update failed or no update was available.');
        if (is_wp_error($checked)) {
            return $checked;
        }
        wp_clean_plugins_cache(true);
        $after = get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
        return rest_ensure_response([
            'ok' => true,
            'file' => $file,
            'before_version' => $before['Version'] ?? null,
            'after_version' => $after['Version'] ?? null,
        ]);
    }

    private static function plugin_delete(array $params)
    {
        $confirm = self::require_confirm($params, 'Plugin deletion requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $file = self::required_plugin_file($params);
        if (is_wp_error($file)) {
            return $file;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (is_plugin_active($file) || (is_multisite() && is_plugin_active_for_network($file))) {
            return new WP_Error('takka_bridge_plugin_active_delete_blocked', 'Deactivate the plugin before deleting it.', ['status' => 409]);
        }
        $result = delete_plugins([$file]);
        if (is_wp_error($result)) {
            return $result;
        }
        wp_clean_plugins_cache(true);
        return rest_ensure_response(['ok' => (bool) $result, 'file' => $file]);
    }

    private static function theme_list(): array
    {
        $active_stylesheet = get_stylesheet();
        $active_template = get_template();
        $items = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $parent = $theme->parent();
            $items[] = [
                'stylesheet' => $stylesheet,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'description' => $theme->get('Description'),
                'template' => $theme->get_template(),
                'parent' => $parent ? $parent->get_stylesheet() : null,
                'active_stylesheet' => $stylesheet === $active_stylesheet,
                'active_parent' => $stylesheet === $active_template && $active_template !== $active_stylesheet,
                'requires_wp' => $theme->get('RequiresWP'),
                'requires_php' => $theme->get('RequiresPHP'),
                'errors' => $theme->errors() ? $theme->errors()->get_error_messages() : [],
            ];
        }
        usort($items, static function (array $a, array $b): int {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        });
        return [
            'active_stylesheet' => $active_stylesheet,
            'active_template' => $active_template,
            'themes' => $items,
        ];
    }

    private static function theme_install(array $params)
    {
        $confirm = self::require_confirm($params, 'Theme installation requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $slug = self::required_slug($params, 'slug');
        if (is_wp_error($slug)) {
            return $slug;
        }
        if (wp_get_theme($slug)->exists()) {
            return new WP_Error('takka_bridge_theme_already_installed', 'Theme is already installed.', ['status' => 409]);
        }

        require_once ABSPATH . 'wp-admin/includes/theme-install.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api)) {
            return $api;
        }
        if (empty($api->download_link)) {
            return new WP_Error('takka_bridge_theme_download_missing', 'WordPress.org did not provide a theme download URL.', ['status' => 502]);
        }
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->install($api->download_link);
        $checked = self::check_upgrader_result($result, $skin, 'Theme installation failed.');
        if (is_wp_error($checked)) {
            return $checked;
        }
        wp_clean_themes_cache(true);
        return rest_ensure_response(['ok' => true, 'slug' => $slug, 'themes' => self::theme_list()['themes']]);
    }

    private static function theme_activate(array $params)
    {
        $confirm = self::require_confirm($params, 'Theme activation requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $stylesheet = self::required_theme_stylesheet($params);
        if (is_wp_error($stylesheet)) {
            return $stylesheet;
        }
        $theme = wp_get_theme($stylesheet);
        if ($theme->errors()) {
            return $theme->errors();
        }
        $before = get_stylesheet();
        if ($before === $stylesheet) {
            return rest_ensure_response(['ok' => true, 'stylesheet' => $stylesheet, 'changed' => false]);
        }
        switch_theme($stylesheet);
        wp_clean_themes_cache(true);
        $after = get_stylesheet();
        if ($after !== $stylesheet) {
            return new WP_Error('takka_bridge_theme_activation_failed', 'Theme did not become active.', ['status' => 500]);
        }
        return rest_ensure_response(['ok' => true, 'before' => $before, 'after' => $after, 'changed' => true]);
    }

    private static function theme_update(array $params)
    {
        $confirm = self::require_confirm($params, 'Theme update requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $stylesheet = self::required_theme_stylesheet($params);
        if (is_wp_error($stylesheet)) {
            return $stylesheet;
        }
        $before = wp_get_theme($stylesheet)->get('Version');
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        wp_clean_themes_cache(true);
        wp_update_themes();
        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Theme_Upgrader($skin);
        $result = $upgrader->upgrade($stylesheet);
        $checked = self::check_upgrader_result($result, $skin, 'Theme update failed or no update was available.');
        if (is_wp_error($checked)) {
            return $checked;
        }
        wp_clean_themes_cache(true);
        $after = wp_get_theme($stylesheet)->get('Version');
        return rest_ensure_response(['ok' => true, 'stylesheet' => $stylesheet, 'before_version' => $before, 'after_version' => $after]);
    }

    private static function theme_delete(array $params)
    {
        $confirm = self::require_confirm($params, 'Theme deletion requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $stylesheet = self::required_theme_stylesheet($params);
        if (is_wp_error($stylesheet)) {
            return $stylesheet;
        }
        if ($stylesheet === get_stylesheet() || $stylesheet === get_template()) {
            return new WP_Error('takka_bridge_active_theme_delete_blocked', 'Cannot delete the active theme or its active parent.', ['status' => 409]);
        }
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        $result = delete_theme($stylesheet);
        if (is_wp_error($result)) {
            return $result;
        }
        wp_clean_themes_cache(true);
        return rest_ensure_response(['ok' => (bool) $result, 'stylesheet' => $stylesheet]);
    }

    private static function theme_file_patch(array $params)
    {
        $path = self::required_string($params, 'path');
        $find = self::required_string($params, 'find', false);
        if (is_wp_error($path)) {
            return $path;
        }
        if (is_wp_error($find)) {
            return $find;
        }
        if (!array_key_exists('replace', $params) || !is_string($params['replace'])) {
            return new WP_Error('takka_bridge_patch_missing_replace', 'replace must be a string.', ['status' => 400]);
        }
        if ($find === '') {
            return new WP_Error('takka_bridge_patch_empty_find', 'find must not be empty.', ['status' => 400]);
        }

        $legacy_context = ['path' => $path];
        if (isset($params['draft_id']) && is_string($params['draft_id']) && $params['draft_id'] !== '') {
            $legacy_context['draft_id'] = $params['draft_id'];
        }
        $read = self::legacy_action('theme.file.read', $legacy_context);
        if (is_wp_error($read)) {
            return $read;
        }
        if (!isset($read['content']) || !is_string($read['content'])) {
            return new WP_Error('takka_bridge_patch_read_invalid', 'Theme read response did not contain text content.', ['status' => 500]);
        }

        $before = $read['content'];
        if (strlen($before) > self::MAX_PATCH_FILE_BYTES) {
            return new WP_Error('takka_bridge_patch_file_too_large', 'Theme file exceeds patch size limit.', ['status' => 413]);
        }
        if (isset($params['expected_sha256']) && is_string($params['expected_sha256']) && $params['expected_sha256'] !== '') {
            if (!hash_equals(strtolower($params['expected_sha256']), hash('sha256', $before))) {
                return new WP_Error('takka_bridge_patch_sha_conflict', 'Theme file changed since it was inspected.', [
                    'status' => 409,
                    'current_sha256' => hash('sha256', $before),
                ]);
            }
        }

        $replace = $params['replace'];
        $replace_all = !empty($params['replace_all']);
        $count = 0;
        if ($replace_all) {
            $after = str_replace($find, $replace, $before, $count);
        } else {
            $position = strpos($before, $find);
            if ($position === false) {
                $after = $before;
                $count = 0;
            } else {
                $after = substr($before, 0, $position) . $replace . substr($before, $position + strlen($find));
                $count = 1;
            }
        }

        $expected = isset($params['expected_replacements']) ? (int) $params['expected_replacements'] : 1;
        if ($expected < 0 || $count !== $expected) {
            return new WP_Error('takka_bridge_patch_match_conflict', 'Replacement count did not match expectation.', [
                'status' => 409,
                'expected_replacements' => $expected,
                'actual_replacements' => $count,
            ]);
        }

        $diff = self::compact_diff($before, $after, $path);
        $summary = [
            'ok' => true,
            'path' => $path,
            'draft_id' => $legacy_context['draft_id'] ?? null,
            'replacements' => $count,
            'before_sha256' => hash('sha256', $before),
            'after_sha256' => hash('sha256', $after),
            'before_bytes' => strlen($before),
            'after_bytes' => strlen($after),
            'diff' => $diff['text'],
            'diff_truncated' => $diff['truncated'],
            'dry_run' => !empty($params['dry_run']),
        ];

        if (!empty($params['dry_run'])) {
            return rest_ensure_response($summary);
        }

        $write = $legacy_context;
        $write['content'] = $after;
        if (!empty($params['confirm_active'])) {
            $write['confirm_active'] = true;
        }
        $written = self::legacy_action('theme.file.write', $write);
        if (is_wp_error($written)) {
            return $written;
        }
        $summary['write'] = $written;
        return rest_ensure_response($summary);
    }

    private static function media_upload_base64(array $params)
    {
        $filename = self::required_string($params, 'filename');
        if (is_wp_error($filename)) {
            return $filename;
        }
        if (!isset($params['data_b64']) || !is_string($params['data_b64']) || trim($params['data_b64']) === '') {
            return new WP_Error('takka_bridge_media_missing_base64', 'data_b64 is required.', ['status' => 400]);
        }
        $binary = base64_decode(trim($params['data_b64']), true);
        if (!is_string($binary)) {
            return new WP_Error('takka_bridge_media_invalid_base64', 'data_b64 is not valid Base64.', ['status' => 400]);
        }
        if (strlen($binary) < 1 || strlen($binary) > self::MAX_MEDIA_BYTES) {
            return new WP_Error('takka_bridge_media_size', 'Decoded media is empty or exceeds the size limit.', ['status' => 413]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $safe_name = sanitize_file_name($filename);
        if ($safe_name === '' || strpos($safe_name, '.') === false) {
            return new WP_Error('takka_bridge_media_filename', 'filename must include an allowed file extension.', ['status' => 400]);
        }
        $tmp = wp_tempnam($safe_name);
        if (!$tmp || file_put_contents($tmp, $binary, LOCK_EX) === false) {
            if ($tmp) {
                @unlink($tmp);
            }
            return new WP_Error('takka_bridge_media_temp_write', 'Could not create temporary upload file.', ['status' => 500]);
        }

        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $file_array = ['name' => $safe_name, 'tmp_name' => $tmp];
        $attachment_id = media_handle_sideload($file_array, $post_id, isset($params['description']) ? (string) $params['description'] : null);
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        if (isset($params['alt_text']) && is_string($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($params['alt_text']));
        }
        $post_update = ['ID' => $attachment_id];
        foreach (['title' => 'post_title', 'caption' => 'post_excerpt', 'description' => 'post_content'] as $input => $field) {
            if (isset($params[$input]) && is_string($params[$input])) {
                $post_update[$field] = $params[$input];
            }
        }
        if (count($post_update) > 1) {
            wp_update_post(wp_slash($post_update));
        }

        return rest_ensure_response([
            'ok' => true,
            'id' => $attachment_id,
            'filename' => basename((string) get_attached_file($attachment_id)),
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'bytes' => strlen($binary),
        ]);
    }

    private static function cron_schedule(array $params)
    {
        $confirm = self::require_confirm($params, 'Scheduling a cron event requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $hook = self::required_hook($params);
        if (is_wp_error($hook)) {
            return $hook;
        }
        $timestamp = isset($params['timestamp']) ? (int) $params['timestamp'] : 0;
        if ($timestamp < time() - 60) {
            return new WP_Error('takka_bridge_cron_timestamp', 'timestamp must be now or in the future.', ['status' => 400]);
        }
        if (!has_action($hook)) {
            return new WP_Error('takka_bridge_cron_unregistered_hook', 'No callback is currently registered for this hook.', ['status' => 409]);
        }
        $args = isset($params['args']) && is_array($params['args']) ? $params['args'] : [];
        $recurrence = isset($params['recurrence']) && is_string($params['recurrence']) ? trim($params['recurrence']) : '';
        if ($recurrence !== '' && !array_key_exists($recurrence, wp_get_schedules())) {
            return new WP_Error('takka_bridge_cron_recurrence', 'Unknown cron recurrence.', ['status' => 400]);
        }

        $result = $recurrence === ''
            ? wp_schedule_single_event($timestamp, $hook, $args, true)
            : wp_schedule_event($timestamp, $recurrence, $hook, $args, true);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response([
            'ok' => (bool) $result,
            'hook' => $hook,
            'timestamp' => $timestamp,
            'recurrence' => $recurrence !== '' ? $recurrence : null,
            'args' => $args,
        ]);
    }

    private static function cron_run(array $params)
    {
        $confirm = self::require_confirm($params, 'Running a cron callback requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $hook = self::required_hook($params);
        if (is_wp_error($hook)) {
            return $hook;
        }
        $args = isset($params['args']) && is_array($params['args']) ? $params['args'] : [];
        $timestamp = isset($params['timestamp']) ? (int) $params['timestamp'] : null;
        $event = wp_get_scheduled_event($hook, $args, $timestamp);
        if (!$event) {
            return new WP_Error('takka_bridge_cron_event_not_found', 'Matching scheduled event was not found.', ['status' => 404]);
        }
        do_action_ref_array($hook, $args);
        return rest_ensure_response([
            'ok' => true,
            'hook' => $hook,
            'scheduled_timestamp' => $event->timestamp,
            'args' => $args,
            'schedule_unchanged' => true,
        ]);
    }

    private static function cron_unschedule(array $params)
    {
        $confirm = self::require_confirm($params, 'Unscheduling a cron event requires confirm=true.');
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        $hook = self::required_hook($params);
        if (is_wp_error($hook)) {
            return $hook;
        }
        $timestamp = isset($params['timestamp']) ? (int) $params['timestamp'] : 0;
        if ($timestamp < 1) {
            return new WP_Error('takka_bridge_cron_timestamp', 'A valid timestamp is required.', ['status' => 400]);
        }
        $args = isset($params['args']) && is_array($params['args']) ? $params['args'] : [];
        $event = wp_get_scheduled_event($hook, $args, $timestamp);
        if (!$event) {
            return new WP_Error('takka_bridge_cron_event_not_found', 'Matching scheduled event was not found.', ['status' => 404]);
        }
        $result = wp_unschedule_event($timestamp, $hook, $args, true);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(['ok' => (bool) $result, 'hook' => $hook, 'timestamp' => $timestamp, 'args' => $args]);
    }

    private static function admin_capabilities(): array
    {
        return [
            'commands' => [
                ['name' => 'cache.flush', 'confirmation' => false],
                ['name' => 'rewrite.flush', 'confirmation' => true],
                ['name' => 'transients.delete_expired', 'confirmation' => true],
                ['name' => 'themes.refresh_cache', 'confirmation' => false],
                ['name' => 'cron.spawn', 'confirmation' => true],
            ],
            'arbitrary_shell' => false,
            'arbitrary_wp_cli' => false,
        ];
    }

    private static function admin_run(array $params)
    {
        $command = self::required_string($params, 'command');
        if (is_wp_error($command)) {
            return $command;
        }
        switch ($command) {
            case 'cache.flush':
                return rest_ensure_response(['ok' => (bool) wp_cache_flush(), 'command' => $command]);
            case 'themes.refresh_cache':
                wp_clean_themes_cache(true);
                return rest_ensure_response(['ok' => true, 'command' => $command]);
            case 'rewrite.flush':
                $confirm = self::require_confirm($params, 'rewrite.flush requires confirm=true.');
                if (is_wp_error($confirm)) {
                    return $confirm;
                }
                flush_rewrite_rules(false);
                return rest_ensure_response(['ok' => true, 'command' => $command, 'hard' => false]);
            case 'transients.delete_expired':
                $confirm = self::require_confirm($params, 'transients.delete_expired requires confirm=true.');
                if (is_wp_error($confirm)) {
                    return $confirm;
                }
                delete_expired_transients(true);
                return rest_ensure_response(['ok' => true, 'command' => $command]);
            case 'cron.spawn':
                $confirm = self::require_confirm($params, 'cron.spawn requires confirm=true.');
                if (is_wp_error($confirm)) {
                    return $confirm;
                }
                $result = spawn_cron(time());
                return rest_ensure_response(['ok' => (bool) $result, 'command' => $command]);
            default:
                return new WP_Error('takka_bridge_admin_command_blocked', 'Unknown or non-allowlisted admin command.', [
                    'status' => 403,
                    'command' => $command,
                ]);
        }
    }

    private static function legacy_action(string $action, array $params)
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        if ($secret === '') {
            return new WP_Error('takka_bridge_not_configured', 'Bridge secret is missing.', ['status' => 503]);
        }
        $route = '/takka-bridge/v1/execute';
        $body = wp_json_encode(['action' => $action, 'params' => $params], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            return new WP_Error('takka_bridge_internal_encode', 'Could not encode internal bridge request.', ['status' => 500]);
        }
        $timestamp = (string) time();
        $signature_payload = $timestamp . "\nPOST\n" . $route . "\n" . hash('sha256', $body);
        $signature = hash_hmac('sha256', $signature_payload, $secret);

        $request = new WP_REST_Request('POST', $route);
        $request->set_header('content-type', 'application/json');
        $request->set_header('x-takka-timestamp', $timestamp);
        $request->set_header('x-takka-signature', $signature);
        $request->set_body($body);
        $response = rest_do_request($request);
        if (is_wp_error($response)) {
            return $response;
        }
        $status = $response->get_status();
        $data = $response->get_data();
        if ($status >= 400) {
            if (is_array($data) && isset($data['code'], $data['message'])) {
                return new WP_Error((string) $data['code'], (string) $data['message'], is_array($data['data'] ?? null) ? $data['data'] : ['status' => $status]);
            }
            return new WP_Error('takka_bridge_legacy_action_failed', 'Internal legacy bridge action failed.', ['status' => $status, 'data' => $data]);
        }
        return is_array($data) ? $data : ['value' => $data];
    }

    private static function compact_diff(string $before, string $after, string $path): array
    {
        $a = preg_split('/\R/', $before);
        $b = preg_split('/\R/', $after);
        if (!is_array($a) || !is_array($b)) {
            return ['text' => '', 'truncated' => false];
        }

        $prefix = 0;
        $max_prefix = min(count($a), count($b));
        while ($prefix < $max_prefix && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }
        $suffix = 0;
        while (
            $suffix < (count($a) - $prefix)
            && $suffix < (count($b) - $prefix)
            && $a[count($a) - 1 - $suffix] === $b[count($b) - 1 - $suffix]
        ) {
            $suffix++;
        }

        $old = array_slice($a, $prefix, count($a) - $prefix - $suffix);
        $new = array_slice($b, $prefix, count($b) - $prefix - $suffix);
        $truncated = count($old) > self::MAX_DIFF_LINES || count($new) > self::MAX_DIFF_LINES;
        $old_show = array_slice($old, 0, self::MAX_DIFF_LINES);
        $new_show = array_slice($new, 0, self::MAX_DIFF_LINES);

        $lines = [
            '--- a/' . $path,
            '+++ b/' . $path,
            '@@ -' . ($prefix + 1) . ',' . count($old) . ' +' . ($prefix + 1) . ',' . count($new) . ' @@',
        ];
        foreach ($old_show as $line) {
            $lines[] = '-' . $line;
        }
        foreach ($new_show as $line) {
            $lines[] = '+' . $line;
        }
        if ($truncated) {
            $lines[] = '... diff truncated ...';
        }
        return ['text' => implode("\n", $lines), 'truncated' => $truncated];
    }

    private static function check_upgrader_result($result, $skin, string $fallback)
    {
        if (is_wp_error($result)) {
            return $result;
        }
        if ($result === false) {
            if (is_object($skin) && method_exists($skin, 'get_errors')) {
                $errors = $skin->get_errors();
                if (is_wp_error($errors) && $errors->has_errors()) {
                    return $errors;
                }
            }
            return new WP_Error('takka_bridge_upgrader_failed', $fallback, ['status' => 409]);
        }
        return true;
    }

    private static function required_plugin_file(array $params)
    {
        $file = self::required_string($params, 'file');
        if (is_wp_error($file)) {
            return $file;
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugins = get_plugins();
        if (!isset($plugins[$file])) {
            return new WP_Error('takka_bridge_plugin_not_found', 'Installed plugin file not found.', ['status' => 404]);
        }
        return $file;
    }

    private static function required_theme_stylesheet(array $params)
    {
        $stylesheet = self::required_string($params, 'stylesheet');
        if (is_wp_error($stylesheet)) {
            return $stylesheet;
        }
        if (basename($stylesheet) !== $stylesheet) {
            return new WP_Error('takka_bridge_theme_stylesheet_invalid', 'Invalid theme stylesheet.', ['status' => 400]);
        }
        $theme = wp_get_theme($stylesheet);
        if (!$theme->exists()) {
            return new WP_Error('takka_bridge_theme_not_found', 'Installed theme not found.', ['status' => 404]);
        }
        return $stylesheet;
    }

    private static function required_slug(array $params, string $key)
    {
        $slug = self::required_string($params, $key);
        if (is_wp_error($slug)) {
            return $slug;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/', $slug)) {
            return new WP_Error('takka_bridge_invalid_slug', 'Slug contains unsupported characters.', ['status' => 400]);
        }
        return $slug;
    }

    private static function required_hook(array $params)
    {
        $hook = self::required_string($params, 'hook');
        if (is_wp_error($hook)) {
            return $hook;
        }
        if (strlen($hook) > 191 || preg_match('/[^A-Za-z0-9_.:\/-]/', $hook)) {
            return new WP_Error('takka_bridge_invalid_hook', 'Invalid cron hook name.', ['status' => 400]);
        }
        return $hook;
    }

    private static function required_string(array $params, string $key, bool $trim = true)
    {
        if (!array_key_exists($key, $params) || !is_scalar($params[$key])) {
            return new WP_Error('takka_bridge_missing_parameter', 'Missing required parameter: ' . $key, ['status' => 400]);
        }
        $value = (string) $params[$key];
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '' && $trim) {
            return new WP_Error('takka_bridge_missing_parameter', 'Missing required parameter: ' . $key, ['status' => 400]);
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
