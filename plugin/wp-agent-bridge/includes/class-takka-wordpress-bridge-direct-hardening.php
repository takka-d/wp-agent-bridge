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
 * - Adds a bounded WP-Cron reconciliation fallback so a missed GitHub push
 *   webhook cannot strand valid pending commands indefinitely.
 */
final class TakKa_WordPress_Bridge_Direct_Hardening
{
    private const PRIMARY_LOCK_OPTION = 'takka_bridge_direct_primary_lock_v3';
    private const PRIMARY_LOCK_TTL = 90;
    private const PRIMARY_LOCK_WAIT_SECONDS = 30;

    private const RECONCILE_CRON_HOOK = 'takka_bridge_direct_reconcile_cron_v4';
    private const RECONCILE_CRON_SCHEDULE = 'takka_bridge_direct_every_two_minutes';
    private const RECONCILE_CRON_INTERVAL = 120;
    private const RECONCILE_LAST_OPTION = 'takka_bridge_direct_reconcile_last_v4';

    public static function init(): void
    {
        remove_action('admin_post_takka_bridge_generate_key', [TakKa_WordPress_Bridge::class, 'handle_generate_key']);
        add_action('admin_menu', [self::class, 'remove_legacy_menu'], 999);
        add_action('admin_init', [self::class, 'ensure_runtime_identity'], 20);
        add_action('rest_api_init', [self::class, 'replace_runtime_webhook'], 40);

        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('init', [self::class, 'ensure_reconcile_schedule'], 45);
        add_action(self::RECONCILE_CRON_HOOK, [self::class, 'scheduled_reconcile']);
        add_filter('rest_request_after_callbacks', [self::class, 'annotate_health'], 620, 3);
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

    public static function cron_schedules(array $schedules): array
    {
        $schedules[self::RECONCILE_CRON_SCHEDULE] = [
            'interval' => self::RECONCILE_CRON_INTERVAL,
            'display' => 'WP Agent Bridge Direct Runtime recovery (2 minutes)',
        ];
        return $schedules;
    }

    public static function ensure_reconcile_schedule(): void
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(self::RECONCILE_CRON_HOOK);
            }
            return;
        }

        if (!wp_next_scheduled(self::RECONCILE_CRON_HOOK)) {
            wp_schedule_event(
                time() + 30,
                self::RECONCILE_CRON_SCHEDULE,
                self::RECONCILE_CRON_HOOK
            );
        }
    }

    /**
     * Fallback for GitHub deliveries that never reach the webhook endpoint.
     *
     * This does not create a second command transport. It synthesizes the same
     * authenticated runtime push inside WordPress, causing Direct Runtime V2 to
     * scan the canonical pending queue and reuse the exact same request IDs,
     * idempotency guards, recovery age limits and atomic bookkeeping.
     */
    public static function scheduled_reconcile(): void
    {
        $started = microtime(true);
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            self::record_reconcile([
                'ok' => false,
                'code' => 'wpab_direct_reconcile_connection',
                'message' => 'Direct Runtime connection is incomplete.',
                'duration_ms' => self::elapsed_ms($started),
            ]);
            return;
        }

        $installation_id = (int) $connection['installation_id'];
        $repository_id = (int) $connection['repository_id'];
        $repository = (string) $connection['repository'];
        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            self::record_reconcile_error($token, $started);
            return;
        }

        $repo = TakKa_WordPress_Bridge_Direct_GitHub::github_api(
            'GET',
            '/repos/' . $repository,
            $token
        );
        if (is_wp_error($repo)) {
            self::record_reconcile_error($repo, $started);
            return;
        }
        $repo_data = isset($repo['data']) && is_array($repo['data']) ? $repo['data'] : [];
        if ((int) ($repo_data['id'] ?? 0) !== $repository_id
            || empty($repo_data['private'])
            || !hash_equals($repository, (string) ($repo_data['full_name'] ?? ''))) {
            self::record_reconcile([
                'ok' => false,
                'code' => 'wpab_direct_reconcile_mapping',
                'message' => 'Connected repository metadata no longer matches the Direct Runtime mapping.',
                'duration_ms' => self::elapsed_ms($started),
            ]);
            return;
        }

        $after = TakKa_WordPress_Bridge_Direct_GitHub_Recovery::branch_sha(
            $token,
            $repository,
            TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH
        );
        if (is_wp_error($after)) {
            self::record_reconcile_error($after, $started);
            return;
        }

        $payload = [
            'ref' => 'refs/heads/' . TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH,
            'deleted' => false,
            'after' => $after,
            'repository' => [
                'id' => $repository_id,
                'full_name' => $repository,
                'private' => true,
            ],
            'installation' => ['id' => $installation_id],
            // The primary handler has no new path to execute. Direct Runtime V2
            // performs the durable pending-directory scan immediately after it.
            'commits' => [],
        ];
        $raw = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = TakKa_WordPress_Bridge_Direct_GitHub::webhook_secret();
        if (!is_string($raw) || strlen($secret) < 20) {
            self::record_reconcile([
                'ok' => false,
                'code' => 'wpab_direct_reconcile_payload',
                'message' => 'Could not construct the authenticated scheduled recovery request.',
                'duration_ms' => self::elapsed_ms($started),
            ]);
            return;
        }

        $request = new WP_REST_Request('POST', TakKa_WordPress_Bridge_Direct_Runtime::WEBHOOK_ROUTE);
        $request->set_header('x-github-event', 'push');
        $request->set_header('x-hub-signature-256', 'sha256=' . hash_hmac('sha256', $raw, $secret));
        $request->set_header('content-type', 'application/json');
        $request->set_body($raw);

        $response = self::serialized_webhook($request);
        if (is_wp_error($response)) {
            self::record_reconcile_error($response, $started);
            return;
        }

        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        $recovery = is_array($data) && isset($data['pending_recovery']) && is_array($data['pending_recovery'])
            ? $data['pending_recovery']
            : [];
        self::record_reconcile([
            'ok' => empty($recovery) || !array_key_exists('ok', $recovery) || !empty($recovery['ok']),
            'processed' => isset($recovery['processed']) ? (int) $recovery['processed'] : 0,
            'deferred' => isset($recovery['deferred']) ? (int) $recovery['deferred'] : 0,
            'busy' => !empty($recovery['busy']),
            'duration_ms' => self::elapsed_ms($started),
        ]);
    }

    public static function annotate_health($response, array $handler, WP_REST_Request $request)
    {
        if ($request->get_route() !== '/takka-bridge/v1/health' || is_wp_error($response)) {
            return $response;
        }
        $rest = rest_ensure_response($response);
        $data = $rest->get_data();
        if (!is_array($data)) {
            return $response;
        }
        $features = isset($data['features']) && is_array($data['features']) ? $data['features'] : [];
        if (!in_array('scheduled_pending_recovery', $features, true)) {
            $features[] = 'scheduled_pending_recovery';
        }
        $data['features'] = $features;
        $data['scheduled_pending_recovery'] = [
            'primary' => 'github-push-webhook',
            'fallback' => 'wp-cron-pending-reconcile',
            'same_runtime_transport' => true,
            'interval_seconds' => self::RECONCILE_CRON_INTERVAL,
            'next_scheduled_at' => wp_next_scheduled(self::RECONCILE_CRON_HOOK) ?: null,
            'last_run' => get_option(self::RECONCILE_LAST_OPTION, null),
            'wp_cron_disabled' => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        ];
        $rest->set_data($data);
        return $rest;
    }

    private static function valid_connection(array $connection): bool
    {
        return (int) ($connection['installation_id'] ?? 0) > 0
            && (int) ($connection['repository_id'] ?? 0) > 0
            && preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) ($connection['repository'] ?? ''))
            && (string) ($connection['runtime_branch'] ?? '') === TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH;
    }

    private static function record_reconcile(array $result): void
    {
        $result['ran_at_gmt'] = gmdate('c');
        update_option(self::RECONCILE_LAST_OPTION, $result, false);
    }

    private static function record_reconcile_error(WP_Error $error, float $started): void
    {
        $data = $error->get_error_data();
        self::record_reconcile([
            'ok' => false,
            'code' => $error->get_error_code(),
            'message' => $error->get_error_message(),
            'status' => is_array($data) && isset($data['status']) ? (int) $data['status'] : null,
            'duration_ms' => self::elapsed_ms($started),
        ]);
    }

    private static function elapsed_ms(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
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
