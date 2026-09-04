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

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'preserve_legacy_connection'], 1);
        add_action('admin_post_takka_bridge_connect_github', [self::class, 'block_unsafe_reconnect'], 1);
        add_filter('rest_request_after_callbacks', [self::class, 'restore_legacy_after_direct_setup'], 990, 3);
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

    public static function restore_legacy_after_direct_setup($response, array $handler, WP_REST_Request $request)
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

        $backup = get_option(self::LEGACY_BACKUP, null);
        $legacy = get_option(self::LEGACY_CONNECTION, null);
        if (is_array($backup) && !is_array($legacy)) {
            update_option(self::LEGACY_CONNECTION, $backup, false);
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
