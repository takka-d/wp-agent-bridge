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
            . "## Tool availability / connector discovery\n\n"
            . "Do NOT conclude that WP Agent Bridge or GitHub write access is unavailable merely because GitHub write actions are not initially visible in the current tool list.\n"
            . "Before stopping for `no write tool` or `GitHub cannot receive this local file`, use the available connector/tool discovery mechanism to load the connected GitHub connector write actions for this repository. Look for `create_file` / `update_file` and, when useful, Git Data actions such as `create_blob`, `create_tree`, `create_commit`, and `update_ref` (or equivalent names exposed by the current connector).\n"
            . "Re-verify the canonical repository/branch/marker after discovery. For ordinary runtime commands, `create_file` for `wordpress-bridge/commands/pending/<id>.json` is sufficient; a local-file parameter on the GitHub connector is NOT required.\n"
            . "For ChatGPT-local media, Base64 belongs inside bounded normal runtime command JSON sent to `/wp-agent-bridge-media/v1/upload-chunk`; do not try to hand the local file path itself to GitHub.\n"
            . "Only report a genuine tool blocker after connector discovery has been attempted and either no usable GitHub write action exists or an actual write call fails. State the concrete missing/failed action and error; do not stop at an assumed capability gap.\n\n"
            . "## Media uploads\n\n"
            . "Choose the media transport from the SOURCE of the file; do not blindly use media/pending for every image.\n\n"
            . "### ChatGPT-local / conversation-uploaded files\n"
            . "If the source exists only as a ChatGPT attachment or local sandbox/container path and the GitHub connector does not expose a file-parameter upload action, do NOT spend time trying to copy that local file into `wordpress-bridge/media/pending/*.b64`.\n"
            . "Use `/wp-agent-bridge-media/v1/upload-chunk` through normal runtime REST commands.\n"
            . "1. Compute whole-file `expected_bytes` and SHA-256 from the original local binary.\n"
            . "2. Split the binary into ordered chunks. The Bridge supports up to 1,200,000 decoded bytes per chunk, 32 chunks, and 6 MiB total; prefer smaller chunks such as 256 KiB decoded if connector/tool payload limits are uncertain.\n"
            . "3. For each chunk compute `chunk_bytes` and `chunk_sha256`, Base64-encode that chunk only, and create one normal runtime command under `wordpress-bridge/commands/pending/` with `upload_id`, `chunk_index`, `chunk_count`, `filename`, whole-file integrity fields, chunk integrity fields, and `data_b64`.\n"
            . "4. The command is an ordinary REST command: `type=rest`, `method=POST`, `route=/wp-agent-bridge-media/v1/upload-chunk`. The GitHub connector only writes the command JSON text; it does not need a local-file upload parameter.\n"
            . "5. Send chunks sequentially and wait for the matching pending command to disappear or completed result to appear before sending the next chunk.\n"
            . "6. The final chunk automatically reassembles, verifies, creates the attachment, and cleans WordPress-side staging files.\n"
            . "This route bypasses `wordpress-bridge/media/pending/` and is preferred for ChatGPT-local files.\n\n"
            . "### GitHub-staged media\n"
            . "If the media is already available to the GitHub connector as manageable text/blob input without serializing a large local file through the model, the `wordpress-bridge/media/pending/*.b64` + `/wp-agent-bridge-runtime/v1/media-upload` path remains available.\n"
            . "Compute whole-file integrity first, split the ORIGINAL BINARY before Base64-encoding each part, verify staged payloads, publish payloads plus command atomically when possible, and let WordPress verify + clean them in one bounded-retry commit.\n\n"
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
            . "If GitHub write actions are not initially visible, discover/load the connected GitHub connector write actions before claiming the runtime is unavailable. `create_file` for a pending command is sufficient for ordinary commands and local-media chunk commands.\n"
            . "ChatGPT-local media: `/wp-agent-bridge-media/v1/upload-chunk` via normal runtime commands; this bypasses GitHub media payload files.\n"
            . "GitHub-staged media: `wordpress-bridge/media/pending/<id>.b64` -> `/wp-agent-bridge-runtime/v1/media-upload`.\n"
            . "Choose chunk upload first when the source exists only as a local conversation/sandbox file and the GitHub connector has no file-parameter upload action.\n"
            . "Successful staged-media cleanup is performed as one Git tree cleanup commit with bounded retry if the runtime branch moved.\n"
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
