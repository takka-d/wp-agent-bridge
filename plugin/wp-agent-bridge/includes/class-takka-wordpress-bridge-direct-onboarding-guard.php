<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Safety guard for self-contained onboarding.
 *
 * A working Direct Runtime must never be destroyed merely because the user
 * clicked Reconnect and then abandoned or failed the GitHub App setup flow.
 * During migration from the previous relay design, preserve the legacy local
 * connection option as rollback metadata until cutover is explicitly chosen.
 */
final class TakKa_WordPress_Bridge_Direct_Onboarding_Guard
{
    private const LEGACY_CONNECTION = 'takka_bridge_github_connection_v1';
    private const LEGACY_BACKUP = 'takka_bridge_legacy_connection_backup_v1';
    private const IDENTITY_WARNING = 'takka_bridge_direct_identity_warning_v1';
    private const LEGACY_COMPLETE_ROUTE = '/takka-bridge-onboarding/v1/complete';

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'preserve_legacy_connection'], 1);
        add_action('admin_post_takka_bridge_connect_github', [self::class, 'block_unsafe_reconnect'], 1);

        // Register late so a still-active legacy Onboarding Service can keep its
        // existing callback during the staged migration window. We must not
        // merge/replace the same REST route while that service is the rollback
        // transport. Once it is no longer active, a later request installs the
        // controlled 410 tombstone below.
        add_action('rest_api_init', [self::class, 'register_tombstone_route'], 999);
        add_filter('rest_request_after_callbacks', [self::class, 'after_direct_setup'], 990, 3);
    }

    public static function preserve_legacy_connection(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $legacy = get_option(self::LEGACY_CONNECTION, null);
        $backup = get_option(self::LEGACY_BACKUP, null);
        if (is_array($legacy) && !empty($legacy['repository']) && !is_array($backup)) {
            update_option(self::LEGACY_BACKUP, $legacy, false);
        }
    }

    public static function block_unsafe_reconnect(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (!self::direct_connected()) {
            return;
        }

        // The old prototype cleared the active App credentials before the new
        // setup was validated. Refuse that destructive reconnect path. A future
        // transactional reconnect may stage new credentials separately.
        $url = add_query_arg(
            ['page' => 'takka-wordpress-bridge-connect', 'takka_bridge_setup' => 'reconnect_blocked'],
            admin_url('tools.php')
        );
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Keep the old callback path as an explicit tombstone on installations that
     * no longer have a legacy Onboarding Service. During a staged production
     * migration, an already-registered legacy route is left untouched until the
     * legacy service is deliberately retired after Direct Runtime validation.
     */
    public static function register_tombstone_route(): void
    {
        $routes = rest_get_server()->get_routes();
        if (isset($routes[self::LEGACY_COMPLETE_ROUTE])) {
            return;
        }

        register_rest_route('takka-bridge-onboarding/v1', '/complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => static function () {
                return new WP_Error(
                    'wpab_central_onboarding_retired',
                    'The operator-owned onboarding callback is retired. Start the self-contained GitHub setup from WordPress Tools > WP Agent Bridge.',
                    ['status' => 410]
                );
            },
            'permission_callback' => '__return_true',
        ]);
    }

    public static function after_direct_setup($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/wp-agent-bridge-onboarding/v2/installed') {
            return $response;
        }
        if (is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        if ($rest->get_status() < 200 || $rest->get_status() >= 400) {
            return $response;
        }

        // Keep the previous local relay mapping only as local rollback metadata;
        // normal self-contained operation never reads it.
        $backup = get_option(self::LEGACY_BACKUP, null);
        $legacy = get_option(self::LEGACY_CONNECTION, null);
        if (is_array($backup) && !is_array($legacy)) {
            update_option(self::LEGACY_CONNECTION, $backup, false);
        }

        // The connection has already been authenticated and stored by the
        // onboarding endpoint, so initialize the canonical marker immediately.
        // A GitHub race must not undo the working connection: record a warning
        // and let the next authenticated admin_init retry the same idempotent sync.
        $identity = TakKa_WordPress_Bridge_Direct_Runtime_Identity::sync();
        if (is_wp_error($identity)) {
            set_transient(self::IDENTITY_WARNING, [
                'message' => $identity->get_error_message(),
                'created_at' => time(),
            ], HOUR_IN_SECONDS);
        } else {
            delete_transient(self::IDENTITY_WARNING);
        }

        return $response;
    }

    private static function direct_connected(): bool
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $app = TakKa_WordPress_Bridge_Direct_GitHub::app_config();
        return !empty($connection['installation_id'])
            && !empty($connection['repository_id'])
            && !empty($connection['repository'])
            && !empty($app['app_id'])
            && !empty($app['slug'])
            && TakKa_WordPress_Bridge_Direct_GitHub::private_key() !== ''
            && TakKa_WordPress_Bridge_Direct_GitHub::webhook_secret() !== '';
    }
}
