<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge
{
    private const REST_NAMESPACE = 'takka-bridge/v1';
    private const VERSION = '0.3.0';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const OPTION_DRAFTS = 'takka_bridge_draft_themes';
    private const OPTION_BACKUPS = 'takka_bridge_theme_backups';
    private const TRANSIENT_SECRET_PREFIX = 'takka_bridge_secret_once_';
    private const TRANSIENT_PREVIEW_PREFIX = 'takka_bridge_preview_';
    private const MAX_CLOCK_SKEW = 300;
    private const MAX_FILE_BYTES = 2097152;
    private const MAX_SEARCH_FILE_BYTES = 1048576;
    private const MAX_SEARCH_RESULTS = 200;
    private const MAX_THEME_FILES = 3000;
    private const MAX_THEME_BYTES = 52428800;
    private const MAX_BACKUPS = 10;

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'register_routes']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_post_takka_bridge_generate_key', [self::class, 'handle_generate_key']);

        add_filter('pre_option_stylesheet', [self::class, 'preview_stylesheet']);
        add_filter('pre_option_template', [self::class, 'preview_template']);
        add_action('template_redirect', [self::class, 'preview_no_cache'], 0);
    }

    public static function admin_menu(): void
    {
        add_management_page(
            'TakKa WordPress Bridge',
            'TakKa WP Bridge',
            'manage_options',
            'takka-wordpress-bridge',
            [self::class, 'render_settings_page']
        );
    }

    public static function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        $configured = (string) get_option(self::OPTION_SECRET, '') !== '' && $user_id > 0;
        $one_time_secret = get_transient(self::TRANSIENT_SECRET_PREFIX . get_current_user_id());
        if (is_string($one_time_secret) && $one_time_secret !== '') {
            delete_transient(self::TRANSIENT_SECRET_PREFIX . get_current_user_id());
        } else {
            $one_time_secret = '';
        }

        $bridge_user = $user_id > 0 ? get_user_by('id', $user_id) : false;
        ?>
        <div class="wrap">
            <h1>TakKa WordPress Bridge</h1>
            <p><strong>Version:</strong> <?php echo esc_html(self::VERSION); ?></p>
            <p><strong>Status:</strong> <?php echo $configured ? 'Configured' : 'Not configured'; ?></p>
            <?php if ($bridge_user) : ?>
                <p><strong>Bridge user:</strong> <?php echo esc_html($bridge_user->user_login); ?></p>
            <?php endif; ?>

            <?php if ($one_time_secret !== '') : ?>
                <div class="notice notice-success">
                    <p><strong>Bridge key generated. Copy it now. It will not be shown again.</strong></p>
                    <p><input type="text" readonly value="<?php echo esc_attr($one_time_secret); ?>" style="width:100%;max-width:900px;font-family:monospace"></p>
                    <p>Save this value in the GitHub Actions repository secret named <code>WP_BRIDGE_SECRET</code>.</p>
                </div>
            <?php endif; ?>

            <p>The bridge uses HMAC authentication. WordPress Application Passwords are not required.</p>
            <p>Theme development should normally use a draft theme, preview it, then publish it. Publishing automatically creates a rollback snapshot.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="takka_bridge_generate_key">
                <?php wp_nonce_field('takka_bridge_generate_key'); ?>
                <?php submit_button($configured ? 'Rotate Bridge Key' : 'Generate Bridge Key'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_generate_key(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('takka_bridge_generate_key');

        try {
            $secret = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            $secret = wp_generate_password(64, false, false);
        }

        update_option(self::OPTION_SECRET, $secret, false);
        update_option(self::OPTION_USER_ID, get_current_user_id(), false);
        set_transient(
            self::TRANSIENT_SECRET_PREFIX . get_current_user_id(),
            $secret,
            10 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect(admin_url('tools.php?page=takka-wordpress-bridge&generated=1'));
        exit;
    }

    public static function register_routes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'health'],
            'permission_callback' => [self::class, 'authorize_request'],
        ]);

        register_rest_route(self::REST_NAMESPACE, '/execute', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'execute'],
            'permission_callback' => [self::class, 'authorize_request'],
            'args' => [
                'action' => [
                    'required' => true,
                    'type' => 'string',
                ],
                'params' => [
                    'required' => false,
                    'type' => 'object',
                    'default' => [],
                ],
            ],
        ]);
    }

    public static function authorize_request(WP_REST_Request $request)
    {
        $secret = (string) get_option(self::OPTION_SECRET, '');
        $user_id = (int) get_option(self::OPTION_USER_ID, 0);
        if ($secret === '' || $user_id < 1) {
            return new WP_Error(
                'takka_bridge_not_configured',
                'TakKa WordPress Bridge is not configured.',
                ['status' => 503]
            );
        }

        $timestamp = trim((string) $request->get_header('x-takka-timestamp'));
        $signature = strtolower(trim((string) $request->get_header('x-takka-signature')));
        if ($timestamp === '' || $signature === '') {
            return new WP_Error('takka_bridge_missing_signature', 'Missing bridge signature.', ['status' => 401]);
        }
        if (!ctype_digit($timestamp)) {
            return new WP_Error('takka_bridge_invalid_timestamp', 'Invalid bridge timestamp.', ['status' => 401]);
        }
        if (abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW) {
            return new WP_Error('takka_bridge_expired_signature', 'Bridge signature has expired.', ['status' => 401]);
        }

        $body_hash = hash('sha256', (string) $request->get_body());
        $payload = $timestamp . "\n"
            . strtoupper((string) $request->get_method()) . "\n"
            . (string) $request->get_route() . "\n"
            . $body_hash;
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

    public static function health(): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => true,
            'bridge_version' => self::VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'site_url' => site_url(),
            'user_id' => get_current_user_id(),
            'features' => [
                'internal_rest_proxy',
                'theme_drafts',
                'theme_preview',
                'theme_publish_rollback',
                'theme_file_editing',
                'theme_file_search',
                'php_syntax_validation',
                'db_select',
                'abilities_api',
            ],
        ]);
    }

    public static function execute(WP_REST_Request $request)
    {
        $action = (string) $request->get_param('action');
        $params = $request->get_param('params');
        if (!is_array($params)) {
            $params = [];
        }

        try {
            switch ($action) {
                case 'rest.call':
                    return self::rest_call($params);
                case 'system.capabilities':
                    return rest_ensure_response(self::system_capabilities());
                case 'site.info':
                    return rest_ensure_response(self::site_info());
                case 'cache.flush':
                    return rest_ensure_response(self::cache_flush());
                case 'rewrite.flush':
                    return rest_ensure_response(self::rewrite_flush());
                case 'option.get':
                    return self::option_get($params);
                case 'option.update':
                    return self::option_update($params);
                case 'cron.list':
                    return rest_ensure_response(self::cron_list());
                case 'post_meta.get':
                    return self::post_meta_get($params);
                case 'post_meta.update':
                    return self::post_meta_update($params);
                case 'post_meta.delete':
                    return self::post_meta_delete($params);
                case 'media.upload_from_url':
                    return self::media_upload_from_url($params);

                case 'theme.files.list':
                    return self::theme_files_list($params);
                case 'theme.files.search':
                    return self::theme_files_search($params);
                case 'theme.file.read':
                    return self::theme_file_read($params);
                case 'theme.file.lint':
                    return self::theme_file_lint($params);
                case 'theme.file.write':
                    return self::theme_file_write($params);
                case 'theme.file.delete':
                    return self::theme_file_delete($params);

                case 'draft_theme.create':
                    return self::draft_theme_create($params);
                case 'draft_theme.list':
                    return rest_ensure_response(self::draft_theme_list());
                case 'draft_theme.info':
                    return self::draft_theme_info($params);
                case 'draft_theme.preview_url':
                    return self::draft_theme_preview_url($params);
                case 'draft_theme.publish':
                    return self::draft_theme_publish($params);
                case 'draft_theme.discard':
                    return self::draft_theme_discard($params);

                case 'theme.backups.list':
                    return rest_ensure_response(self::theme_backups_list());
                case 'theme.rollback':
                    return self::theme_rollback($params);

                case 'db.select':
                    return self::db_select($params);

                case 'abilities.list':
                    return self::abilities_list($params);
                case 'abilities.get':
                    return self::abilities_get($params);
                case 'abilities.run':
                    return self::abilities_run($params);

                default:
                    return new WP_Error(
                        'takka_bridge_unknown_action',
                        'Unknown bridge action.',
                        ['status' => 400, 'action' => $action]
                    );
            }
        } catch (Throwable $e) {
            return new WP_Error(
                'takka_bridge_exception',
                $e->getMessage(),
                ['status' => 500, 'type' => get_class($e)]
            );
        }
    }

    private static function rest_call(array $params)
    {
        $route = self::required_string($params, 'route');
        if (is_wp_error($route)) {
            return $route;
        }
        if (strpos($route, '/') !== 0) {
            $route = '/' . $route;
        }
        if (strpos($route, '/' . self::REST_NAMESPACE) === 0) {
            return new WP_Error('takka_bridge_recursive_rest_call', 'Calling the bridge recursively is blocked.', ['status' => 400]);
        }

        $method = strtoupper(isset($params['method']) ? (string) $params['method'] : 'GET');
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return new WP_Error('takka_bridge_invalid_method', 'Unsupported REST method.', ['status' => 400]);
        }

        $internal = new WP_REST_Request($method, $route);
        if (isset($params['query']) && is_array($params['query'])) {
            $internal->set_query_params($params['query']);
        }
        if (array_key_exists('body', $params)) {
            $internal->set_header('content-type', 'application/json');
            $internal->set_body(wp_json_encode($params['body']));
        }

        $response = rest_do_request($internal);
        if (is_wp_error($response)) {
            return $response;
        }

        return rest_ensure_response([
            'status' => $response->get_status(),
            'headers' => $response->get_headers(),
            'data' => $response->get_data(),
        ]);
    }

    private static function system_capabilities(): array
    {
        return [
            'bridge_version' => self::VERSION,
            'actions' => [
                'rest.call',
                'system.capabilities',
                'site.info',
                'cache.flush',
                'rewrite.flush',
                'option.get',
                'option.update',
                'cron.list',
                'post_meta.get',
                'post_meta.update',
                'post_meta.delete',
                'media.upload_from_url',
                'theme.files.list',
                'theme.files.search',
                'theme.file.read',
                'theme.file.lint',
                'theme.file.write',
                'theme.file.delete',
                'draft_theme.create',
                'draft_theme.list',
                'draft_theme.info',
                'draft_theme.preview_url',
                'draft_theme.publish',
                'draft_theme.discard',
                'theme.backups.list',
                'theme.rollback',
                'db.select',
                'abilities.list',
                'abilities.get',
                'abilities.run',
            ],
            'limits' => [
                'max_file_bytes' => self::MAX_FILE_BYTES,
                'max_search_results' => self::MAX_SEARCH_RESULTS,
                'max_theme_files' => self::MAX_THEME_FILES,
                'max_theme_bytes' => self::MAX_THEME_BYTES,
                'max_backups' => self::MAX_BACKUPS,
            ],
        ];
    }

    private static function site_info(): array
    {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $theme = wp_get_theme();
        $plugins = [];
        foreach (get_plugins() as $file => $data) {
            $plugins[] = [
                'file' => $file,
                'name' => $data['Name'] ?? $file,
                'version' => $data['Version'] ?? null,
                'active' => is_plugin_active($file),
            ];
        }

        return [
            'bridge_version' => self::VERSION,
            'site' => [
                'name' => get_bloginfo('name'),
                'url' => site_url(),
                'home' => home_url(),
                'wordpress_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'multisite' => is_multisite(),
            ],
            'theme' => [
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version'),
                'stylesheet' => get_stylesheet(),
                'template' => get_template(),
                'is_child_theme' => is_child_theme(),
            ],
            'plugins' => $plugins,
            'draft_themes' => array_values(self::get_drafts()),
            'theme_backups' => array_values(self::get_backups()),
        ];
    }

    private static function cache_flush(): array
    {
        $result = wp_cache_flush();
        return ['ok' => (bool) $result];
    }

    private static function rewrite_flush(): array
    {
        flush_rewrite_rules(false);
        return ['ok' => true, 'hard' => false];
    }

    private static function option_get(array $params)
    {
        $name = self::required_string($params, 'name');
        if (is_wp_error($name)) {
            return $name;
        }
        if (self::is_sensitive_option($name)) {
            return new WP_Error('takka_bridge_sensitive_option', 'Reading this option is blocked.', ['status' => 403]);
        }

        $sentinel = new stdClass();
        $value = get_option($name, $sentinel);
        if ($value === $sentinel) {
            return new WP_Error('takka_bridge_option_not_found', 'Option not found.', ['status' => 404]);
        }

        return rest_ensure_response(['name' => $name, 'value' => $value]);
    }

    private static function option_update(array $params)
    {
        $name = self::required_string($params, 'name');
        if (is_wp_error($name)) {
            return $name;
        }
        if (!array_key_exists('value', $params)) {
            return new WP_Error('takka_bridge_missing_value', 'Missing value.', ['status' => 400]);
        }
        if (self::is_critical_option($name) || self::is_sensitive_option($name)) {
            return new WP_Error('takka_bridge_blocked_option', 'Updating this option is blocked.', ['status' => 403]);
        }

        $before = get_option($name, null);
        $changed = update_option($name, $params['value']);
        $after = get_option($name, null);

        return rest_ensure_response([
            'name' => $name,
            'changed' => (bool) $changed,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private static function cron_list(): array
    {
        $cron = _get_cron_array();
        $items = [];
        if (!is_array($cron)) {
            return $items;
        }

        foreach ($cron as $timestamp => $hooks) {
            foreach ($hooks as $hook => $events) {
                foreach ($events as $event) {
                    $items[] = [
                        'timestamp' => (int) $timestamp,
                        'datetime_utc' => gmdate('c', (int) $timestamp),
                        'hook' => $hook,
                        'schedule' => $event['schedule'] ?? null,
                        'interval' => $event['interval'] ?? null,
                        'args' => $event['args'] ?? [],
                    ];
                }
            }
        }
        return $items;
    }

    private static function post_meta_get(array $params)
    {
        $post_id = self::required_int($params, 'post_id');
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        if (!get_post($post_id)) {
            return new WP_Error('takka_bridge_post_not_found', 'Post not found.', ['status' => 404]);
        }

        $key = isset($params['key']) ? (string) $params['key'] : '';
        if ($key !== '') {
            return rest_ensure_response([
                'post_id' => $post_id,
                'key' => $key,
                'value' => get_post_meta($post_id, $key, true),
            ]);
        }

        return rest_ensure_response([
            'post_id' => $post_id,
            'meta' => get_post_meta($post_id),
        ]);
    }

    private static function post_meta_update(array $params)
    {
        $post_id = self::required_int($params, 'post_id');
        $key = self::required_string($params, 'key');
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        if (is_wp_error($key)) {
            return $key;
        }
        if (!array_key_exists('value', $params)) {
            return new WP_Error('takka_bridge_missing_value', 'Missing value.', ['status' => 400]);
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('takka_bridge_cannot_edit_post', 'You cannot edit this post.', ['status' => 403]);
        }

        $before = get_post_meta($post_id, $key, true);
        update_post_meta($post_id, $key, $params['value']);
        $after = get_post_meta($post_id, $key, true);

        return rest_ensure_response([
            'post_id' => $post_id,
            'key' => $key,
            'before' => $before,
            'after' => $after,
        ]);
    }

    private static function post_meta_delete(array $params)
    {
        $post_id = self::required_int($params, 'post_id');
        $key = self::required_string($params, 'key');
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        if (is_wp_error($key)) {
            return $key;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return new WP_Error('takka_bridge_cannot_edit_post', 'You cannot edit this post.', ['status' => 403]);
        }

        $deleted = delete_post_meta($post_id, $key);
        return rest_ensure_response(['post_id' => $post_id, 'key' => $key, 'deleted' => (bool) $deleted]);
    }

    private static function media_upload_from_url(array $params)
    {
        $url = self::required_string($params, 'url');
        if (is_wp_error($url)) {
            return $url;
        }
        if (!wp_http_validate_url($url)) {
            return new WP_Error('takka_bridge_invalid_url', 'Invalid media URL.', ['status' => 400]);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($url, 30);
        if (is_wp_error($tmp)) {
            return $tmp;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        if ($filename === '' || $filename === '/' || strpos($filename, '.') === false) {
            $filename = 'download-' . gmdate('Ymd-His') . '.jpg';
        }

        $file_array = [
            'name' => sanitize_file_name($filename),
            'tmp_name' => $tmp,
        ];

        $post_id = isset($params['post_id']) ? absint($params['post_id']) : 0;
        $description = isset($params['description']) ? (string) $params['description'] : null;
        $attachment_id = media_handle_sideload($file_array, $post_id, $description);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        if (!empty($params['alt_text'])) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field((string) $params['alt_text']));
        }

        return rest_ensure_response([
            'id' => $attachment_id,
            'url' => wp_get_attachment_url($attachment_id),
            'mime_type' => get_post_mime_type($attachment_id),
            'title' => get_the_title($attachment_id),
        ]);
    }

    private static function theme_files_list(array $params)
    {
        $context = self::theme_context($params, false);
        if (is_wp_error($context)) {
            return $context;
        }

        $files = self::collect_theme_files($context['root']);
        if (is_wp_error($files)) {
            return $files;
        }

        return rest_ensure_response([
            'scope' => $context['scope'],
            'draft_id' => $context['draft_id'],
            'stylesheet' => $context['stylesheet'],
            'root' => basename($context['root']),
            'files' => $files,
            'truncated' => count($files) >= self::MAX_THEME_FILES,
        ]);
    }

    private static function theme_files_search(array $params)
    {
        $query = self::required_string($params, 'query');
        if (is_wp_error($query)) {
            return $query;
        }
        if (strlen($query) > 300) {
            return new WP_Error('takka_bridge_search_query_too_long', 'Search query is too long.', ['status' => 400]);
        }

        $context = self::theme_context($params, false);
        if (is_wp_error($context)) {
            return $context;
        }

        $regex = !empty($params['regex']);
        $case_sensitive = !empty($params['case_sensitive']);
        $limit = isset($params['limit']) ? max(1, min(self::MAX_SEARCH_RESULTS, (int) $params['limit'])) : 100;
        $results = [];

        $files = self::collect_theme_files($context['root']);
        if (is_wp_error($files)) {
            return $files;
        }

        foreach ($files as $entry) {
            if (count($results) >= $limit) {
                break;
            }
            if ($entry['size'] > self::MAX_SEARCH_FILE_BYTES) {
                continue;
            }
            $path = $context['root'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry['path']);
            $content = @file_get_contents($path);
            if (!is_string($content)) {
                continue;
            }
            $lines = preg_split('/\R/u', $content);
            if (!is_array($lines)) {
                continue;
            }

            foreach ($lines as $index => $line) {
                $matched = false;
                if ($regex) {
                    $flags = $case_sensitive ? 'u' : 'iu';
                    $matched = @preg_match('~' . str_replace('~', '\~', $query) . '~' . $flags, $line) === 1;
                } else {
                    $matched = $case_sensitive
                        ? strpos($line, $query) !== false
                        : stripos($line, $query) !== false;
                }
                if ($matched) {
                    $results[] = [
                        'path' => $entry['path'],
                        'line' => $index + 1,
                        'text' => mb_substr($line, 0, 500),
                    ];
                    if (count($results) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return rest_ensure_response([
            'scope' => $context['scope'],
            'draft_id' => $context['draft_id'],
            'query' => $query,
            'regex' => $regex,
            'case_sensitive' => $case_sensitive,
            'results' => $results,
            'truncated' => count($results) >= $limit,
        ]);
    }

    private static function theme_file_read(array $params)
    {
        $relative = self::required_string($params, 'path');
        if (is_wp_error($relative)) {
            return $relative;
        }

        $context = self::theme_context($params, false);
        if (is_wp_error($context)) {
            return $context;
        }

        $resolved = self::resolve_theme_file($context['root'], $relative, true);
        if (is_wp_error($resolved)) {
            return $resolved;
        }

        $content = file_get_contents($resolved);
        if ($content === false) {
            return new WP_Error('takka_bridge_file_read_failed', 'Could not read theme file.', ['status' => 500]);
        }

        return rest_ensure_response([
            'scope' => $context['scope'],
            'draft_id' => $context['draft_id'],
            'path' => self::relative_path($context['root'], $resolved),
            'size' => strlen($content),
            'sha256' => hash('sha256', $content),
            'content' => $content,
        ]);
    }

    private static function theme_file_lint(array $params)
    {
        $content = null;
        $path = isset($params['path']) ? trim((string) $params['path']) : '';

        if (array_key_exists('content', $params)) {
            if (!is_string($params['content'])) {
                return new WP_Error('takka_bridge_invalid_content', 'Content must be a string.', ['status' => 400]);
            }
            $content = $params['content'];
        } elseif ($path !== '') {
            $context = self::theme_context($params, false);
            if (is_wp_error($context)) {
                return $context;
            }
            $resolved = self::resolve_theme_file($context['root'], $path, true);
            if (is_wp_error($resolved)) {
                return $resolved;
            }
            $content = file_get_contents($resolved);
            if (!is_string($content)) {
                return new WP_Error('takka_bridge_file_read_failed', 'Could not read theme file.', ['status' => 500]);
            }
        } else {
            return new WP_Error('takka_bridge_missing_parameter', 'Provide path or content.', ['status' => 400]);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'php' || ($path === '' && strpos(ltrim($content), '<?php') === 0)) {
            $lint = self::lint_php($content);
            if (is_wp_error($lint)) {
                return $lint;
            }
        }

        return rest_ensure_response([
            'ok' => true,
            'path' => $path !== '' ? $path : null,
            'bytes' => strlen($content),
        ]);
    }

    private static function theme_file_write(array $params)
    {
        $relative = self::required_string($params, 'path');
        if (is_wp_error($relative)) {
            return $relative;
        }
        if (!array_key_exists('content', $params) || !is_string($params['content'])) {
            return new WP_Error('takka_bridge_invalid_content', 'Content must be a string.', ['status' => 400]);
        }
        $content = $params['content'];
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return new WP_Error('takka_bridge_file_too_large', 'File exceeds the bridge size limit.', ['status' => 413]);
        }

        $context = self::theme_context($params, true);
        if (is_wp_error($context)) {
            return $context;
        }

        $target = self::resolve_theme_file($context['root'], $relative, false);
        if (is_wp_error($target)) {
            return $target;
        }

        $extension = strtolower(pathinfo($target, PATHINFO_EXTENSION));
        if ($extension === 'php') {
            $lint = self::lint_php($content);
            if (is_wp_error($lint)) {
                return $lint;
            }
        }

        $backup_id = null;
        if ($context['scope'] === 'active') {
            $backup = self::create_theme_backup('direct-file-write');
            if (is_wp_error($backup)) {
                return $backup;
            }
            $backup_id = $backup['id'];
        }

        $dir = dirname($target);
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('takka_bridge_directory_create_failed', 'Could not create target directory.', ['status' => 500]);
        }

        $before = is_file($target) ? @file_get_contents($target) : null;
        $tmp = $target . '.takka-tmp-' . wp_generate_password(8, false, false);
        if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
            return new WP_Error('takka_bridge_file_write_failed', 'Could not write temporary file.', ['status' => 500]);
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return new WP_Error('takka_bridge_file_replace_failed', 'Could not replace target file.', ['status' => 500]);
        }

        self::touch_draft($context['draft_id']);

        return rest_ensure_response([
            'ok' => true,
            'scope' => $context['scope'],
            'draft_id' => $context['draft_id'],
            'path' => self::relative_path($context['root'], $target),
            'created' => $before === null,
            'before_sha256' => is_string($before) ? hash('sha256', $before) : null,
            'after_sha256' => hash('sha256', $content),
            'bytes' => strlen($content),
            'backup_id' => $backup_id,
        ]);
    }

    private static function theme_file_delete(array $params)
    {
        $relative = self::required_string($params, 'path');
        if (is_wp_error($relative)) {
            return $relative;
        }

        $context = self::theme_context($params, true);
        if (is_wp_error($context)) {
            return $context;
        }

        $target = self::resolve_theme_file($context['root'], $relative, true);
        if (is_wp_error($target)) {
            return $target;
        }

        $backup_id = null;
        if ($context['scope'] === 'active') {
            $backup = self::create_theme_backup('direct-file-delete');
            if (is_wp_error($backup)) {
                return $backup;
            }
            $backup_id = $backup['id'];
        }

        if (!@unlink($target)) {
            return new WP_Error('takka_bridge_file_delete_failed', 'Could not delete theme file.', ['status' => 500]);
        }

        self::touch_draft($context['draft_id']);

        return rest_ensure_response([
            'ok' => true,
            'scope' => $context['scope'],
            'draft_id' => $context['draft_id'],
            'path' => $relative,
            'backup_id' => $backup_id,
        ]);
    }

    private static function draft_theme_create(array $params)
    {
        $source = realpath(get_stylesheet_directory());
        if ($source === false || !is_dir($source)) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Active stylesheet directory was not found.', ['status' => 500]);
        }

        $id = 'draft-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false));
        $slug = 'takka-' . $id;
        $theme_root = realpath(get_theme_root());
        if ($theme_root === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Theme root was not found.', ['status' => 500]);
        }

        $target = $theme_root . DIRECTORY_SEPARATOR . $slug;
        if (file_exists($target)) {
            return new WP_Error('takka_bridge_draft_exists', 'Draft directory already exists.', ['status' => 409]);
        }

        $copy = self::copy_tree($source, $target);
        if (is_wp_error($copy)) {
            self::remove_tree($target);
            return $copy;
        }

        $lint = self::lint_theme_php($target);
        if (is_wp_error($lint)) {
            self::remove_tree($target);
            return $lint;
        }

        $drafts = self::get_drafts();
        $draft = [
            'id' => $id,
            'slug' => $slug,
            'source_stylesheet' => get_stylesheet(),
            'template' => get_template(),
            'created_at' => gmdate('c'),
            'modified_at' => gmdate('c'),
            'file_count' => $copy['files'],
            'bytes' => $copy['bytes'],
        ];
        $drafts[$id] = $draft;
        self::save_drafts($drafts);

        return rest_ensure_response($draft);
    }

    private static function draft_theme_list(): array
    {
        return [
            'active_stylesheet' => get_stylesheet(),
            'drafts' => array_values(self::get_drafts()),
        ];
    }

    private static function draft_theme_info(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $root = self::draft_root($draft);
        $files = self::collect_theme_files($root);
        if (is_wp_error($files)) {
            return $files;
        }

        $lint = self::lint_theme_php($root);

        return rest_ensure_response([
            'draft' => $draft,
            'root' => basename($root),
            'file_count' => count($files),
            'php_valid' => !is_wp_error($lint),
            'php_error' => is_wp_error($lint) ? $lint->get_error_message() : null,
        ]);
    }

    private static function draft_theme_preview_url(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }

        try {
            $token = bin2hex(random_bytes(24));
        } catch (Throwable $e) {
            $token = wp_generate_password(48, false, false);
        }

        $ttl = isset($params['ttl']) ? max(300, min(86400, (int) $params['ttl'])) : 7200;
        $key = self::TRANSIENT_PREVIEW_PREFIX . hash('sha256', $token);
        set_transient($key, [
            'draft_id' => $draft['id'],
            'stylesheet' => $draft['slug'],
            'template' => $draft['template'],
        ], $ttl);

        $path = isset($params['path']) && is_string($params['path']) ? $params['path'] : '/';
        if ($path === '' || $path[0] !== '/' || strpos($path, '://') !== false) {
            return new WP_Error('takka_bridge_invalid_preview_path', 'Preview path must be a local path beginning with /.', ['status' => 400]);
        }

        $url = add_query_arg('takka_theme_preview', rawurlencode($token), home_url($path));

        return rest_ensure_response([
            'draft_id' => $draft['id'],
            'url' => $url,
            'expires_in' => $ttl,
        ]);
    }

    private static function draft_theme_publish(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_publish_confirmation_required', 'Set confirm=true to publish a draft theme.', ['status' => 400]);
        }

        $draft_root = self::draft_root($draft);
        $lint = self::lint_theme_php($draft_root);
        if (is_wp_error($lint)) {
            return $lint;
        }

        $backup = self::create_theme_backup('draft-publish:' . $draft['id']);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $active_root = realpath(get_stylesheet_directory());
        $theme_root = realpath(get_theme_root());
        if ($active_root === false || $theme_root === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Theme root was not found.', ['status' => 500]);
        }

        $active_slug = basename($active_root);
        $staging = $theme_root . DIRECTORY_SEPARATOR . $active_slug . '-takka-staging-' . strtolower(wp_generate_password(6, false, false));
        $old = $theme_root . DIRECTORY_SEPARATOR . $active_slug . '-takka-old-' . strtolower(wp_generate_password(6, false, false));

        $copy = self::copy_tree($draft_root, $staging);
        if (is_wp_error($copy)) {
            self::remove_tree($staging);
            return $copy;
        }

        if (!@rename($active_root, $old)) {
            self::remove_tree($staging);
            return new WP_Error('takka_bridge_theme_swap_failed', 'Could not move the active theme aside.', ['status' => 500]);
        }

        if (!@rename($staging, $active_root)) {
            @rename($old, $active_root);
            self::remove_tree($staging);
            return new WP_Error('takka_bridge_theme_swap_failed', 'Could not activate the staged theme files. Original files were restored.', ['status' => 500]);
        }

        self::remove_tree($old);
        wp_clean_themes_cache(true);
        wp_cache_flush();

        $keep_draft = !empty($params['keep_draft']);
        if (!$keep_draft) {
            self::remove_tree($draft_root);
            $drafts = self::get_drafts();
            unset($drafts[$draft['id']]);
            self::save_drafts($drafts);
        }

        return rest_ensure_response([
            'ok' => true,
            'draft_id' => $draft['id'],
            'active_stylesheet' => $active_slug,
            'backup_id' => $backup['id'],
            'kept_draft' => $keep_draft,
        ]);
    }

    private static function draft_theme_discard(array $params)
    {
        $draft = self::require_draft($params);
        if (is_wp_error($draft)) {
            return $draft;
        }

        $root = self::draft_root($draft);
        $removed = self::remove_tree($root);
        if (!$removed && is_dir($root)) {
            return new WP_Error('takka_bridge_draft_delete_failed', 'Could not remove draft theme directory.', ['status' => 500]);
        }

        $drafts = self::get_drafts();
        unset($drafts[$draft['id']]);
        self::save_drafts($drafts);

        return rest_ensure_response(['ok' => true, 'draft_id' => $draft['id']]);
    }

    private static function theme_backups_list(): array
    {
        return [
            'backups' => array_values(self::get_backups()),
        ];
    }

    private static function theme_rollback(array $params)
    {
        $backup_id = self::required_string($params, 'backup_id');
        if (is_wp_error($backup_id)) {
            return $backup_id;
        }
        if (empty($params['confirm'])) {
            return new WP_Error('takka_bridge_rollback_confirmation_required', 'Set confirm=true to rollback.', ['status' => 400]);
        }

        $backups = self::get_backups();
        if (!isset($backups[$backup_id])) {
            return new WP_Error('takka_bridge_backup_not_found', 'Theme backup not found.', ['status' => 404]);
        }
        $backup = $backups[$backup_id];
        $backup_root = isset($backup['path']) ? (string) $backup['path'] : '';
        if ($backup_root === '' || !is_dir($backup_root)) {
            return new WP_Error('takka_bridge_backup_missing', 'Theme backup files are missing.', ['status' => 410]);
        }

        $safety = self::create_theme_backup('pre-rollback:' . $backup_id);
        if (is_wp_error($safety)) {
            return $safety;
        }

        $active_root = realpath(get_stylesheet_directory());
        $theme_root = realpath(get_theme_root());
        if ($active_root === false || $theme_root === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Theme root was not found.', ['status' => 500]);
        }

        $active_slug = basename($active_root);
        $staging = $theme_root . DIRECTORY_SEPARATOR . $active_slug . '-takka-rollback-' . strtolower(wp_generate_password(6, false, false));
        $old = $theme_root . DIRECTORY_SEPARATOR . $active_slug . '-takka-old-' . strtolower(wp_generate_password(6, false, false));

        $copy = self::copy_tree($backup_root, $staging);
        if (is_wp_error($copy)) {
            self::remove_tree($staging);
            return $copy;
        }

        $lint = self::lint_theme_php($staging);
        if (is_wp_error($lint)) {
            self::remove_tree($staging);
            return $lint;
        }

        if (!@rename($active_root, $old)) {
            self::remove_tree($staging);
            return new WP_Error('takka_bridge_theme_swap_failed', 'Could not move the active theme aside.', ['status' => 500]);
        }
        if (!@rename($staging, $active_root)) {
            @rename($old, $active_root);
            self::remove_tree($staging);
            return new WP_Error('takka_bridge_theme_swap_failed', 'Rollback swap failed. Original files were restored.', ['status' => 500]);
        }

        self::remove_tree($old);
        wp_clean_themes_cache(true);
        wp_cache_flush();

        return rest_ensure_response([
            'ok' => true,
            'restored_backup_id' => $backup_id,
            'safety_backup_id' => $safety['id'],
        ]);
    }

    private static function db_select(array $params)
    {
        global $wpdb;

        $sql = self::required_string($params, 'sql');
        if (is_wp_error($sql)) {
            return $sql;
        }
        if (strlen($sql) > 20000) {
            return new WP_Error('takka_bridge_sql_too_long', 'SQL query is too long.', ['status' => 400]);
        }

        $trimmed = trim($sql);
        $trimmed = rtrim($trimmed, " \t\n\r\0\x0B;");
        if (!preg_match('/^SELECT\b/i', $trimmed)) {
            return new WP_Error('takka_bridge_select_only', 'Only SELECT statements are allowed.', ['status' => 403]);
        }
        if (strpos($trimmed, ';') !== false) {
            return new WP_Error('takka_bridge_multiple_statements', 'Multiple SQL statements are not allowed.', ['status' => 403]);
        }

        $blocked_fragments = [
            'INTO OUTFILE',
            'INTO DUMPFILE',
            'LOAD_FILE(',
            'SLEEP(',
            'BENCHMARK(',
            'GET_LOCK(',
            'RELEASE_LOCK(',
        ];
        $upper = strtoupper($trimmed);
        foreach ($blocked_fragments as $fragment) {
            if (strpos($upper, $fragment) !== false) {
                return new WP_Error('takka_bridge_sql_blocked', 'SQL contains a blocked operation.', ['status' => 403]);
            }
        }

        $sensitive_tables = [
            $wpdb->options,
            $wpdb->users,
            $wpdb->usermeta,
        ];
        foreach ($sensitive_tables as $table) {
            if ($table && preg_match('/\b' . preg_quote($table, '/') . '\b/i', $trimmed)) {
                return new WP_Error(
                    'takka_bridge_sensitive_table',
                    'Direct SELECT from sensitive credential-bearing tables is blocked. Use a dedicated bridge action instead.',
                    ['status' => 403, 'table' => $table]
                );
            }
        }

        $rows = $wpdb->get_results($trimmed, ARRAY_A);
        if ($wpdb->last_error) {
            return new WP_Error('takka_bridge_db_error', $wpdb->last_error, ['status' => 400]);
        }
        if (!is_array($rows)) {
            $rows = [];
        }

        $limit = isset($params['limit']) ? max(1, min(500, (int) $params['limit'])) : 200;
        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        return rest_ensure_response([
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ]);
    }

    private static function abilities_list(array $params)
    {
        if (!function_exists('wp_get_abilities')) {
            return new WP_Error('takka_bridge_abilities_unavailable', 'WordPress Abilities API is unavailable.', ['status' => 501]);
        }

        $args = [];
        foreach (['category', 'namespace'] as $key) {
            if (isset($params[$key]) && is_string($params[$key]) && $params[$key] !== '') {
                $args[$key] = $params[$key];
            }
        }

        $abilities = wp_get_abilities($args);
        $items = [];
        foreach ($abilities as $ability) {
            $items[] = self::ability_to_array($ability);
        }

        return rest_ensure_response(['abilities' => $items]);
    }

    private static function abilities_get(array $params)
    {
        if (!function_exists('wp_get_ability')) {
            return new WP_Error('takka_bridge_abilities_unavailable', 'WordPress Abilities API is unavailable.', ['status' => 501]);
        }

        $name = self::required_string($params, 'name');
        if (is_wp_error($name)) {
            return $name;
        }

        $ability = wp_get_ability($name);
        if (!$ability) {
            return new WP_Error('takka_bridge_ability_not_found', 'Ability not found.', ['status' => 404]);
        }

        return rest_ensure_response(self::ability_to_array($ability));
    }

    private static function abilities_run(array $params)
    {
        if (!function_exists('wp_get_ability')) {
            return new WP_Error('takka_bridge_abilities_unavailable', 'WordPress Abilities API is unavailable.', ['status' => 501]);
        }

        $name = self::required_string($params, 'name');
        if (is_wp_error($name)) {
            return $name;
        }
        $ability = wp_get_ability($name);
        if (!$ability) {
            return new WP_Error('takka_bridge_ability_not_found', 'Ability not found.', ['status' => 404]);
        }

        $meta = method_exists($ability, 'get_meta') ? (array) $ability->get_meta() : [];
        $annotations = isset($meta['annotations']) && is_array($meta['annotations']) ? $meta['annotations'] : [];
        if (!empty($annotations['destructive']) && empty($params['confirm_destructive'])) {
            return new WP_Error(
                'takka_bridge_destructive_confirmation_required',
                'This ability is marked destructive. Set confirm_destructive=true to execute it.',
                ['status' => 400]
            );
        }

        $input = array_key_exists('input', $params) ? $params['input'] : null;
        $result = $ability->execute($input);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response([
            'name' => $name,
            'result' => $result,
        ]);
    }

    private static function ability_to_array($ability): array
    {
        return [
            'name' => method_exists($ability, 'get_name') ? $ability->get_name() : null,
            'label' => method_exists($ability, 'get_label') ? $ability->get_label() : null,
            'description' => method_exists($ability, 'get_description') ? $ability->get_description() : null,
            'category' => method_exists($ability, 'get_category') ? $ability->get_category() : null,
            'input_schema' => method_exists($ability, 'get_input_schema') ? $ability->get_input_schema() : null,
            'output_schema' => method_exists($ability, 'get_output_schema') ? $ability->get_output_schema() : null,
            'meta' => method_exists($ability, 'get_meta') ? $ability->get_meta() : [],
        ];
    }

    public static function preview_stylesheet($pre)
    {
        $context = self::preview_context();
        if (!$context) {
            return $pre;
        }
        return $context['stylesheet'];
    }

    public static function preview_template($pre)
    {
        $context = self::preview_context();
        if (!$context) {
            return $pre;
        }
        return $context['template'];
    }

    public static function preview_no_cache(): void
    {
        if (!self::preview_context()) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }

    private static function preview_context()
    {
        if (empty($_GET['takka_theme_preview']) || !is_string($_GET['takka_theme_preview'])) {
            return null;
        }
        $token = sanitize_text_field(wp_unslash($_GET['takka_theme_preview']));
        if ($token === '') {
            return null;
        }

        $data = get_transient(self::TRANSIENT_PREVIEW_PREFIX . hash('sha256', $token));
        if (!is_array($data) || empty($data['stylesheet']) || empty($data['template'])) {
            return null;
        }

        $theme_root = realpath(get_theme_root());
        if ($theme_root === false) {
            return null;
        }
        $candidate = realpath($theme_root . DIRECTORY_SEPARATOR . basename((string) $data['stylesheet']));
        if ($candidate === false || !is_dir($candidate)) {
            return null;
        }

        return $data;
    }

    private static function theme_context(array $params, bool $write)
    {
        $draft_id = isset($params['draft_id']) && is_string($params['draft_id'])
            ? trim($params['draft_id'])
            : '';

        if ($draft_id !== '') {
            $drafts = self::get_drafts();
            if (!isset($drafts[$draft_id])) {
                return new WP_Error('takka_bridge_draft_not_found', 'Draft theme not found.', ['status' => 404]);
            }
            $draft = $drafts[$draft_id];
            $root = self::draft_root($draft);
            if (!is_dir($root)) {
                return new WP_Error('takka_bridge_draft_missing', 'Draft theme directory is missing.', ['status' => 410]);
            }
            return [
                'scope' => 'draft',
                'draft_id' => $draft_id,
                'stylesheet' => $draft['slug'],
                'root' => $root,
            ];
        }

        if ($write && empty($params['confirm_active'])) {
            return new WP_Error(
                'takka_bridge_active_write_confirmation_required',
                'Writing directly to the active theme requires confirm_active=true. Prefer a draft theme.',
                ['status' => 400]
            );
        }

        $root = realpath(get_stylesheet_directory());
        if ($root === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Active theme directory not found.', ['status' => 500]);
        }

        return [
            'scope' => 'active',
            'draft_id' => null,
            'stylesheet' => get_stylesheet(),
            'root' => $root,
        ];
    }

    private static function collect_theme_files(string $root)
    {
        if (!is_dir($root)) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Theme directory not found.', ['status' => 500]);
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $extension = strtolower($file->getExtension());
            if (!self::is_allowed_theme_extension($extension)) {
                continue;
            }
            $relative = self::relative_path($root, $file->getPathname());
            $files[] = [
                'path' => $relative,
                'size' => $file->getSize(),
                'modified' => gmdate('c', $file->getMTime()),
            ];
            if (count($files) >= self::MAX_THEME_FILES) {
                break;
            }
        }

        usort($files, static function (array $a, array $b): int {
            return strcmp($a['path'], $b['path']);
        });

        return $files;
    }

    private static function resolve_theme_file(string $root, string $relative, bool $must_exist)
    {
        if ($relative === '' || strpos($relative, "\0") !== false) {
            return new WP_Error('takka_bridge_invalid_path', 'Invalid path.', ['status' => 400]);
        }
        $relative = str_replace('\\', '/', $relative);
        if ($relative[0] === '/' || preg_match('~(^|/)\.\.(/|$)~', $relative)) {
            return new WP_Error('takka_bridge_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
        }

        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!self::is_allowed_theme_extension($extension)) {
            return new WP_Error('takka_bridge_file_type_blocked', 'File type is not supported by theme editing.', ['status' => 403]);
        }

        $root_real = realpath($root);
        if ($root_real === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Theme directory not found.', ['status' => 500]);
        }

        $target = $root_real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
        if ($must_exist) {
            $real = realpath($target);
            if ($real === false || !is_file($real)) {
                return new WP_Error('takka_bridge_file_not_found', 'Theme file not found.', ['status' => 404]);
            }
            if (!self::path_inside($root_real, $real)) {
                return new WP_Error('takka_bridge_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
            }
            return $real;
        }

        $parent = dirname($target);
        $existing_parent = $parent;
        while (!is_dir($existing_parent)) {
            $next = dirname($existing_parent);
            if ($next === $existing_parent) {
                break;
            }
            $existing_parent = $next;
        }
        $parent_real = realpath($existing_parent);
        if ($parent_real === false || !self::path_inside($root_real, $parent_real, true)) {
            return new WP_Error('takka_bridge_path_escape', 'Path escapes the theme directory.', ['status' => 403]);
        }

        return $target;
    }

    private static function lint_php(string $content)
    {
        try {
            token_get_all($content, TOKEN_PARSE);
        } catch (ParseError $e) {
            return new WP_Error(
                'takka_bridge_php_syntax_error',
                $e->getMessage(),
                ['status' => 400]
            );
        }
        return true;
    }

    private static function lint_theme_php(string $root)
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $content = @file_get_contents($file->getPathname());
            if (!is_string($content)) {
                return new WP_Error('takka_bridge_file_read_failed', 'Could not read PHP file for syntax validation.', [
                    'status' => 500,
                    'path' => self::relative_path($root, $file->getPathname()),
                ]);
            }
            $lint = self::lint_php($content);
            if (is_wp_error($lint)) {
                $data = $lint->get_error_data();
                if (!is_array($data)) {
                    $data = [];
                }
                $data['path'] = self::relative_path($root, $file->getPathname());
                $lint->add_data($data);
                return $lint;
            }
        }
        return true;
    }

    private static function create_theme_backup(string $reason)
    {
        $active_root = realpath(get_stylesheet_directory());
        if ($active_root === false) {
            return new WP_Error('takka_bridge_theme_root_missing', 'Active theme directory not found.', ['status' => 500]);
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            return new WP_Error('takka_bridge_upload_dir_error', (string) $uploads['error'], ['status' => 500]);
        }

        $id = 'backup-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(6, false, false));
        $base = trailingslashit($uploads['basedir']) . 'takka-wordpress-bridge/backups/' . $id;
        if (!wp_mkdir_p($base)) {
            return new WP_Error('takka_bridge_backup_dir_failed', 'Could not create backup directory.', ['status' => 500]);
        }

        $copy = self::copy_tree($active_root, $base);
        if (is_wp_error($copy)) {
            self::remove_tree($base);
            return $copy;
        }

        $backups = self::get_backups();
        $backups[$id] = [
            'id' => $id,
            'reason' => $reason,
            'created_at' => gmdate('c'),
            'stylesheet' => get_stylesheet(),
            'path' => $base,
            'file_count' => $copy['files'],
            'bytes' => $copy['bytes'],
        ];
        self::save_backups($backups);
        self::prune_backups();

        return $backups[$id];
    }

    private static function copy_tree(string $source, string $target)
    {
        if (!is_dir($source)) {
            return new WP_Error('takka_bridge_copy_source_missing', 'Copy source directory does not exist.', ['status' => 500]);
        }
        if (!wp_mkdir_p($target)) {
            return new WP_Error('takka_bridge_copy_target_failed', 'Could not create destination directory.', ['status' => 500]);
        }

        $files = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }

            $relative = self::relative_path($source, $item->getPathname());
            if ($relative === '.git' || strpos($relative, '.git/') === 0) {
                continue;
            }

            $destination = $target . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                if (!is_dir($destination) && !wp_mkdir_p($destination)) {
                    return new WP_Error('takka_bridge_copy_failed', 'Could not create directory while copying theme.', [
                        'status' => 500,
                        'path' => $relative,
                    ]);
                }
                continue;
            }

            $files++;
            $bytes += $item->getSize();
            if ($files > self::MAX_THEME_FILES || $bytes > self::MAX_THEME_BYTES) {
                return new WP_Error(
                    'takka_bridge_theme_too_large',
                    'Theme exceeds the bridge copy limit.',
                    ['status' => 413, 'files' => $files, 'bytes' => $bytes]
                );
            }

            $parent = dirname($destination);
            if (!is_dir($parent) && !wp_mkdir_p($parent)) {
                return new WP_Error('takka_bridge_copy_failed', 'Could not create directory while copying theme.', [
                    'status' => 500,
                    'path' => $relative,
                ]);
            }
            if (!@copy($item->getPathname(), $destination)) {
                return new WP_Error('takka_bridge_copy_failed', 'Could not copy theme file.', [
                    'status' => 500,
                    'path' => $relative,
                ]);
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    private static function remove_tree(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }
        if (!is_dir($dir) || is_link($dir)) {
            return @unlink($dir);
        }

        $items = scandir($dir);
        if (!is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                if (!self::remove_tree($path)) {
                    return false;
                }
            } elseif (!@unlink($path)) {
                return false;
            }
        }
        return @rmdir($dir);
    }

    private static function draft_root(array $draft): string
    {
        return rtrim(get_theme_root(), '/\\') . DIRECTORY_SEPARATOR . basename((string) $draft['slug']);
    }

    private static function require_draft(array $params)
    {
        $id = self::required_string($params, 'draft_id');
        if (is_wp_error($id)) {
            return $id;
        }
        $drafts = self::get_drafts();
        if (!isset($drafts[$id])) {
            return new WP_Error('takka_bridge_draft_not_found', 'Draft theme not found.', ['status' => 404]);
        }
        return $drafts[$id];
    }

    private static function touch_draft($draft_id): void
    {
        if (!$draft_id || !is_string($draft_id)) {
            return;
        }
        $drafts = self::get_drafts();
        if (!isset($drafts[$draft_id])) {
            return;
        }
        $drafts[$draft_id]['modified_at'] = gmdate('c');
        self::save_drafts($drafts);
    }

    private static function get_drafts(): array
    {
        $value = get_option(self::OPTION_DRAFTS, []);
        return is_array($value) ? $value : [];
    }

    private static function save_drafts(array $drafts): void
    {
        update_option(self::OPTION_DRAFTS, $drafts, false);
    }

    private static function get_backups(): array
    {
        $value = get_option(self::OPTION_BACKUPS, []);
        return is_array($value) ? $value : [];
    }

    private static function save_backups(array $backups): void
    {
        update_option(self::OPTION_BACKUPS, $backups, false);
    }

    private static function prune_backups(): void
    {
        $backups = self::get_backups();
        if (count($backups) <= self::MAX_BACKUPS) {
            return;
        }

        uasort($backups, static function (array $a, array $b): int {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });

        while (count($backups) > self::MAX_BACKUPS) {
            $id = array_key_first($backups);
            if ($id === null) {
                break;
            }
            $backup = $backups[$id];
            if (!empty($backup['path']) && is_string($backup['path'])) {
                self::remove_tree($backup['path']);
            }
            unset($backups[$id]);
        }

        self::save_backups($backups);
    }

    private static function relative_path(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (strpos($path, $root . '/') === 0) {
            return substr($path, strlen($root) + 1);
        }
        return ltrim($path, '/');
    }

    private static function path_inside(string $root, string $path, bool $allow_root = false): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if ($allow_root && $path === $root) {
            return true;
        }
        return strpos($path . '/', $root . '/') === 0 && $path !== $root;
    }

    private static function is_allowed_theme_extension(string $extension): bool
    {
        return in_array($extension, ['php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'svg', 'xml', 'md'], true);
    }

    private static function required_string(array $params, string $key)
    {
        if (!isset($params[$key]) || !is_scalar($params[$key]) || trim((string) $params[$key]) === '') {
            return new WP_Error('takka_bridge_missing_parameter', 'Missing required parameter: ' . $key, ['status' => 400]);
        }
        return trim((string) $params[$key]);
    }

    private static function required_int(array $params, string $key)
    {
        if (!isset($params[$key]) || !is_numeric($params[$key])) {
            return new WP_Error('takka_bridge_missing_parameter', 'Missing required integer parameter: ' . $key, ['status' => 400]);
        }
        $value = absint($params[$key]);
        if ($value < 1) {
            return new WP_Error('takka_bridge_invalid_parameter', 'Invalid integer parameter: ' . $key, ['status' => 400]);
        }
        return $value;
    }

    private static function is_critical_option(string $name): bool
    {
        return in_array($name, [
            'siteurl',
            'home',
            'active_plugins',
            'stylesheet',
            'template',
            'cron',
            self::OPTION_SECRET,
            self::OPTION_USER_ID,
            self::OPTION_DRAFTS,
            self::OPTION_BACKUPS,
        ], true);
    }

    private static function is_sensitive_option(string $name): bool
    {
        if (in_array($name, [self::OPTION_SECRET], true)) {
            return true;
        }
        $lower = strtolower($name);
        foreach (['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'private_key', 'auth_key'] as $needle) {
            if (strpos($lower, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}
