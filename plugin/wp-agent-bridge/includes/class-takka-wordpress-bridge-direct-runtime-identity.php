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
            . "For ChatGPT-local media, prefer batched staged-media transport when GitHub can write UTF-8 text/blobs. A GitHub local-file parameter is NOT required: Base64-encode bounded binary chunks locally, stage those Base64 strings as text payload files, then submit one `/wp-agent-bridge-runtime/v1/media-upload` command referencing all `data_paths`.\n"
            . "Only report a genuine tool blocker after connector discovery has been attempted and either no usable GitHub write action exists or an actual write call fails. State the concrete missing/failed action and error; do not stop at an assumed capability gap.\n\n"
            . "## Diagnostics before WPVibe or temporary debug routes\n\n"
            . "For same-site HTTP/resource-loading diagnostics, do not switch to WPVibe or add a temporary PHP debug route before trying WP Agent Bridge's built-in diagnostics. Use one normal REST command to `POST /takka-v094/v1/manage`.\n"
            . "- `http.probe`: same-origin GET/HEAD with Android/Desktop Chrome image/document header profiles, bounded custom allowlisted headers, same-origin redirect following, response status/headers/timing, MIME from headers and magic bytes, SHA-256, binary prefix, text snippet, and optional body search contexts.\n"
            . "- `http.probe.batch`: run up to 20 such probes in one command when comparing browser profiles or multiple resource URLs.\n"
            . "- `media.file.inspect`: inspect a Media Library attachment or same-origin uploads URL and compare WordPress MIME, file extension, finfo MIME, magic bytes, size, and SHA-256. Filesystem access is restricted to WordPress uploads.\n"
            . "These diagnostics send no WordPress cookies, do not allow Authorization/Host-style arbitrary headers, and do not allow arbitrary outbound hosts. Use an actual browser/web capability only when the question depends on client-side JavaScript execution, DOM state after JavaScript, layout, or browser-only events such as `img.onerror`.\n\n"
            . "## Media uploads\n\n"
            . "Choose the media transport from connector capability and avoid per-chunk WordPress round trips when a batched staged upload is possible.\n\n"
            . "### ChatGPT-local / conversation-uploaded files — preferred batched path\n"
            . "If the source exists only as a ChatGPT attachment or local sandbox/container path, a direct local-file parameter on the GitHub connector is not required.\n"
            . "1. Compute whole-file `expected_bytes` and SHA-256 from the original local binary.\n"
            . "2. Split the ORIGINAL BINARY into ordered chunks before Base64 encoding. Keep total decoded size within 6 MiB and use at most 32 chunks. Prefer bounded chunks such as about 256 KiB decoded if connector payload limits are uncertain.\n"
            . "3. Base64-encode each binary chunk independently. Stage each Base64 string as UTF-8 text under `wordpress-bridge/media/pending/<id>-<index>.b64`.\n"
            . "4. Build ONE normal runtime REST command with `type=rest`, `method=POST`, `route=/wp-agent-bridge-runtime/v1/media-upload`, `filename`, whole-file `expected_bytes`, whole-file `expected_sha256`, and an ordered `data_paths` array containing all staged chunk paths.\n"
            . "5. If `create_blob` / `create_tree` / `create_commit` / `update_ref` are available, create all payload blobs plus the command blob first, then publish all paths in ONE tree/commit/ref update. This intentionally produces one command-bearing push/Webhook instead of one WordPress round trip per chunk.\n"
            . "6. If only `create_file` is available, stage all `.b64` payload files first without waiting for WordPress results after each payload write, then create the single pending media-upload command last. Payload-only pushes may still occur, but there is only one upload command and one upload result to wait for.\n"
            . "7. Wait once for the media-upload result. WordPress reads all ordered `data_paths`, reconstructs and validates the file, creates the attachment, then removes the staged payloads with its bounded single-tree cleanup flow.\n"
            . "This batched staged-media path is the default for ChatGPT-local files because it removes the previous `chunk command -> Webhook -> result -> next chunk` loop.\n\n"
            . "### Sequential chunk-command fallback\n"
            . "Use `/wp-agent-bridge-media/v1/upload-chunk` only when the connector cannot reliably stage Base64 text payload files/blobs for the batched path or the batched staged-media write actually fails.\n"
            . "For this fallback, compute whole-file and per-chunk integrity, keep chunks ordered and bounded, and send chunk commands sequentially so the final chunk can reassemble and validate the upload. Do not choose this slower route merely because the GitHub connector lacks a local-file parameter.\n\n"
            . "### Existing GitHub-staged / remote media\n"
            . "For media already available to the GitHub connector as manageable text/blob input, use the same `wordpress-bridge/media/pending/*.b64` + `/wp-agent-bridge-runtime/v1/media-upload` batched path. Compute whole-file integrity first, split the ORIGINAL BINARY before Base64-encoding each part, verify staged payloads, publish payloads plus command atomically when possible, and let WordPress verify + clean them in one bounded-retry commit.\n\n"
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
            . "If GitHub write actions are not initially visible, discover/load the connected GitHub connector write actions before claiming the runtime is unavailable. `create_file` is sufficient for ordinary commands; for fast local-media batching, prefer Git Data actions when available.\n"
            . "Built-in diagnostics: `POST /takka-v094/v1/manage` with `http.probe`, `http.probe.batch`, or `media.file.inspect`. Use these before WPVibe or temporary debug routes for same-site HTTP, image-request, MIME, magic-byte, and uploads-file diagnostics. Browser JavaScript/DOM execution remains a browser-side task.\n"
            . "ChatGPT-local media default: split the original binary into bounded chunks, Base64 each chunk independently, stage the Base64 text under `wordpress-bridge/media/pending/*.b64`, then submit ONE `/wp-agent-bridge-runtime/v1/media-upload` command with ordered `data_paths`, whole-file bytes, and SHA-256.\n"
            . "When `create_blob` / `create_tree` / `create_commit` / `update_ref` are available, publish all payload blobs plus the single command blob in ONE tree/commit/ref update so one command-bearing push/Webhook handles the upload.\n"
            . "If only `create_file` is available, write all payload files first without waiting for a WordPress result after each file, then write the single media-upload command last and wait once for its result.\n"
            . "Sequential `/wp-agent-bridge-media/v1/upload-chunk` commands are a fallback only when Base64 text/blob staging is unavailable or the batched staged-media path actually fails; lack of a GitHub local-file parameter alone is not a reason to choose the slow sequential route.\n"
            . "Successful staged-media upload validates whole-file integrity and cleanup is performed as one Git tree cleanup commit with bounded retry if the runtime branch moved.\n"
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
