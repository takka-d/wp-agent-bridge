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
            . "Do not place a large image Base64 string in one command JSON or one large runtime payload file.\n"
            . "Preferred self-contained file transport:\n"
            . "1. Compute `expected_bytes` and SHA-256 from the complete original binary before touching the runtime branch.\n"
            . "2. Split the ORIGINAL BINARY into small ordered chunks, then Base64-encode each chunk independently. Do not split one already-encoded Base64 string into arbitrary pieces.\n"
            . "3. Keep each `.b64` payload at or below 8,000 Base64 characters. Store ordered files under `wordpress-bridge/media/pending/<id>-partNN.b64`.\n"
            . "4. Create all payload blobs without moving the runtime branch. For every blob, read it back by blob SHA before publishing and verify that its exact text length and SHA-256 match the Base64 text that was intended. A returned create-blob SHA alone is not sufficient verification.\n"
            . "5. Only after every payload passes read-back verification, prepare the small upload command. Publish all payload files plus `wordpress-bridge/commands/pending/<id>.json` together in one Git tree/commit/ref update whenever Git Data operations are available.\n"
            . "6. The upload command calls `/wp-agent-bridge-runtime/v1/media-upload` with ordered `data_paths`, `filename`, `expected_bytes`, and `expected_sha256`. Each path is decoded independently and the decoded binary chunks are concatenated in that same order.\n"
            . "7. WordPress verifies the reconstructed byte count and SHA-256 before creating the attachment, then removes all temporary `.b64` source files in one Git tree cleanup commit with bounded optimistic retry.\n"
            . "8. Treat a visible result as complete only after the matching pending command has disappeared or the completed command is visible before staging the next upload.\n"
            . "9. If payload read-back verification fails or the final media integrity check fails, do not reuse that payload or request ID; regenerate the chunks from the original binary with a new ID.\n"
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
            . "For multi-file media input, split the original binary first, Base64-encode each chunk independently, keep each payload at or below 8,000 Base64 characters, verify every staged blob by read-back SHA/text length, then publish payload files plus the upload command in one Git tree/commit/ref update when possible.\n"
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
