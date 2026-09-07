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
            . "## Canonical runtime fast path\n\n"
            . "If this exact repository, branch, and marker were already verified as canonical in the current task/session, reuse them unless a migration signal appeared.\n"
            . "Migration signals are limited to: an explicit migration/reconnection notice; the marker becoming non-canonical or naming another repository; a write failing because the repository/branch is missing or inaccessible; WordPress reporting a Direct Runtime mapping mismatch; or the connected GitHub account/tool context changing.\n"
            . "If runtime resolution is required, read `wordpress-bridge/RUNTIME_CONNECTION.json` FIRST. Do not enumerate neighboring repositories first.\n"
            . "If a candidate marker says `status=retired` or `do_not_use=true`, stop using it. If it contains `replaced_by`, go directly to that replacement repository and read only its marker next.\n"
            . "Accept this runtime only when repository=`" . $repository . "`, branch=`" . $branch . "`, status=canonical, transport=direct-github-webhook, site_host=`" . $site_host . "`, ownership=user-owned, and operator_relay=false agree.\n"
            . "If a usable GitHub write action is already visible, do not run connector discovery. Once canonical is established, create `wordpress-bridge/commands/pending/<id>.json` immediately for ordinary commands.\n"
            . "Create commands at `wordpress-bridge/commands/pending/<id>.json`; read matching results from `wordpress-bridge/results/<id>.json`. Use unique `id` and `request_id` values and respect Bridge confirmation/SHA/plan guards.\n"
            . "After a result appears, do not race the next branch update against result/completed cleanup for the preceding command.\n\n"
            . "## Tool availability / connector discovery\n\n"
            . "Do not conclude GitHub write access is unavailable merely because write actions are not initially visible. Discover the connected GitHub connector only when needed. Ordinary commands need `create_file`; fast media staging should prefer Git Data actions `create_blob`, `create_tree`, `create_commit`, and `update_ref` when available.\n"
            . "When Git Data operations are available, publish related staged inputs plus the pending command in ONE tree/commit/ref update instead of moving the runtime branch once per file.\n\n"
            . "## Diagnostics and WordPress-server work\n\n"
            . "WPVibe compatibility: prefer WP Agent Bridge's own structured actions before WPVibe or temporary debug routes. Use `POST /takka-v094/v1/manage` for `http.probe`, `http.probe.batch`, and `media.file.inspect`; `POST /takka-v095/v1/manage` for `theme.file.outline`, `theme.file.read.range`, `page.html.inspect`, `classic_theme.create`, `classic_theme.preview_url`, `classic_theme.publish`, and `classic_theme.discard`; and `POST /takka-v096/v1/theme-files` for `theme.files.list`, `theme.files.search`, and `theme.file.read.many`.\n"
            . "WP Agent Bridge is not a browser runtime. Browser-executed JavaScript/DOM/layout remains a browser-side task.\n\n"
            . "## Exact theme asset writes\n\n"
            . "For an exact known UTF-8 theme asset, use one guarded `theme.file.write` with `require_integrity=true`, `expected_content_sha256`, and the current `expected_current_sha256` (or `expected_current_absent=true` for a new target).\n\n"
            . "## Site Icon / favicon and local or Drive media\n\n"
            . "Use `POST /takka-v096/v1/manage` with `site.icon.get`, `site.icon.set`, `site.icon.clear`, or `media.upload.capabilities`.\n"
            . "For Google Drive or another connector source, retrieve/download the file through the agent/connector first, then use the same staged-media route as a ChatGPT-local file; WordPress does not need the connector credential.\n"
            . "An existing Media Library image should use `site.icon.set` rather than re-upload. To upload and assign in one operation, include `set_site_icon=true` and `confirm_site_icon=true`; `expected_site_icon_id` may guard stale overwrites.\n\n"
            . "## Media uploads\n\n"
            . "Choose the batched staged-media route by default. Do not perform a separate verify pass during a normal new upload: `/wp-agent-bridge-runtime/v1/media-upload` already validates optional per-chunk integrity and mandatory whole-file bytes/SHA-256 before creating the attachment. Use `/wp-agent-bridge-runtime/v1/media-verify` only for an explicit verify-only request, staging uncertainty, or diagnosis/recovery after an integrity 409.\n\n"
            . "### ChatGPT-local / conversation-uploaded files — preferred batched path\n"
            . "1. Compute whole-file `expected_bytes` and SHA-256 from the original local binary.\n"
            . "2. split the ORIGINAL BINARY into ordered chunks before Base64 encoding. Keep total decoded size within 6 MiB and at most 32 chunks. Prefer about 512 KiB decoded chunks when connector limits are unknown; smaller files should normally use one chunk.\n"
            . "3. Base64-encode each chunk independently and compute each decoded chunk's byte count and SHA-256. Stage each Base64 string under `wordpress-bridge/media/pending/<id>-<index>.b64`.\n"
            . "4. Build ordered `data_paths` and an aligned `chunk_integrity` array. Keep whole-file integrity separately.\n"
            . "5. If `create_blob` is available, retain each returned staged payload Git blob SHA and send an aligned `data_blob_shas` array with the upload command. This activates the blob-SHA direct fast path: WordPress reads blobs directly, verifies all staged path-to-blob mappings with one tree read, and reuses that snapshot for normal cleanup.\n"
            . "6. With Git Data operations, create all payload blobs plus the command blob, then publish them in ONE tree/commit/ref update. The command-bearing push should be the only WordPress-triggering push needed for the upload.\n"
            . "7. Send ONE upload command with `type=rest`, `method=POST`, `route=/wp-agent-bridge-runtime/v1/media-upload`, `filename`, ordered `data_paths`, whole-file integrity, `chunk_integrity`, and `data_blob_shas` when known. Do not call media-verify first in the normal path.\n"
            . "8. If only `create_file` is available, stage all `.b64` payloads first without waiting for WordPress after each write, then create the single pending upload command last. The legacy path remains compatible when `data_blob_shas` is unavailable.\n"
            . "9. On an integrity 409, inspect `chunk_diagnostics` or the fast-path chunk error, replace only the mismatched staged payload file(s) when identifiable, and then use `/wp-agent-bridge-runtime/v1/media-verify` only if diagnosis is still needed.\n"
            . "10. On success, WordPress reconstructs and validates the file, creates the attachment, optionally assigns Site Icon, and removes staged payloads with one Git tree cleanup commit and bounded retry.\n\n"
            . "### Sequential chunk-command fallback\n"
            . "Use `/wp-agent-bridge-media/v1/upload-chunk` only when Base64 text/blob staging is unavailable or the batched staged-media path actually fails. Lack of a GitHub local-file parameter alone is not a reason to choose this slower route.\n\n"
            . "Normal transport: ChatGPT -> this user-owned private repository -> site-specific GitHub App signed push Webhook -> this WordPress -> this repository -> ChatGPT.\n"
            . "Normal operation does not use an operator-owned relay, operator-owned runtime repository, GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.\n";

        $runtime = "# Runtime\n\n"
            . "Ownership: user-owned private GitHub repository.\n"
            . "Transport: site-specific GitHub App + signed push Webhook direct to the user's WordPress.\n"
            . "Repository: `" . $repository . "`\n"
            . "Branch: `" . $branch . "`\n"
            . "Marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "Resolution fast path: reuse this exact canonical repository/branch during the current task/session unless a migration signal appears.\n"
            . "Pending commands: `wordpress-bridge/commands/pending/<id>.json`\n"
            . "Completed commands: `wordpress-bridge/commands/completed/<id>.json`\n"
            . "Results: `wordpress-bridge/results/<id>.json`\n"
            . "Media default: batched staged upload. Normal uploads do not run verify-first; integrity is checked by upload itself. When Git Data staging is available, publish payloads plus command in one commit and pass `data_blob_shas` so WordPress can use direct Git-blob reads.\n"
            . "Verify-only route: `/wp-agent-bridge-runtime/v1/media-verify` for explicit verification, uncertain staging, or post-409 diagnosis.\n"
            . "Sequential chunk commands are fallback only.\n"
            . "Site Icon / favicon: use `site.icon.get`, `site.icon.set`, `site.icon.clear`, or upload with `set_site_icon=true` + `confirm_site_icon=true`.\n"
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
