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
    private const RESOLUTION_GUIDANCE_OPTION = 'takka_bridge_runtime_resolution_guidance_v1';
    private const RESOLUTION_GUIDANCE_RETRY = 'takka_bridge_runtime_resolution_guidance_retry_v1';
    private const RESOLUTION_GUIDANCE_VERSION = 1;

    public static function init(): void
    {
        add_action('admin_init', [self::class, 'preserve_legacy_connection'], 1);
        add_action('admin_init', [self::class, 'sync_resolution_guidance_if_needed'], 2);
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

    public static function sync_resolution_guidance_if_needed(): void
    {
        if (!current_user_can('manage_options') || !self::direct_connected()) {
            return;
        }
        if ((int) get_option(self::RESOLUTION_GUIDANCE_OPTION, 0) >= self::RESOLUTION_GUIDANCE_VERSION) {
            return;
        }
        if (get_transient(self::RESOLUTION_GUIDANCE_RETRY)) {
            return;
        }

        $result = self::sync_resolution_guidance();
        if (is_wp_error($result)) {
            set_transient(self::RESOLUTION_GUIDANCE_RETRY, 1, 10 * MINUTE_IN_SECONDS);
            set_transient(self::IDENTITY_WARNING, [
                'message' => $result->get_error_message(),
                'created_at' => time(),
            ], HOUR_IN_SECONDS);
            return;
        }

        update_option(self::RESOLUTION_GUIDANCE_OPTION, self::RESOLUTION_GUIDANCE_VERSION, false);
        delete_transient(self::RESOLUTION_GUIDANCE_RETRY);
        delete_transient(self::IDENTITY_WARNING);
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
            return $response;
        }

        $guidance = self::sync_resolution_guidance();
        if (is_wp_error($guidance)) {
            set_transient(self::RESOLUTION_GUIDANCE_RETRY, 1, 10 * MINUTE_IN_SECONDS);
            set_transient(self::IDENTITY_WARNING, [
                'message' => $guidance->get_error_message(),
                'created_at' => time(),
            ], HOUR_IN_SECONDS);
        } else {
            update_option(self::RESOLUTION_GUIDANCE_OPTION, self::RESOLUTION_GUIDANCE_VERSION, false);
            delete_transient(self::RESOLUTION_GUIDANCE_RETRY);
            delete_transient(self::IDENTITY_WARNING);
        }

        return $response;
    }

    /**
     * Replace ritual runtime re-discovery with a marker-first fast path.
     *
     * The base identity writer intentionally remains the single source for the
     * canonical repository/branch/site values. This post-sync pass only tightens
     * agent guidance, and is idempotent for already-updated runtimes.
     */
    private static function sync_resolution_guidance()
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = trim((string) ($connection['repository'] ?? ''));
        $branch = (string) ($connection['runtime_branch'] ?? '');
        if ($installation_id < 1
            || $repository_id < 1
            || !preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $repository)
            || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return new WP_Error('wpab_runtime_resolution_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($site_host === '') {
            $site_host = 'wordpress';
        }

        $agents = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, 'AGENTS.md');
        if (is_wp_error($agents)) {
            return $agents;
        }

        $fast_heading = '## Canonical runtime fast path';
        if (strpos($agents, $fast_heading) === false) {
            $old_intro = "Before any WordPress read/write, verify all of the following:\n"
                . "- repository: `" . $repository . "`\n"
                . "- branch: `" . $branch . "`\n"
                . "- marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
                . "- marker `status`: `canonical`\n"
                . "- marker `transport`: `direct-github-webhook`\n"
                . "- marker `site_host`: `" . $site_host . "`\n"
                . "- marker `ownership`: `user-owned`\n"
                . "- marker `operator_relay`: `false`\n\n"
                . "Do not substitute an operator-owned Organization runtime, an older personal runtime, or any repository selected only because it has a similar branch/folder layout.\n\n";

            if (strpos($agents, $old_intro) === false) {
                return new WP_Error('wpab_runtime_resolution_agents_shape', 'Canonical AGENTS.md did not match the expected identity preamble.', ['status' => 409]);
            }

            $new_intro = "## Canonical runtime fast path\n\n"
                . "If this exact repository, branch, and marker have already been verified as canonical for `" . $site_host . "` in the current task/session, reuse them immediately unless a migration signal has appeared. Do not search GitHub for other runtimes, reopen retired predecessors, enumerate branches, or reread identity files merely as ritual revalidation.\n"
                . "Migration signals are limited to: an explicit user notice of migration/reconnection; the current marker becoming non-canonical or naming another repository; a GitHub write failing because the repository/branch is missing or inaccessible; WordPress reporting a Direct Runtime mapping/connection mismatch; or the connected GitHub account/tool context actually changing.\n"
                . "If runtime resolution is required, read `wordpress-bridge/RUNTIME_CONNECTION.json` FIRST. Do not inspect `AGENTS.md`, commits, branches, command history, or neighboring repositories before the marker.\n"
                . "If a candidate marker says `status=retired` or `do_not_use=true`, stop inspecting that repository immediately. If it contains `replaced_by`, go directly to that replacement repository and read only its marker next; do not search GitHub for alternative runtimes.\n"
                . "Accept a candidate as canonical only when repository=`" . $repository . "`, branch=`" . $branch . "`, `status=canonical`, `transport=direct-github-webhook`, `site_host=" . $site_host . "`, `ownership=user-owned`, and `operator_relay=false` all agree.\n"
                . "Once canonical is established, if a usable GitHub write action such as `create_file` is already available, create `wordpress-bridge/commands/pending/<id>.json` immediately. Do not perform connector discovery or another marker verification before the command unless a migration signal occurred.\n"
                . "Old conversation history, memory, cached instructions, or a similarly named operator-owned runtime are not reasons to reopen a retired repository after the current canonical runtime has been established.\n\n";

            $agents = str_replace($old_intro, $new_intro, $agents);
        }

        $old_discovery = "Re-verify the canonical repository/branch/marker after discovery. For ordinary runtime commands, `create_file` for `wordpress-bridge/commands/pending/<id>.json` is sufficient; a local-file parameter on the GitHub connector is NOT required.\n";
        $new_discovery = "If a usable GitHub write action is already visible, do not run connector discovery. If discovery was actually required, keep the already-established canonical runtime and do not re-verify its marker afterward unless the connected GitHub account/tool context changed. For ordinary runtime commands, `create_file` for `wordpress-bridge/commands/pending/<id>.json` is sufficient; a local-file parameter on the GitHub connector is NOT required.\n";
        if (strpos($agents, $new_discovery) === false) {
            if (strpos($agents, $old_discovery) === false) {
                return new WP_Error('wpab_runtime_resolution_discovery_shape', 'Canonical AGENTS.md did not contain the expected connector-discovery guidance.', ['status' => 409]);
            }
            $agents = str_replace($old_discovery, $new_discovery, $agents);
        }

        $agents_write = self::put_guidance_if_changed($token, $repository, $branch, 'AGENTS.md', $agents);
        if (is_wp_error($agents_write)) {
            return $agents_write;
        }

        $runtime = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, 'wordpress-bridge/WEBHOOK_RUNTIME.md');
        if (is_wp_error($runtime)) {
            return $runtime;
        }
        $runtime_fast = "Resolution fast path: reuse this exact canonical repository/branch during the current task/session unless a migration signal appears. If verification is required, read `wordpress-bridge/RUNTIME_CONNECTION.json` first and only; a retired marker must redirect through `replaced_by` without further repository inspection. If `create_file` is already available, write the pending command immediately instead of rediscovering GitHub tools or runtimes.\n";
        if (strpos($runtime, $runtime_fast) === false) {
            $anchor = "Marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n";
            if (strpos($runtime, $anchor) === false) {
                return new WP_Error('wpab_runtime_resolution_runtime_shape', 'WEBHOOK_RUNTIME.md did not contain the expected marker line.', ['status' => 409]);
            }
            $runtime = str_replace($anchor, $anchor . $runtime_fast, $runtime);
        }

        $runtime_write = self::put_guidance_if_changed($token, $repository, $branch, 'wordpress-bridge/WEBHOOK_RUNTIME.md', $runtime);
        if (is_wp_error($runtime_write)) {
            return $runtime_write;
        }

        return [
            'ok' => true,
            'version' => self::RESOLUTION_GUIDANCE_VERSION,
            'agents' => $agents_write,
            'runtime' => $runtime_write,
        ];
    }

    private static function put_guidance_if_changed(string $token, string $repository, string $branch, string $path, string $content)
    {
        $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
        if (is_wp_error($current)) {
            return $current;
        }
        if (hash_equals(hash('sha256', $current), hash('sha256', $content))) {
            return ['changed' => false, 'sha256' => hash('sha256', $content)];
        }

        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $token,
            $repository,
            $branch,
            $path,
            $content,
            'WP Agent Bridge: sync canonical runtime fast-resolution guidance'
        );
        if (is_wp_error($written)) {
            return $written;
        }
        return ['changed' => true, 'sha256' => hash('sha256', $content)];
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
