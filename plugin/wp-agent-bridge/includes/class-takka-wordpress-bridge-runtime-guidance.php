<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Keeps AGENTS.md and WEBHOOK_RUNTIME.md aligned with the installed Bridge.
 *
 * This intentionally supersedes older verbose/stale runtime guidance so model
 * clients receive one short deterministic routing contract per Bridge version.
 */
final class TakKa_WordPress_Bridge_Runtime_Guidance
{
    private const OPTION_SYNCED_VERSION = 'takka_bridge_runtime_guidance_synced_version';
    private const TRANSIENT_LOCK = 'takka_bridge_runtime_guidance_sync_lock';

    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_sync'], 31);
    }

    public static function maybe_sync(): void
    {
        $version = self::bridge_version();
        if ($version === '') {
            return;
        }
        if (get_transient(self::TRANSIENT_LOCK)) {
            return;
        }
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return;
        }
        // Disk headers can advance while an older request still has old classes loaded.
        // Stamp the generated contract, so the next fresh request repairs stale content.
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '') $host = 'wordpress';
        $signature = $version . ':' . hash('sha256', self::agents($repository, $branch, $host, $version) . "\n" . self::runtime($repository, $branch, $host, $version));
        if ((string) get_option(self::OPTION_SYNCED_VERSION, '') === $signature) {
            return;
        }

        set_transient(self::TRANSIENT_LOCK, '1', 300);
        $result = self::sync();
        if (!is_wp_error($result)) {
            update_option(self::OPTION_SYNCED_VERSION, $signature, false);
            delete_transient(self::TRANSIENT_LOCK);
        }
    }

    public static function sync()
    {
        $connection = TakKa_WordPress_Bridge_Direct_Runtime::connection();
        if (!self::valid_connection($connection)) {
            return new WP_Error('wpab_runtime_guidance_connection', 'Direct Runtime connection is incomplete.', ['status' => 503]);
        }
        $repository = (string) $connection['repository'];
        $branch = (string) $connection['runtime_branch'];
        $token = TakKa_WordPress_Bridge_Direct_GitHub::installation_token(
            (int) $connection['installation_id'],
            (int) $connection['repository_id']
        );
        if (is_wp_error($token)) {
            return $token;
        }
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if ($host === '') $host = 'wordpress';
        $version = self::bridge_version();

        $agents = self::agents($repository, $branch, $host, $version);
        $runtime = self::runtime($repository, $branch, $host, $version);
        $results = [];
        foreach ([
            'AGENTS.md' => $agents,
            'wordpress-bridge/WEBHOOK_RUNTIME.md' => $runtime,
        ] as $path => $content) {
            $current = TakKa_WordPress_Bridge_Direct_GitHub::get_text_file($token, $repository, $branch, $path);
            if (!is_wp_error($current) && hash_equals(hash('sha256', $current), hash('sha256', $content))) {
                $results[$path] = ['changed' => false, 'sha256' => hash('sha256', $content)];
                continue;
            }
            if (is_wp_error($current)
                && TakKa_WordPress_Bridge_Direct_GitHub_Recovery::error_status($current) !== 404) {
                return $current;
            }
            $written = TakKa_WordPress_Bridge_Direct_GitHub::put_text_file(
                $token,
                $repository,
                $branch,
                $path,
                $content,
                'WP Agent Bridge: sync deterministic runtime guidance'
            );
            if (is_wp_error($written)) return $written;
            $results[$path] = ['changed' => true, 'sha256' => hash('sha256', $content)];
        }
        return ['ok' => true, 'files' => $results];
    }

    public static function agents(string $repository, string $branch, string $host, string $version): string
    {
        return "# WP Agent Bridge runtime — CANONICAL\n\n"
            . "Site: `{$host}`  \nRepository: `{$repository}`  \nBranch: `{$branch}`  \nBridge: `{$version}`\n\n"
            . "## Mandatory fast path\n\n"
            . "1. Reuse this repository/branch when already verified in the current task/session. Re-resolve only after an explicit reconnect/migration signal, a mapping mismatch, an inaccessible repository/branch, a non-canonical marker, or a connected GitHub account/tool-context change.\n"
            . "2. Read `wordpress-bridge/RUNTIME_CAPABILITIES.json` before probing Bridge capabilities or searching source code for known routes/actions. Read it once per verified connection/version and reuse its operation catalog. Refresh after a version/connection change or a contradictory response, not before every command.\n"
            . "3. For advertised common operations use `type=operation` with `operation` and `params`. The runtime selects `/takka-v099/v1/operate` internally. Do not reconstruct versioned routes when an operation is listed in the catalog.\n"
            . "4. Never put `?query=...` in a REST `route` field. Put query parameters in the `query` object or in the deterministic operation params.\n"
            . "5. Do not ask the user to identify repositories, files, routes, branches, or installed state that connected tools can inspect directly.\n"
            . "6. Do not switch normal WordPress work to WPVibe, `takka-d/chatgpt-data`, old Bridge Key/queue, an operator relay, temporary PHP installers, or a per-command GitHub Actions worker. GitHub Actions is for source CI/package/release, not the Direct Runtime command path.\n\n"
            . "## Routing rules\n\n"
            . "- Preferred pending JSON: `{\"id\":\"<unique-id>\",\"type\":\"operation\",\"operation\":\"post.get\",\"params\":{\"post_id\":123}}`. The filename is `<unique-id>.json`. This reaches `/takka-v099/v1/operate` without reconstructing a route or method; the explicit REST envelope remains available for compatibility.\n"
            . "- For a low-level action not in the operation catalog, use `type=bridge` with `action` and `params`. Never send `/takka-bridge/v1/execute` as a REST target; that is a recursive proxy, not a direct action.\n"
            . "- Create an article or page: post.create with fields (title/content) and optional post_type=page. It defaults to draft; explicit publish/schedule needs confirm_live. Reuse the command ID on uncertain outcomes. Creation returns the ID, type, status and URL without echoing full content.\n"
            . "- Post/page metadata/status/featured image: `post.get` and `post.update`. The Bridge selects the posts/pages route from the actual object type; post_type, if supplied, must agree. `post.get` is metadata-oriented and bounded by default; do not request `content` through it. `post.update` must not replace `content`.\n"
            . "- When the user supplies a post/page URL, preserve it as params.target_url for post.get, post.update, and post.content.*. Omit post_id unless already verified; the Bridge resolves it locally. If both are supplied they must identify the same object. A target conflict must not be bypassed by dropping or replacing the URL. For a title-only request use target_title (exact title, unique match required), optionally post_type=post or page. Duplicate or non-exact titles are rejected. Use post.find with search to return bounded candidates, then choose the user-intended URL; never silently select the first candidate.\n"
            . "- Post content: `post.content.search`, `post.content.read_range`, guarded `post.content.patch_preview` → `post.content.patch_apply`. Avoid full-post rewrite when a targeted patch is sufficient.\n"
            . "- Small local/conversation media up to 1 MiB decoded: one `media.upload.inline` operation. Supply `expected_bytes` and `expected_sha256` when already known. Do not stage/split/verify-first for this size range. Files above 1 MiB are rejected from the deterministic inline path so they cannot silently take the wrong transport.\n"
            . "- Larger media: staged Media Fast Path. Split original binary before Base64, publish payloads + pending upload command in one Git commit when Git Data is available, and use verify-only only for explicit verification or integrity diagnosis.\n"
            . "- Two or more independent reads supported by the batch catalog: one `readonly.batch`. Check the catalog once before grouping; `post.get` is not currently a batch item and must be submitted separately. Nested items may use the same high-level operation names (`post.content.read_range`, `workspace.file.read_range`, etc.); the router normalizes them to the strict read-only action map. Do not hand-derive another action spelling when a documented alias exists.\n"
            . "- Large iterative HTML/JS/CSS/JSON/POV-Ray work: Workspace search/range/patch/snapshot. Do not rediscover the artifact from File Library/history on every continuation.\n"
            . "- For ordinary WordPress work, submit native runtime commands through the connected GitHub tools. Do not create or run local helper scripts just to wrap a known operation. When local asset generation/testing is necessary, reuse the existing project entrypoint; numbered replacement runners add setup and approval churn without improving the WordPress operation. Client-side approval policy remains outside Bridge control.\n"
            . "- Self-update: use `self_update.status/apply/rollback` operations and pinned official source metadata; verify after apply.\n\n"
            . "- Before changing a user-visible target, verify its identity against the requested page/post/media and the affected content or selector. A matching file hash or successful write proves storage, not the intended visual result. Verify the affected output; if rendering is unavailable, explicitly leave visual correctness unverified.\n\n"
            . "## Failure rules\n\n"
            . "- Keep one command ID and identical payload when checking/recovering an uncertain outcome. A new ID means a new operation; do not generate a fresh ID to retry an unconfirmed mutation.\n"
            . "- Check the result `ok` and operation status, not just successful GitHub reads or outer HTTP 200. A 207 read batch is incomplete: inspect its failed items and do not rerun successful items.\n"
            . "- Fetch the exact `wordpress-bridge/results/<id>.json` path. A missing result is pending/unknown, not proof of failure. Record submission-to-result time separately from `duration_ms`. Results include source_commit and timing for command loading and execution; timing.excludes lists unmeasured phases. Compare source/result commit timestamps only as second-resolution commit-creation evidence, not exact visibility. Never describe execution time as total user wait. Avoid fixed long sleeps: do independent work while pending and use bounded result checks without resubmitting mutations.\n"
            . "- `rest_no_route`: do not repeat unchanged. Check operation catalog and query/route separation.\n"
            . "- guarded content error from `post.update`: switch to `post.content.search/read_range` and guarded patch; do not fall back to a full content rewrite.\n"
            . "- inline media threshold/integrity error: keep the reported bytes/SHA evidence. Use staged Fast Path only when the file is actually above the threshold; for mismatches fix the source data rather than changing transport blindly.\n"
            . "- stale/plan 409: re-read only affected state, regenerate preview/plan, retry the guarded write once.\n"
            . "- media integrity 409: replace only mismatched staging payloads when identifiable; do not change transport blindly.\n"
            . "- runtime ref 409/422: check whether the matching result already exists before any retry. Never replay a WordPress mutation solely because Git publication/reading failed.\n"
            . "- A visible matching result is the completion barrier when atomic bookkeeping is advertised; do not wait for another bookkeeping commit.\n";
    }

    public static function runtime(string $repository, string $branch, string $host, string $version): string
    {
        return "# WP Agent Bridge Direct Runtime\n\n"
            . "Site: `{$host}`\n\n"
            . "Repository: `{$repository}`\n\n"
            . "Branch: `{$branch}`\n\n"
            . "Installed Bridge: `{$version}`\n\n"
            . "Normal transport: ChatGPT → user-owned private runtime repository → site-specific GitHub App signed Webhook → WordPress/WP Agent Bridge → same runtime repository → ChatGPT.\n\n"
            . "GitHub Actions is not a command worker in this path. It belongs to source CI/package/release only.\n\n"
            . "Machine-readable routing contract: `wordpress-bridge/RUNTIME_CAPABILITIES.json`. Common operations use `/takka-v099/v1/operate`; its policy layer bounds post reads, blocks unguarded content replacement, validates the small-media path, and normalizes read-only batch aliases.\n\n"
            . "Pending: `wordpress-bridge/commands/pending/<id>.json`  \nResults: `wordpress-bridge/results/<id>.json`  \nCompleted: `wordpress-bridge/commands/completed/<id>.json`\n";
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
