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
            . "If this exact repository, branch, and marker have already been verified as canonical for `" . $site_host . "` in the current task/session, reuse them immediately unless a migration signal has appeared. Do not search GitHub for other runtimes, reopen retired predecessors, enumerate branches, or reread identity files merely as ritual revalidation.\n"
            . "Migration signals are limited to: an explicit user notice of migration/reconnection; the current marker becoming non-canonical or naming another repository; a GitHub write failing because the repository/branch is missing or inaccessible; WordPress reporting a Direct Runtime mapping/connection mismatch; or the connected GitHub account/tool context actually changing.\n"
            . "If runtime resolution is required, read `wordpress-bridge/RUNTIME_CONNECTION.json` FIRST. Do not inspect `AGENTS.md`, commits, branches, command history, or neighboring repositories before the marker.\n"
            . "If a candidate marker says `status=retired` or `do_not_use=true`, stop inspecting that repository immediately. If it contains `replaced_by`, go directly to that replacement repository and read only its marker next; do not search GitHub for alternative runtimes.\n"
            . "Accept this runtime as canonical only when repository=`" . $repository . "`, branch=`" . $branch . "`, `status=canonical`, `transport=direct-github-webhook`, `site_host=" . $site_host . "`, `ownership=user-owned`, and `operator_relay=false` all agree.\n"
            . "Once canonical is established, if a usable GitHub write action such as `create_file` is already available, create `wordpress-bridge/commands/pending/<id>.json` immediately. Do not perform connector discovery or another marker verification before the command unless a migration signal occurred.\n"
            . "Old conversation history, memory, cached instructions, or a similarly named operator-owned runtime are not reasons to reopen a retired repository after the current canonical runtime has been established.\n\n"
            . "Create commands at `wordpress-bridge/commands/pending/<id>.json` and read results from `wordpress-bridge/results/<id>.json`.\n"
            . "Use unique `id` and `request_id` values. Respect preview/confirm/SHA/plan/impact guards returned by the Bridge.\n"
            . "When GitHub Git Data operations are available, stage related input files as blobs first and publish them to the runtime branch in one tree/commit/ref update instead of moving the branch once per file.\n"
            . "After a command result appears, do not start the next branch update until the matching pending command has been removed or the matching completed command is visible. This avoids racing WordPress result/completed cleanup writes.\n\n"
            . "## Tool availability / connector discovery\n\n"
            . "Do NOT conclude that WP Agent Bridge or GitHub write access is unavailable merely because GitHub write actions are not initially visible in the current tool list.\n"
            . "Before stopping for `no write tool` or `GitHub cannot receive this local file`, use the available connector/tool discovery mechanism to load the connected GitHub connector write actions for this repository. Look for `create_file` / `update_file` and, when useful, Git Data actions such as `create_blob`, `create_tree`, `create_commit`, and `update_ref` (or equivalent names exposed by the current connector).\n"
            . "If a usable GitHub write action is already visible, do not run connector discovery. If discovery was actually required, keep the already-established canonical runtime and do not re-verify its marker afterward unless the connected GitHub account/tool context changed. For ordinary runtime commands, `create_file` for `wordpress-bridge/commands/pending/<id>.json` is sufficient; a local-file parameter on the GitHub connector is NOT required.\n"
            . "For ChatGPT-local media, prefer batched staged-media transport when GitHub can write UTF-8 text/blobs. A GitHub local-file parameter is NOT required: Base64-encode bounded binary chunks locally, stage those Base64 strings as text payload files, then submit one `/wp-agent-bridge-runtime/v1/media-upload` command referencing all `data_paths`.\n"
            . "Only report a genuine tool blocker after connector discovery has been attempted and either no usable GitHub write action exists or an actual write call fails. State the concrete missing/failed action and error; do not stop at an assumed capability gap.\n\n"
            . "## Diagnostics before WPVibe or temporary debug routes\n\n"
            . "For same-site HTTP/resource-loading diagnostics, do not switch to WPVibe or add a temporary PHP debug route before trying WP Agent Bridge's built-in diagnostics. Use one normal REST command to `POST /takka-v094/v1/manage`.\n"
            . "- `http.probe`: same-origin GET/HEAD with Android/Desktop Chrome image/document header profiles, bounded custom allowlisted headers, same-origin redirect following, response status/headers/timing, MIME from headers and magic bytes, SHA-256, binary prefix, text snippet, and optional body search contexts.\n"
            . "- `http.probe.batch`: run up to 20 such probes in one command when comparing browser profiles or multiple resource URLs.\n"
            . "- `media.file.inspect`: inspect a Media Library attachment or same-origin uploads URL and compare WordPress MIME, file extension, finfo MIME, magic bytes, size, and SHA-256. Filesystem access is restricted to WordPress uploads.\n"
            . "These diagnostics send no WordPress cookies, do not allow Authorization/Host-style arbitrary headers, and do not allow arbitrary outbound hosts. Use an actual browser/web capability only when the question depends on client-side JavaScript execution, DOM state after JavaScript, layout, or browser-only events such as `img.onerror`.\n\n"
            . "## WPVibe compatibility / server-side inspection\n\n"
            . "Before switching to WPVibe for a WordPress-server task, check WP Agent Bridge's structured actions and REST proxy. For structural inspection/theme creation use `POST /takka-v095/v1/manage`; for multi-file listing/search/batch reads use `POST /takka-v096/v1/theme-files`.\n"
            . "- `theme.file.outline`: get functions, classes, hooks, REST routes, selectors, landmarks, and other structural elements without reading an entire theme file.\n"
            . "- `theme.file.read.range`: read only a bounded line range after locating the relevant structure.\n"
            . "- `theme.files.list`: list active or draft theme files with an optional glob pattern and bounded metadata.\n"
            . "- `theme.files.search`: grep-like bounded substring search across theme source with line/context output.\n"
            . "- `theme.file.read.many`: read up to 20 bounded file ranges in ONE Bridge command; do not split a multi-file read into repeated commands merely because an older `read_many` alias was unavailable.\n"
            . "- `page.html.inspect`: fetch same-origin server-rendered HTML, optionally extract a supported CSS selector subset, and return status/headers/timing. It sends no WordPress cookies and explicitly does not execute JavaScript.\n"
            . "- `classic_theme.create`, `classic_theme.list`, `classic_theme.info`, `classic_theme.preview_url`, `classic_theme.publish`, `classic_theme.discard`: create and manage a standalone classic-theme draft without overwriting the currently active theme during drafting. Publishing uses WordPress theme activation and leaves the previous theme files intact for rollback.\n"
            . "Use the existing WP Agent Bridge surfaces for REST, Abilities, plugin/theme management, options, users/roles/terms, posts/media/comments through REST, cron, cache/rewrite, DB SELECT/search-replace, and ordinary theme draft/read/write/patch/publish/rollback work instead of WPVibe.\n"
            . "WP Agent Bridge is not a browser runtime: JavaScript-executed DOM state, visual layout, browser-only events, and navigation of the ChatGPT client require a browser-side capability. External image search and ChatGPT connector/account management are also agent/product-layer tasks rather than WordPress-server operations.\n\n"
            . "## Exact theme asset writes\n\n"
            . "When restoring or replacing an exact known theme asset, especially SVG/logo files, do not reconstruct the target incrementally when the complete UTF-8 asset fits the normal theme write limit. Read the current target once and perform one guarded `theme.file.write`.\n"
            . "Set `require_integrity=true`, set `expected_content_sha256` to the SHA-256 of the exact intended content, and set `expected_current_sha256` to the SHA-256 returned by the current target read. If the target must be new, use `expected_current_absent=true` instead.\n"
            . "The Bridge rejects a content mismatch before mutation and rejects a stale current target before the legacy backup/lint/atomic replacement path runs. Do not substitute a visually similar or alternate asset when the requested source hash is known.\n\n"
            . "## Site Icon / favicon and local or Drive media\n\n"
            . "Do NOT treat favicon/Site Icon assignment as unsupported merely because the generic option patcher does not write scalar `site_icon`. WP Agent Bridge has a dedicated guarded Site Icon surface at `POST /takka-v096/v1/manage`.\n"
            . "- `site.icon.get`: read the current Site Icon attachment, URLs, MIME, dimensions and current attachment ID.\n"
            . "- `site.icon.set`: assign an existing Media Library image by `attachment_id`; requires `confirm=true` and supports `expected_current_id` stale-write protection.\n"
            . "- `site.icon.clear`: clear the Site Icon without deleting its Media Library attachment; requires `confirm=true` and supports `expected_current_id`.\n"
            . "- `media.upload.capabilities`: return the canonical runtime-media route and supported local/connector/Drive source workflow.\n"
            . "For a file originating from Google Drive or another connector, retrieve/download the file through the agent/connector first, then treat the resulting bytes exactly like a ChatGPT-local file and use the batched staged-media route. WP Agent Bridge itself does not need a Google Drive credential.\n"
            . "When the same uploaded image should immediately become the favicon, send one `/wp-agent-bridge-runtime/v1/media-upload` command with `set_site_icon=true` and `confirm_site_icon=true`. Optionally include `expected_site_icon_id` to reject a stale overwrite. The upload result includes the Site Icon outcome.\n"
            . "If the image is already in the Media Library, do not re-upload it; call `site.icon.set` with its attachment ID.\n\n"
            . "## Media uploads\n\n"
            . "Choose the media transport from connector capability and avoid per-chunk WordPress round trips when a batched staged upload is possible.\n\n"
            . "### ChatGPT-local / conversation-uploaded files — preferred batched path\n"
            . "If the source exists only as a ChatGPT attachment or local sandbox/container path, a direct local-file parameter on the GitHub connector is not required.\n"
            . "1. Compute whole-file `expected_bytes` and SHA-256 from the original local binary.\n"
            . "2. Split the ORIGINAL BINARY into ordered chunks before Base64 encoding. Keep total decoded size within 6 MiB and use at most 32 chunks. Prefer bounded chunks such as about 256 KiB decoded if connector payload limits are uncertain.\n"
            . "3. Base64-encode each binary chunk independently. For every decoded chunk also compute its byte count and SHA-256. Stage each Base64 string as UTF-8 text under `wordpress-bridge/media/pending/<id>-<index>.b64`.\n"
            . "4. Build an ordered `chunk_integrity` array aligned with `data_paths`; each entry contains the chunk `expected_bytes`, `expected_sha256`, and optionally its matching `path`. Keep the whole-file `expected_bytes` and `expected_sha256` separately.\n"
            . "5. When the transfer is new but high-value, when staged payload integrity is uncertain, or after any previous media integrity 409, first submit ONE verify-only REST command to `/wp-agent-bridge-runtime/v1/media-verify` with the same `data_paths`, whole-file integrity fields, and `chunk_integrity`. This route does not create an attachment, change Site Icon, or clean staged files.\n"
            . "6. If verification reports a mismatched chunk, replace only the mismatched staged payload file(s), then verify again. If all declared chunks match but the whole file does not, re-check source chunk ordering and the whole-file source hash rather than blindly retransmitting every chunk.\n"
            . "7. After verification succeeds, build ONE normal runtime REST command with `type=rest`, `method=POST`, `route=/wp-agent-bridge-runtime/v1/media-upload`, `filename`, whole-file integrity fields, ordered `data_paths`, and the same `chunk_integrity`. For upload-and-favicon in one operation, also add `set_site_icon=true` and `confirm_site_icon=true`.\n"
            . "8. If `create_blob` / `create_tree` / `create_commit` / `update_ref` are available, create all payload blobs plus the command blob first, then publish all paths in ONE tree/commit/ref update. This intentionally produces one command-bearing push/Webhook instead of one WordPress round trip per chunk.\n"
            . "9. If only `create_file` is available, stage all `.b64` payload files first without waiting for WordPress results after each payload write, then create the single pending media command last. Payload-only pushes may still occur, but there is only one verify/upload result to wait for.\n"
            . "10. On an upload integrity 409, inspect `chunk_diagnostics` in the error response before retrying. The upload route reports the same per-chunk actual bytes/SHA diagnostics when it can re-read the staged payloads.\n"
            . "11. On success, WordPress reconstructs and validates the file, creates the attachment, optionally assigns it as Site Icon when explicitly requested, then removes the staged payloads with its bounded single-tree cleanup flow.\n"
            . "This batched staged-media path is the default for ChatGPT-local and connector-downloaded files because it removes the previous `chunk command -> Webhook -> result -> next chunk` loop while retaining exact integrity diagnostics.\n\n"
            . "### Sequential chunk-command fallback\n"
            . "Use `/wp-agent-bridge-media/v1/upload-chunk` only when the connector cannot reliably stage Base64 text payload files/blobs for the batched path or the batched staged-media write actually fails.\n"
            . "For this fallback, compute whole-file and per-chunk integrity, keep chunks ordered and bounded, and send chunk commands sequentially so the final chunk can reassemble and validate the upload. Do not choose this slower route merely because the GitHub connector lacks a local-file parameter.\n\n"
            . "### Existing GitHub-staged / remote media\n"
            . "For media already available to the GitHub connector as manageable text/blob input, use the same `wordpress-bridge/media/pending/*.b64` + verify/upload batched path. Compute whole-file and per-chunk integrity first, split the ORIGINAL BINARY before Base64-encoding each part, verify staged payloads, publish payloads plus command atomically when possible, and let WordPress verify + clean them in one bounded-retry commit.\n\n"
            . "Normal transport: ChatGPT -> this user-owned private repository -> site-specific GitHub App signed push Webhook -> this WordPress -> this repository -> ChatGPT.\n"
            . "Normal operation does not use an operator-owned relay, operator-owned runtime repository, GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.\n";

        $runtime = "# Runtime\n\n"
            . "Ownership: user-owned private GitHub repository.\n"
            . "Transport: site-specific GitHub App + signed push Webhook direct to the user's WordPress.\n"
            . "Repository: `" . $repository . "`\n"
            . "Branch: `" . $branch . "`\n"
            . "Marker: `wordpress-bridge/RUNTIME_CONNECTION.json`\n"
            . "Resolution fast path: reuse this exact canonical repository/branch during the current task/session unless a migration signal appears. If verification is required, read `wordpress-bridge/RUNTIME_CONNECTION.json` first and only; a retired marker must redirect through `replaced_by` without further repository inspection. If `create_file` is already available, write the pending command immediately instead of rediscovering GitHub tools or runtimes.\n"
            . "Pending commands: `wordpress-bridge/commands/pending/<id>.json`\n"
            . "Completed commands: `wordpress-bridge/commands/completed/<id>.json`\n"
            . "Results: `wordpress-bridge/results/<id>.json`\n"
            . "If GitHub write actions are not initially visible, discover/load the connected GitHub connector write actions before claiming the runtime is unavailable. `create_file` is sufficient for ordinary commands; for fast local-media batching, prefer Git Data actions when available.\n"
            . "Built-in diagnostics: `POST /takka-v094/v1/manage` with `http.probe`, `http.probe.batch`, or `media.file.inspect`. Use these before WPVibe or temporary debug routes for same-site HTTP, image-request, MIME, magic-byte, and uploads-file diagnostics. Browser JavaScript/DOM execution remains a browser-side task.\n"
            . "WPVibe compatibility / server-side inspection: use `POST /takka-v095/v1/manage` for `theme.file.outline`, `theme.file.read.range`, `page.html.inspect`, and classic-theme lifecycle; use `POST /takka-v096/v1/theme-files` for `theme.files.list`, `theme.files.search`, and `theme.file.read.many`. Prefer these and existing WP Agent Bridge structured/REST actions over WPVibe for WordPress-server work.\n"
            . "Exact known theme assets: use one `theme.file.write` with `require_integrity=true`, source `expected_content_sha256`, and the current target `expected_current_sha256` (or `expected_current_absent=true` for a new target).\n"
            . "Site Icon / favicon: `POST /takka-v096/v1/manage` with `site.icon.get`, `site.icon.set`, `site.icon.clear`, or `media.upload.capabilities`. Existing Media Library images use `site.icon.set`; local/connector/Drive files use the normal staged-media upload and may include `set_site_icon=true` + `confirm_site_icon=true` for upload-and-assign in one command.\n"
            . "ChatGPT-local or connector-downloaded media default: split the original binary into bounded chunks, Base64 each chunk independently, stage the Base64 text under `wordpress-bridge/media/pending/*.b64`, and retain whole-file plus per-chunk bytes/SHA-256.\n"
            . "For high-value/new transfers, uncertain staging, or after a media integrity 409, call `/wp-agent-bridge-runtime/v1/media-verify` with ordered `data_paths` and `chunk_integrity` before upload. It has no attachment/Site Icon/cleanup side effects.\n"
            . "If verification identifies a bad chunk, replace only that staged payload. If every declared chunk matches but the whole file does not, inspect ordering/source hash. On upload 409 inspect returned `chunk_diagnostics` before retrying.\n"
            . "When `create_blob` / `create_tree` / `create_commit` / `update_ref` are available, publish all payload blobs plus the single command blob in ONE tree/commit/ref update so one command-bearing push/Webhook handles the upload.\n"
            . "If only `create_file` is available, write all payload files first without waiting for a WordPress result after each file, then write the single verify/upload command last and wait once for its result.\n"
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
