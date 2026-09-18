<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mirrors the canonical read-only runtime bootstrap contract onto the
 * repository default branch and provides root aliases on the runtime branch.
 *
 * Some GitHub clients omit ref/branch on their first read, which makes GitHub
 * read the repository default branch. The executable runtime intentionally
 * remains on wp-agent-bridge-runtime; these mirrors only make discovery
 * deterministic. Mutations must still target the canonical runtime branch.
 */
final class TakKa_WordPress_Bridge_Runtime_Bootstrap
{
    private const OPTION_SYNCED_SIGNATURE = 'takka_bridge_runtime_bootstrap_synced_signature';
    private const TRANSIENT_LOCK = 'takka_bridge_runtime_bootstrap_sync_lock';
    private const SETTLE_HOOK = 'wpab_runtime_bootstrap_settle_sync';

    public static function init(): void
    {
        // Base generators run at 30/31, concurrency enrichment at 33 and
        // reliability enrichment at 34. Compose the final authoritative
        // contract after all of them so independent sync layers cannot leave
        // an older/stale AGENTS or capability file behind.
        add_action('init', [self::class, 'maybe_sync'], 40);
        add_action(self::SETTLE_HOOK, [self::class, 'settle_sync']);
    }

    public static function maybe_sync(): void
    {
        $version = self::bridge_version();
        if ($version === '' || get_transient(self::TRANSIENT_LOCK)) {
            return;
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return;
        }

        $signature = hash('sha256', implode("\n", [
            $version,
            (string) $connection['repository'],
            (string) $connection['runtime_branch'],
            strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
        ]));
        if ((string) get_option(self::OPTION_SYNCED_SIGNATURE, '') === $signature) {
            return;
        }

        set_transient(self::TRANSIENT_LOCK, '1', 300);
        $result = self::sync();
        if (!is_wp_error($result)) {
            update_option(self::OPTION_SYNCED_SIGNATURE, $signature, false);
            delete_transient(self::TRANSIENT_LOCK);

            // A PHP request that loaded the previous plugin version before a
            // self-update can finish later and publish stale generated files.
            // Run one unconditional settle pass after that request window.
            if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event')
                && !wp_next_scheduled(self::SETTLE_HOOK)) {
                wp_schedule_single_event(time() + 60, self::SETTLE_HOOK);
            }
        }
    }

    public static function settle_sync(): void
    {
        if (get_transient(self::TRANSIENT_LOCK)) {
            return;
        }
        set_transient(self::TRANSIENT_LOCK, '1', 300);
        $result = self::sync();
        delete_transient(self::TRANSIENT_LOCK);
        if (is_wp_error($result)) {
            return;
        }

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $version = self::bridge_version();
        if ($version !== '' && self::valid_connection($connection)) {
            $signature = hash('sha256', implode("\n", [
                $version,
                (string) $connection['repository'],
                (string) $connection['runtime_branch'],
                strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST)),
            ]));
            update_option(self::OPTION_SYNCED_SIGNATURE, $signature, false);
        }
    }

    public static function sync()
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_runtime_bootstrap_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $installation_id = (int) $connection['installation_id'];
        $repository_id = (int) $connection['repository_id'];
        $repository = (string) $connection['repository'];
        $runtime_branch = (string) $connection['runtime_branch'];

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
        }

        $repo = TakKa_WordPress_Bridge_Direct_GitHub::github_api('GET', '/repos/' . $repository, $token);
        if (is_wp_error($repo)) {
            return $repo;
        }
        $repo_data = isset($repo['data']) && is_array($repo['data']) ? $repo['data'] : [];
        $default_branch = isset($repo_data['default_branch']) && is_string($repo_data['default_branch'])
            ? trim($repo_data['default_branch'])
            : '';
        if ($default_branch === '') {
            return new WP_Error('wpab_runtime_bootstrap_default_branch', 'GitHub did not return the repository default branch.', ['status' => 502]);
        }

        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($site_host === '') {
            $site_host = 'wordpress';
        }
        $version = self::bridge_version();
        if ($version === '') {
            return new WP_Error('wpab_runtime_bootstrap_version', 'Could not determine the installed Bridge version.', ['status' => 500]);
        }

        // Do not trust whatever an earlier/older request most recently wrote to
        // generated files. Recompose them from the currently loaded code.
        $agents = TakKa_WordPress_Bridge_Runtime_Guidance::agents(
            $repository,
            $runtime_branch,
            $site_host,
            $version
        );
        if (class_exists('TakKa_WordPress_Bridge_Post_Concurrency_Runtime_Guidance')) {
            $agents = TakKa_WordPress_Bridge_Post_Concurrency_Runtime_Guidance::enrich_agents($agents);
        }
        if (class_exists('TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance')) {
            $agents = TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance::enrich_agents($agents);
        }

        $catalog = TakKa_WordPress_Bridge_Runtime_Capabilities::catalog(
            $version,
            $repository,
            $runtime_branch,
            $site_host
        );
        if (class_exists('TakKa_WordPress_Bridge_Post_Concurrency_Runtime_Guidance')) {
            $catalog = TakKa_WordPress_Bridge_Post_Concurrency_Runtime_Guidance::enrich_capabilities($catalog);
        }
        if (class_exists('TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance')) {
            $catalog = TakKa_WordPress_Bridge_Post_Reliability_Runtime_Guidance::enrich_capabilities($catalog);
        }
        $capabilities = wp_json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($capabilities)) {
            return new WP_Error('wpab_runtime_bootstrap_capabilities_json', 'Could not encode final runtime capabilities.', ['status' => 500]);
        }
        $capabilities .= "\n";

        $marker = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file(
            $token,
            $repository,
            $runtime_branch,
            'wordpress-bridge/RUNTIME_CONNECTION.json'
        );
        if (is_wp_error($marker)) {
            return $marker;
        }
        $marker_data = json_decode($marker, true);
        if (!is_array($marker_data)
            || ($marker_data['status'] ?? '') !== 'canonical'
            || ($marker_data['transport'] ?? '') !== 'direct-github-webhook'
            || ($marker_data['repository'] ?? '') !== $repository
            || ($marker_data['runtime_branch'] ?? '') !== $runtime_branch
            || ($marker_data['site_host'] ?? '') !== $site_host
            || ($marker_data['ownership'] ?? '') !== 'user-owned'
            || ($marker_data['operator_relay'] ?? null) !== false) {
            return new WP_Error('wpab_runtime_bootstrap_marker', 'Canonical runtime marker is invalid or does not match this connection.', ['status' => 409]);
        }

        $written = [];

        // Repair the canonical generated contract first. This makes the final
        // composition self-healing even if an old in-flight request published
        // an earlier generator layer after a self-update.
        foreach ([
            'AGENTS.md' => $agents,
            'wordpress-bridge/RUNTIME_CAPABILITIES.json' => $capabilities,
            'RUNTIME_CONNECTION.json' => $marker,
            'RUNTIME_CAPABILITIES.json' => $capabilities,
        ] as $path => $value) {
            $result = self::sync_file($token, $repository, $runtime_branch, $path, $value);
            if (is_wp_error($result)) {
                return $result;
            }
            $written[$runtime_branch . ':' . $path] = $result;
        }

        if ($default_branch !== $runtime_branch) {
            // A first read with no ref uses the default branch. Mirror only
            // read-only bootstrap material there; never command/result data.
            $default_files = [
                'README.md' => self::bootstrap_readme($repository, $runtime_branch),
                'AGENTS.md' => $agents,
                'RUNTIME_CONNECTION.json' => $marker,
                'RUNTIME_CAPABILITIES.json' => $capabilities,
                'wordpress-bridge/RUNTIME_CONNECTION.json' => $marker,
                'wordpress-bridge/RUNTIME_CAPABILITIES.json' => $capabilities,
            ];
            foreach ($default_files as $path => $value) {
                $result = self::sync_file($token, $repository, $default_branch, $path, $value);
                if (is_wp_error($result)) {
                    return $result;
                }
                $written[$default_branch . ':' . $path] = $result;
            }
        }

        return [
            'ok' => true,
            'repository' => $repository,
            'runtime_branch' => $runtime_branch,
            'default_branch' => $default_branch,
            'mutations_require_runtime_branch' => true,
            'files' => $written,
        ];
    }

    public static function bootstrap_readme(string $repository, string $runtime_branch): string
    {
        return "# WP Agent Bridge runtime bootstrap\n\n"
            . "This repository uses a dedicated runtime branch. The default branch is only a read-only bootstrap landing page.\n\n"
            . "Repository: `{$repository}`  \n"
            . "Canonical runtime branch: `{$runtime_branch}`\n\n"
            . "For runtime reads and **all writes**, explicitly use branch/ref `{$runtime_branch}`. GitHub file tools often read the default branch when ref is omitted. A 404 from an omitted ref or a wrong path is not proof that this private repository is inaccessible. The repository and runtime branch above are sufficient for normal command publication; do not require a RUNTIME_CONNECTION.json read before creating a user-requested pending command.\n\n"
            . "Canonical marker: `wordpress-bridge/RUNTIME_CONNECTION.json`  \n"
            . "Capabilities: `wordpress-bridge/RUNTIME_CAPABILITIES.json`  \n"
            . "Commands: `wordpress-bridge/commands/pending/<id>.json`  \n"
            . "Results: `wordpress-bridge/results/<id>.json`\n\n"
            . "Root-level RUNTIME_CONNECTION.json and RUNTIME_CAPABILITIES.json are bootstrap aliases only. Do not write commands to the default branch.\n";
    }

    private static function sync_file(
        string $token,
        string $repository,
        string $branch,
        string $path,
        string $content
    ) {
        $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
        if (!is_wp_error($current)) {
            if (hash_equals(hash('sha256', $current), hash('sha256', $content))) {
                return ['changed' => false, 'sha256' => hash('sha256', $content)];
            }
        } elseif (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($current) !== 404) {
            return $current;
        }

        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $token,
            $repository,
            $branch,
            $path,
            $content,
            'WP Agent Bridge: sync runtime bootstrap mirror'
        );
        if (is_wp_error($written)) {
            return $written;
        }
        return ['changed' => true, 'sha256' => hash('sha256', $content)];
    }

    private static function bridge_version(): string
    {
        if (!function_exists('get_file_data')) return '';
        $data = get_file_data(dirname(__DIR__) . '/takka-wordpress-bridge.php', ['Version' => 'Version'], 'plugin');
        return isset($data['Version']) ? trim((string) $data['Version']) : '';
    }

    private static function valid_connection(array $connection): bool
    {
        return (int) ($connection['installation_id'] ?? 0) > 0
            && (int) ($connection['repository_id'] ?? 0) > 0
            && preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) ($connection['repository'] ?? '')) === 1
            && (string) ($connection['runtime_branch'] ?? '') === TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH;
    }
}
