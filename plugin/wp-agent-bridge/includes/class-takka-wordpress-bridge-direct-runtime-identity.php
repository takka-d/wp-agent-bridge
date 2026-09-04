<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Canonical identity files for the user's own Direct Runtime repository.
 */
final class TakKa_WordPress_Bridge_Direct_Runtime_Identity
{
    public static function sync()
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
            return new WP_Error('wpab_direct_identity_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }

        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token($installation_id, $repository_id);
        if (is_wp_error($token)) {
            return $token;
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
            'ownership' => 'user-owned',
            'operator_relay' => false,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($marker)) {
            return new WP_Error('wpab_direct_identity_marker', 'Could not encode runtime identity marker.');
        }
        $marker .= "\n";

        $agents = "# WP Agent Bridge runtime — CANONICAL\n\n"
            . "This is the user-owned canonical WP Agent Bridge runtime for `" . $site_host . "`.\n\n"
            . "Before any WordPress read/write, verify all of the following:\n"
            . "- repository: `" . $repository . "`\n"
            . "- branch: `" . $branch . "`\n"
            . "- marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "- marker `status`: `canonical`\n"
            . "- marker `transport`: `direct-github-webhook`\n"
            . "- marker `site_host`: `" . $site_host . "`\n"
            . "- marker `ownership`: `user-owned`\n"
            . "- marker `operator_relay`: `false`\n\n"
            . "Do not substitute an operator-owned Organization runtime, an older personal runtime, or any repository selected only because it has a similar branch/folder layout.\n\n"
            . "Create commands at `wordpress-bridge/commands/pending/<id>.json` and read results from `wordpress-bridge/results/<id>.json`.\n"
            . "Use unique `id` and `request_id` values. Respect preview/confirm/SHA/plan/impact guards returned by the Bridge.\n"
            . "When GitHub Git Data operations are available, stage related input files as blobs first and publish them to the runtime branch in one tree/commit/ref update instead of moving the branch once per file.\n"
            . "After a command result appears, do not start the next branch update until the matching pending command has been removed or the matching completed command is visible. This avoids racing WordPress result/completed cleanup writes.\n\n"
            . "## Media uploads\n\n"
            . "Do not place a large image Base64 string in one command JSON.\n"
            . "Preferred self-contained file transport:\n"
            . "1. Base64-encode the original binary and compute `expected_bytes` plus SHA-256 before touching the runtime branch.\n"
            . "2. Split the Base64 text into one or more payload files under `wordpress-bridge/media/pending/<id>.b64` when needed.\n"
            . "3. Create the payload blobs without moving the runtime branch, verify the returned blob SHAs, and prepare the small upload command.\n"
            . "4. Publish every payload file plus `wordpress-bridge/commands/pending/<id>.json` together in one Git tree/commit/ref update whenever the connector supports Git Data operations.\n"
            . "5. The upload command calls `/wp-agent-bridge-runtime/v1/media-upload` with `data_path` or `data_paths`, `filename`, `expected_bytes`, and `expected_sha256`.\n"
            . "6. WordPress reconstructs the binary, verifies byte count and SHA-256, creates the attachment, and removes all temporary `.b64` source files in one Git tree cleanup commit with bounded optimistic retry.\n"
            . "7. Treat a visible result as complete only after the matching pending command has disappeared or the completed command is visible before staging the next upload.\n"
            . "The Bridge also exposes `/wp-agent-bridge-media/v1/upload-chunk` as a bounded authenticated fallback.\n\n"
            . "Normal transport: ChatGPT -> this user-owned private repository -> site-specific GitHub App signed push Webhook -> this WordPress -> this repository -> ChatGPT.\n"
            . "Normal operation does not use an operator-owned relay, operator-owned runtime repository, GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.\n";

        $runtime = "# Runtime\n\n"
            . "Ownership: user-owned private GitHub repository.\n"
            . "Transport: site-specific GitHub App + signed push Webhook direct to the user's WordPress.\n"
            . "Repository: `" . $repository . "`\n"
            . "Branch: `" . $branch . "`\n"
            . "Marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "Pending commands: `wordpress-bridge/commands/pending/<id>.json`\n"
            . "Completed commands: `wordpress-bridge/commands/completed/<id>.json`\n"
            . "Results: `wordpress-bridge/results/<id>.json`\n"
            . "Media payloads: `wordpress-bridge/media/pending/<id>.b64` -> `/wp-agent-bridge-runtime/v1/media-upload`\n"
            . "For multi-file media input, publish payload files plus the upload command in one Git tree/commit/ref update when possible.\n"
            . "Successful media payload cleanup is performed as one Git tree commit with bounded retry if the runtime branch moved.\n"
            . "Operator-owned relay: none.\n"
            . "Normal WordPress commands do not use GitHub Actions, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.\n";

        $files = [
            'AGENTS.md' => $agents,
            'wordpress-bridge/RUNTIME_CONNECTION.json' => $marker,
            'wordpress-bridge/WEBHOOK_RUNTIME.md' => $runtime,
            'wordpress-bridge/media/pending/.gitkeep' => '',
        ];

        $written = [];
        foreach ($files as $path => $content) {
            $result = self::sync_file($token, $repository, $branch, $path, $content);
            if (is_wp_error($result)) {
                return $result;
            }
            $written[$path] = $result;
        }

        return [
            'ok' => true,
            'repository' => $repository,
            'runtime_branch' => $branch,
            'site_host' => $site_host,
            'files' => $written,
        ];
    }

    private static function sync_file(string $token, string $repository, string $branch, string $path, string $content)
    {
        if ($content === '') {
            $meta = TakKa_WordPress_Bridge_Direct_GitHub::get_content_metadata($token, $repository, $branch, $path);
            if (!is_wp_error($meta)) {
                $encoded = array_key_exists('content', $meta) && is_string($meta['content'])
                    ? preg_replace('/\s+/', '', $meta['content'])
                    : null;
                $size = array_key_exists('size', $meta) ? (int) $meta['size'] : null;
                if ($size === 0 || $encoded === '') {
                    return ['changed' => false, 'sha256' => hash('sha256', '')];
                }
            } elseif (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($meta) !== 404) {
                return $meta;
            }
        } else {
            $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
            if (!is_wp_error($current)) {
                if (hash_equals(hash('sha256', $current), hash('sha256', $content))) {
                    return ['changed' => false, 'sha256' => hash('sha256', $content)];
                }
            } elseif (TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($current) !== 404) {
                return $current;
            }
        }

        $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
            $token,
            $repository,
            $branch,
            $path,
            $content,
            'WP Agent Bridge: sync canonical self-contained runtime identity'
        );
        if (is_wp_error($written)) {
            return $written;
        }
        return ['changed' => true, 'sha256' => hash('sha256', $content)];
    }
}
