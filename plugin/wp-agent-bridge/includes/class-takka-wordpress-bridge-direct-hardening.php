<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Direct Runtime public-build hardening.
 *
 * - Hides/disables the legacy manual Bridge Key / GitHub Actions admin path.
 * - Keeps the connected runtime repository self-identifying so ChatGPT does
 *   not accidentally substitute an older repository with similar folders.
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

        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        $installation_id = (int) ($connection['installation_id'] ?? 0);
        $repository_id = (int) ($connection['repository_id'] ?? 0);
        $repository = trim((string) ($connection['repository'] ?? ''));
        $branch = (string) ($connection['runtime_branch'] ?? '');
        if ($installation_id < 1 || $repository_id < 1 || $repository === '' || $branch !== TakKa_WordPress_Bridge_Direct_Runtime::RUNTIME_BRANCH) {
            return;
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return;
        }

        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($site_host === '') {
            $site_host = 'wordpress';
        }

        $marker = wp_json_encode([
            'schema' => 1,
            'status' => 'canonical',
            'transport' => 'direct-github-webhook',
            'repository' => $repository,
            'runtime_branch' => $branch,
            'site_host' => $site_host,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($marker)) {
            return;
        }
        $marker .= "\n";

        $agents = "# WP Agent Bridge runtime — CANONICAL\n\n"
            . "This repository is the canonical WP Agent Bridge runtime for `" . $site_host . "`.\n\n"
            . "Before any WordPress read/write, verify all of the following:\n"
            . "- repository: `" . $repository . "`\n"
            . "- branch: `" . $branch . "`\n"
            . "- marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "- marker `status`: `canonical`\n"
            . "- marker `transport`: `direct-github-webhook`\n"
            . "- marker `site_host`: `" . $site_host . "`\n\n"
            . "Do not choose a repository merely because it contains a `wp-agent-bridge-runtime` branch or a `wordpress-bridge/` directory. "
            . "Do not substitute another repository with similar files.\n\n"
            . "Create commands at `wordpress-bridge/commands/pending/<id>.json` and read results from `wordpress-bridge/results/<id>.json`.\n"
            . "Use unique `id` and `request_id` values. Respect preview/confirm/SHA/plan/impact guards returned by the Bridge.\n\n"
            . "## Media uploads\n\n"
            . "For normal Direct Runtime media uploads, do NOT embed a large `data_b64` string inside the command JSON.\n"
            . "1. Encode the original binary as Base64 and write it to `wordpress-bridge/media/pending/<id>.b64`.\n"
            . "2. Compute `expected_bytes` and SHA-256 from the original binary.\n"
            . "3. Submit a small REST command to `/wp-agent-bridge-runtime/v1/media-upload` with `data_path`, `filename`, `expected_bytes`, and `expected_sha256`.\n"
            . "4. The plugin verifies integrity, uploads to the WordPress media library, and deletes the temporary `.b64` payload after success.\n"
            . "Whitespace, Data-URL prefixes, URL-safe Base64, and omitted Base64 padding are normalized by the plugin.\n\n"
            . "Normal transport: ChatGPT -> this private repository -> site-specific GitHub App signed push Webhook -> connected WordPress -> this repository -> ChatGPT.\n"
            . "WPVibe is not a required user dependency.\n";

        $runtime = "# Runtime\n\n"
            . "Transport: site-specific GitHub App + signed push Webhook direct to the user's WordPress.\n"
            . "Repository: `" . $repository . "`\n"
            . "Branch: `" . $branch . "`\n"
            . "Marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "Pending commands: `wordpress-bridge/commands/pending/<id>.json`\n"
            . "Results: `wordpress-bridge/results/<id>.json`\n"
            . "Media payloads: `wordpress-bridge/media/pending/<id>.b64` -> `/wp-agent-bridge-runtime/v1/media-upload`\n"
            . "Normal WordPress commands do not use GitHub Actions, WPVibe, or an operator-owned relay.\n";

        self::sync_file($token, $repository, $branch, 'AGENTS.md', $agents);
        self::sync_file($token, $repository, $branch, 'wordpress-bridge/RUNTIME_CONNECTION.json', $marker);
        self::sync_file($token, $repository, $branch, 'wordpress-bridge/WEBHOOK_RUNTIME.md', $runtime);
    }

    private static function sync_file(string $token, string $repository, string $branch, string $path, string $content): void
    {
        $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
        if (is_string($current) && hash_equals(hash('sha256', $current), hash('sha256', $content))) {
            return;
        }
        if (is_wp_error($current)) {
            $data = $current->get_error_data();
            $status = is_array($data) ? (int) ($data['status'] ?? 0) : 0;
            if ($status !== 404) {
                return;
            }
        }
        TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $token,
            $repository,
            $branch,
            $path,
            $content,
            'WP Agent Bridge: sync canonical runtime identity'
        );
    }
}
