<?php

if (!defined('ABSPATH')) {
    exit;
}

final class TakKa_WordPress_Bridge_Onboarding
{
    private const OPTION_CONNECTION = 'takka_bridge_github_connection_v1';
    private const OPTION_SECRET = 'takka_bridge_secret';
    private const OPTION_USER_ID = 'takka_bridge_user_id';
    private const TRANSIENT_PREFIX = 'takka_bridge_onboarding_';
    private const SERVICE_START_URL = 'https://takka-note.com/wp-json/takka-wp-bridge-onboarding/v1/start';
    private const RUNTIME_BRANCH = 'wp-agent-bridge-runtime';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_notices', [self::class, 'admin_notice']);
        add_action('admin_post_takka_bridge_connect_github', [self::class, 'handle_connect']);
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function admin_menu(): void
    {
        add_management_page(
            'WordPress Bridge - GitHub Setup',
            'WP Bridge Setup',
            'manage_options',
            'takka-wordpress-bridge-connect',
            [self::class, 'render_page']
        );
    }

    public static function admin_notice(): void
    {
        if (!current_user_can('manage_options') || self::is_connected()) {
            return;
        }
        if (isset($_GET['page']) && sanitize_key((string) $_GET['page']) === 'takka-wordpress-bridge-connect') {
            return;
        }
        $url = admin_url('tools.php?page=takka-wordpress-bridge-connect');
        ?>
        <div class="notice notice-info">
            <p><strong>WordPress Bridge:</strong> GitHub connection is not configured. <a class="button button-primary" href="<?php echo esc_url($url); ?>">Connect GitHub</a></p>
        </div>
        <?php
    }

    private static function is_connected(): bool
    {
        $connection = get_option(self::OPTION_CONNECTION, []);
        return is_array($connection)
            && !empty($connection['installation_id'])
            && !empty($connection['repository'])
            && !empty($connection['repository_id']);
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $connection = get_option(self::OPTION_CONNECTION, []);
        $connected = self::is_connected();
        $status = isset($_GET['takka_bridge_setup']) ? sanitize_key((string) $_GET['takka_bridge_setup']) : '';
        ?>
        <div class="wrap">
            <h1>WordPress Bridge - GitHub Setup</h1>

            <?php if ($status === 'complete') : ?>
                <div class="notice notice-success"><p>GitHub connection completed.</p></div>
            <?php elseif ($status === 'failed') : ?>
                <div class="notice notice-error"><p>GitHub connection could not be completed. Please try again.</p></div>
            <?php endif; ?>

            <?php if ($connected) : ?>
                <p><strong>Status:</strong> Connected</p>
                <p><strong>Repository:</strong> <code><?php echo esc_html((string) $connection['repository']); ?></code></p>
                <p><strong>Runtime branch:</strong> <code><?php echo esc_html((string) ($connection['runtime_branch'] ?? self::RUNTIME_BRANCH)); ?></code></p>
                <p><strong>Installation ID:</strong> <code><?php echo esc_html((string) $connection['installation_id']); ?></code></p>
                <p>You can reconnect if the GitHub installation or repository needs to be replaced.</p>
            <?php else : ?>
                <p><strong>Status:</strong> Not connected</p>
                <p>Connect GitHub to create and configure the private runtime repository automatically.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="takka_bridge_connect_github">
                <?php wp_nonce_field('takka_bridge_connect_github'); ?>
                <?php submit_button($connected ? 'Reconnect GitHub' : 'Connect GitHub', 'primary'); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_connect(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        check_admin_referer('takka_bridge_connect_github');

        if (!wp_http_validate_url(self::SERVICE_START_URL) || strpos(self::SERVICE_START_URL, 'https://') !== 0) {
            wp_die('Invalid onboarding service URL.', '', ['response' => 500]);
        }

        try {
            $setup_id = bin2hex(random_bytes(16));
            $setup_secret = bin2hex(random_bytes(32));
            $bridge_secret = bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            wp_die('Secure random generation failed.', '', ['response' => 500]);
        }

        update_option(self::OPTION_SECRET, $bridge_secret, false);
        update_option(self::OPTION_USER_ID, get_current_user_id(), false);

        set_transient(
            self::TRANSIENT_PREFIX . $setup_id,
            [
                'secret_hash' => hash('sha256', $setup_secret),
                'user_id' => get_current_user_id(),
                'created_at' => time(),
            ],
            20 * MINUTE_IN_SECONDS
        );

        $complete_url = rest_url('takka-bridge-onboarding/v1/complete');
        $return_url = admin_url('tools.php?page=takka-wordpress-bridge-connect');

        nocache_headers();
        header('Content-Type: text/html; charset=' . get_option('blog_charset'));
        ?>
        <!doctype html>
        <html>
        <head>
            <meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>">
            <meta name="referrer" content="no-referrer">
            <title>Connecting to GitHub</title>
        </head>
        <body>
            <p>Connecting to GitHub...</p>
            <form id="takka-bridge-onboarding" method="post" action="<?php echo esc_url(self::SERVICE_START_URL); ?>">
                <input type="hidden" name="setup_id" value="<?php echo esc_attr($setup_id); ?>">
                <input type="hidden" name="setup_secret" value="<?php echo esc_attr($setup_secret); ?>">
                <input type="hidden" name="bridge_secret" value="<?php echo esc_attr($bridge_secret); ?>">
                <input type="hidden" name="site_url" value="<?php echo esc_attr(home_url('/')); ?>">
                <input type="hidden" name="complete_url" value="<?php echo esc_attr($complete_url); ?>">
                <input type="hidden" name="return_url" value="<?php echo esc_attr($return_url); ?>">
            </form>
            <script>document.getElementById('takka-bridge-onboarding').submit();</script>
        </body>
        </html>
        <?php
        exit;
    }

    public static function register_routes(): void
    {
        register_rest_route('takka-bridge-onboarding/v1', '/complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'complete'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function complete(WP_REST_Request $request)
    {
        $body = $request->get_json_params();
        if (!is_array($body)) {
            return new WP_Error('takka_bridge_onboarding_json', 'JSON body is required.', ['status' => 400]);
        }

        $setup_id = isset($body['setup_id']) && is_string($body['setup_id']) ? strtolower(trim($body['setup_id'])) : '';
        $setup_secret = isset($body['setup_secret']) && is_string($body['setup_secret']) ? strtolower(trim($body['setup_secret'])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $setup_id) || !preg_match('/^[a-f0-9]{64}$/', $setup_secret)) {
            return new WP_Error('takka_bridge_onboarding_bad_setup', 'Invalid setup credentials.', ['status' => 400]);
        }

        $pending = get_transient(self::TRANSIENT_PREFIX . $setup_id);
        if (!is_array($pending) || empty($pending['secret_hash'])) {
            return new WP_Error('takka_bridge_onboarding_expired', 'Setup session expired.', ['status' => 410]);
        }
        if (!hash_equals((string) $pending['secret_hash'], hash('sha256', $setup_secret))) {
            return new WP_Error('takka_bridge_onboarding_forbidden', 'Invalid setup secret.', ['status' => 403]);
        }

        $installation_id = isset($body['installation_id']) ? absint($body['installation_id']) : 0;
        $repository_id = isset($body['repository_id']) ? absint($body['repository_id']) : 0;
        $repository = isset($body['repository']) && is_string($body['repository']) ? trim($body['repository']) : '';
        $runtime_branch = isset($body['runtime_branch']) && is_string($body['runtime_branch']) ? trim($body['runtime_branch']) : self::RUNTIME_BRANCH;

        if ($installation_id < 1 || $repository_id < 1 || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)) {
            return new WP_Error('takka_bridge_onboarding_bad_connection', 'Invalid GitHub connection data.', ['status' => 400]);
        }
        if ($runtime_branch !== self::RUNTIME_BRANCH) {
            return new WP_Error('takka_bridge_onboarding_bad_branch', 'Unexpected runtime branch.', ['status' => 400]);
        }

        update_option(self::OPTION_CONNECTION, [
            'installation_id' => $installation_id,
            'repository_id' => $repository_id,
            'repository' => $repository,
            'runtime_branch' => self::RUNTIME_BRANCH,
            'connected_at_gmt' => gmdate('c'),
        ], false);

        delete_transient(self::TRANSIENT_PREFIX . $setup_id);

        return rest_ensure_response([
            'ok' => true,
            'repository' => $repository,
            'runtime_branch' => self::RUNTIME_BRANCH,
        ]);
    }
}
