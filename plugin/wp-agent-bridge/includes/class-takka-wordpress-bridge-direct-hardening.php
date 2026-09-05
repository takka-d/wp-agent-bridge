<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Self-contained Direct Runtime hardening.
 *
 * - Hides/disables the legacy manual Bridge Key / GitHub Actions admin path.
 * - Keeps the connected user-owned runtime repository self-identifying.
 * - Serializes authenticated runtime push handling so recovery cannot race an
 *   already-running primary command and persist idempotency-in-progress as a
 *   terminal result.
 */
final class TakKa_WordPress_Bridge_Direct_Hardening
{
    private const PRIMARY_LOCK_OPTION = 'takka_bridge_direct_primary_lock_v3';
    private const PRIMARY_LOCK_TTL = 90;
    private const PRIMARY_LOCK_WAIT_SECONDS = 30;

    public static function init(): void
    {
        remove_action('admin_post_takka_bridge_generate_key', [TakKa_WordPress_Bridge::class, 'handle_generate_key']);
        add_action('admin_menu', [self::class, 'remove_legacy_menu'], 999);
        add_action('admin_init', [self::class, 'ensure_runtime_identity'], 20);
        add_action('rest_api_init', [self::class, 'replace_runtime_webhook'], 40);
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

    public static function replace_runtime_webhook(): void
    {
        register_rest_route(
            TakKa_WordPress_Bridge_Direct_Runtime::NAMESPACE,
            '/github-webhook',
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'serialized_webhook'],
                'permission_callback' => '__return_true',
            ],
            true
        );
    }

    public static function serialized_webhook(WP_REST_Request $request)
    {
        $raw = (string) $request->get_body();
        $signature = (string) $request->get_header('x-hub-signature-256');
        if (!TakKa_WordPress_Bridge_Direct_GitHub::verify_webhook($raw, $signature)) {
            return new WP_Error('takka_direct_webhook_signature', 'Invalid GitHub webhook signature.', ['status' => 401]);
        }

        $event = strtolower(trim((string) $request->get_header('x-github-event')));
        if ($event !== 'push') {
            return TakKa_WordPress_Bridge_Direct_Runtime_V2::webhook($request);
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)
            || ($payload['ref'] ?? '') !== 'refs/heads/' . TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH
            || !empty($payload['deleted'])) {
            return TakKa_WordPress_Bridge_Direct_Runtime_V2::webhook($request);
        }

        $lock = self::acquire_primary_lock();
        if ($lock === null) {
            return new WP_Error(
                'wpab_direct_runtime_busy',
                'Direct Runtime is still processing another authenticated push.',
                ['status' => 503, 'retryable' => true]
            );
        }

        try {
            return TakKa_WordPress_Bridge_Direct_Runtime_V2::webhook($request);
        } finally {
            self::release_primary_lock($lock);
        }
    }

    private static function acquire_primary_lock(): ?string
    {
        $token = wp_generate_uuid4();
        $deadline = microtime(true) + self::PRIMARY_LOCK_WAIT_SECONDS;

        do {
            $now = time();
            $existing = get_option(self::PRIMARY_LOCK_OPTION, null);
            if (is_array($existing)) {
                $created_at = isset($existing['created_at']) ? (int) $existing['created_at'] : 0;
                if ($created_at < 1 || ($now - $created_at) >= self::PRIMARY_LOCK_TTL) {
                    delete_option(self::PRIMARY_LOCK_OPTION);
                    $existing = null;
                }
            }

            if ($existing === null
                && add_option(
                    self::PRIMARY_LOCK_OPTION,
                    ['token' => $token, 'created_at' => $now],
                    '',
                    false
                )) {
                return $token;
            }

            usleep(100000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private static function release_primary_lock(string $token): void
    {
        $existing = get_option(self::PRIMARY_LOCK_OPTION, null);
        if (is_array($existing)
            && isset($existing['token'])
            && is_string($existing['token'])
            && hash_equals($existing['token'], $token)) {
            delete_option(self::PRIMARY_LOCK_OPTION);
        }
    }
}
