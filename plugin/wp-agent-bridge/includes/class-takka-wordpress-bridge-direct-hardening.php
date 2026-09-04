<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-contained Direct Runtime hardening.
 *
 * - Hides/disables the legacy manual Bridge Key / GitHub Actions admin path.
 * - Keeps the connected user-owned runtime repository self-identifying.
 */
final class TakKa_WordPress_Bridge_Direct_Hardening
{
    public static function init(): void
    {
        remove_action('admin_post_takka_bridge_generate_key', [TakKa_WordPress_Bridge::class, 'handle_generate_key']);
        add_action('admin_menu', [self::class, 'remove_legacy_menu'], 999);
        add_action('admin_init', [self::class, 'ensure_runtime_identity'], 20);
    }

    public static function remove_legacy_menu(): void
    {
        remove_submenu_page('tools.php', 'takka-wordpress-bridge');
    }

    public static function ensure_runtime_identity(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        TakKa_WordPress_Bridge_Direct_Runtime_Identity::sync();
    }
}
