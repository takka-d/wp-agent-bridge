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
        $preparer = self::media_preparer($repository, $branch, $host);
        if (is_wp_error($preparer)) return;
        $signature = $version . ':' . hash('sha256', self::agents($repository, $branch, $host, $version) . "\n" . self::runtime($repository, $branch, $host, $version) . "\n" . $preparer);
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
        $preparer = self::media_preparer($repository, $branch, $host);
        if (is_wp_error($preparer)) return $preparer;
        $results = [];
        foreach ([
            'AGENTS.md' => $agents,
            'wordpress-bridge/WEBHOOK_RUNTIME.md' => $runtime,
            'wordpress-bridge/prepare-media.py' => $preparer,
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

    private static function media_preparer(string $repository, string $branch, string $host)
    {
        $source = file_get_contents(dirname(__DIR__) . '/client/prepare-media.py.txt');
        if (!is_string($source) || $source === '') {
            return new WP_Error('wpab_media_preparer_missing', 'The bundled client media preparer is missing.', ['status' => 500]);
        }
        return '# Runtime binding: ' . wp_json_encode(['repository' => $repository, 'runtime_branch' => $branch, 'site_host' => $host]) . "\n" . $source;
    }

    public static function client_prompt(string $repository, string $branch, string $host): string
    {
        return "WordPress操作には、GitHubプラグイン経由でサイト {$host} のWP Agent Bridgeを使用してください。\n"
            . "接続先repository: {$repository}\nbranch: {$branch}\n"
            . "最初に wordpress-bridge/RUNTIME_CONNECTION.json を読み、site_hostとrepositoryが上記と一致し、status=canonical、transport=direct-github-webhook、ownership=user-owned、operator_relay=false を確認してください。続いて AGENTS.md と wordpress-bridge/RUNTIME_CAPABILITIES.json を読み、今回の依頼に必要な操作を選んでください。確認済みの接続と機能一覧は同じ会話内で再利用してください。\n"
            . "GitHubツールがすでに使えるならそのまま使用してください。表示されていない場合は、この会話に利用可能なツール検索・プラグイン検索でGitHubの読み書きツールを一度確認してください。検索手段もツールも提供されていない場合は、会話側でGitHubを選択する必要があると具体的に説明してください。ツール未提供、リポジトリへのアクセス拒否、画像の元ファイル未取得、Bridgeの操作エラーを区別し、未確認の理由を断定しないでください。\n"
            . "既存画像のアップロード依頼では、採用済み画像を使って登録してください。画像の再生成・編集は、それを依頼された場合だけ行ってください。元ファイルを取得できない場合は画像取得の問題として報告し、Bridgeのアップロード機能がないとは扱わないでください。\n"
            . "文章の修正と画像登録は同じGitHubのJSON書き込み経路を使います。GitHubの画像専用ツールやローカルファイル引数は不要です。元画像を読める実行ツールがある場合は、runtime内の wordpress-bridge/prepare-media.py で転送データを生成し、出力されたconnector-safe chunk commandをchunk_index順にGitHubへ渡してください。1つのtool argumentへ画像全体のBase64を入れたり、複数chunkを連結したりしてはなりません。Base64を回答から書き写さないでください。画像を取得する手段が未確認のまま、GitHub全体が使えないと結論しないでください。\n"
            . "この指示だけでは記事の変更やアップロードを実行せず、接続確認を行ってください。具体的な操作は併記された依頼または続く依頼の範囲で実行してください。";
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
            . "## Client tool availability\n\n"
            . "- WP Agent Bridge is the WordPress component; the client invokes the GitHub plugin. Use visible GitHub read/write tools directly. If absent, use an available tool/plugin discovery facility once for GitHub before reporting it unavailable. Do not repeat discovery for tools already visible.\n"
            . "- A WordPress connection marked Connected does not prove tools are exposed in this chat. If neither tools nor discovery are supplied, explain that client-side limit and suggest selecting the existing GitHub plugin with @GitHub. Typing the name alone is not selecting the plugin. Never infer Bridge failure or ask for reconnection solely from missing client tools.\n"
            . "- Distinguish client tool absence, repository access denial, unavailable original image bytes, and an actual Bridge operation error. Report the observed evidence and leave untested layers unverified.\n\n"
            . "- Text edits and image uploads use the same GitHub text/JSON write tools. A missing binary-file argument or dedicated image tool is not missing GitHub access. A recent text mutation is evidence that this transport worked, not proof that the original image bytes are readable. Check these two conditions separately with available tools; never turn an asset-read failure into a blanket connector-unavailable claim.\n\n"
            . "## Routing rules\n\n"
            . "- Preferred pending JSON: `{\"id\":\"<unique-id>\",\"created_at\":\"<UTC ISO-8601>\",\"type\":\"operation\",\"operation\":\"post.get\",\"params\":{\"post_id\":123}}`. The filename is `<unique-id>.json`. Include `created_at` so recovery can distinguish a current command from an abandoned one. If it is absent, recovery reads the exact pending file's latest GitHub commit timestamp; only when age cannot be established from either source is it left unknown. This reaches `/takka-v099/v1/operate` without reconstructing a route or method; the explicit REST envelope remains available for compatibility.\n"
            . "- For a low-level action not in the operation catalog, use `type=bridge` with `action` and `params`. Never send `/takka-bridge/v1/execute` as a REST target; that is a recursive proxy, not a direct action.\n"
            . "- Create an article or page: post.create with fields (title/content) and optional post_type=page. It defaults to draft; explicit publish/schedule needs confirm_live. Reuse the command ID on uncertain outcomes. Creation returns the ID, type, status and URL without echoing full content.\n"
            . "- Post/page metadata/status/featured image: `post.get` and `post.update`. The Bridge selects the posts/pages route from the actual object type; post_type, if supplied, must agree. `post.get` is metadata-oriented and bounded by default; do not request `content` through it. `post.update` must not replace `content`.\n"
            . "- When the user supplies a post/page URL, preserve it as params.target_url for post.get, post.update, and post.content.*. Omit post_id unless already verified; the Bridge resolves it locally. If both are supplied they must identify the same object. A target conflict must not be bypassed by dropping or replacing the URL. For a title-only request use target_title (exact title, unique match required), optionally post_type=post or page. Duplicate or non-exact titles are rejected. Use post.find with search to return bounded candidates, then choose the user-intended URL; never silently select the first candidate.\n"
            . "- Existing uploads JSON: uploads.json.read → uploads.json.write_preview → uploads.json.write_apply. Supply uploads-relative path and exact JSON source string content (max 1 MiB). Apply requires confirm=true plus preview expected_before_sha256 and expected_plan_hash. The previous version is readable with version=previous and restored through the same guarded flow. Do not relocate JSON to a theme or change its consuming PHP just to edit data. Never claim JSON is unsupported merely because theme.file.write cannot reach uploads.\n"
            . "- Post content: `post.content.search`, `post.content.read_range`, guarded `post.content.patch_preview` → `post.content.patch_apply`. Avoid full-post rewrite when a targeted patch is sufficient.\n"
            . "- Theme ranges accept start_line with max_lines (up to 500), or inclusive end_line; when both are supplied the smaller range wins. Theme searches return bounded UTF-8 excerpts, including context lines, rather than whole minified JSON lines. Use pattern to narrow files. Inspect matches_truncated separately from excerpts_truncated. A search hit is a location hint, not a full JSON audit or replacement source.\n"
            . "- Local/conversation media: always use `wordpress-bridge/prepare-media.py` connector-safe chunk commands. The preparer splits the original bytes into 8192-byte decoded chunks so each pending-command JSON stays below 16384 bytes. Do not use one `media.upload.inline` command for a conversation attachment, even below 1 MiB, because client/tool payload truncation can corrupt Base64 before WordPress receives it.\n"
            . "- Upload an existing approved image without invoking image generation or editing unless the user requests that change. Recover its original bytes through available conversation/file tools. If those bytes are inaccessible, report that asset-access limit separately from Bridge media support; never substitute an older image or a screenshot.\n"
            . "- Client media preparation: read `wordpress-bridge/prepare-media.py` once and use its prepare_file() in an available local/file execution tool. It preserves bytes, calculates whole-file and per-chunk SHA-256, and returns exact connector-safe pending-command strings. Publish them in ascending chunk_index order; use at most 20 chunk commands in one Git push. Never concatenate Base64 chunks or rewrite them through model prose. No GitHub credentials or network are needed by the preparer. If this chat cannot pass file bytes into tool arguments, name that exact transfer limit rather than claim WordPress/GitHub is unavailable. Do not assume a file ID or sandbox path is a downloadable URL.\n"
            . "- Prepared media is not uploaded media. Check matching chunk result IDs before resubmitting. Publish generated pending commands in ascending chunk_index order, preserving each exact command ID and payload; one create_file per chunk is valid, and an atomic Git push must contain at most 20 pending commands. The upload finalizes automatically when all chunks have arrived. Only a successful finalized media result supplies the attachment ID for the separately guarded article patch.\n"
            . "- Media transport is size-independent up to the 6 MiB Bridge limit: split original binary before Base64 into connector-safe 8192-byte chunks, send each chunk through `/wp-agent-bridge-media/v1/upload-chunk`, and let WordPress verify per-chunk plus whole-file integrity before sideloading. On invalid/truncated Base64, do not repeat the same inline command.\n"
            . "- Two or more independent reads supported by the batch catalog: one `readonly.batch`. Check the catalog once before grouping; `post.get` is not currently a batch item and must be submitted separately. Nested items may use the same high-level operation names (`post.content.read_range`, `workspace.file.read_range`, etc.); the router normalizes them to the strict read-only action map. Do not hand-derive another action spelling when a documented alias exists.\n"
            . "- Large iterative HTML/JS/CSS/JSON/POV-Ray work: Workspace search/range/patch/snapshot. Do not rediscover the artifact from File Library/history on every continuation.\n"
            . "- For ordinary WordPress work, submit native runtime commands through the connected GitHub tools. Do not create or run local helper scripts just to wrap a known operation. Reading original media bytes and running the bundled media preparer is necessary asset preparation and is explicitly allowed by this rule; it is not image generation. When local asset generation/testing is necessary, reuse the existing project entrypoint; numbered replacement runners add setup and approval churn without improving the WordPress operation. Client-side approval policy remains outside Bridge control.\n"
            . "- Self-update: use `self_update.status/apply/rollback` operations and pinned official source metadata; verify after apply.\n\n"
            . "- Before changing a user-visible target, verify its identity against the requested page/post/media and the affected content or selector. A matching file hash or successful write proves storage, not the intended visual result. Verify the affected output; if rendering is unavailable, explicitly leave visual correctness unverified.\n\n"
            . "## Failure rules\n\n"
            . "- Keep one command ID and identical payload when checking/recovering an uncertain outcome. A new ID means a new operation; do not generate a fresh ID to retry an unconfirmed mutation.\n"
            . "- Check the result `ok` and operation status, not just successful GitHub reads or outer HTTP 200. A 207 read batch is incomplete: inspect its failed items and do not rerun successful items.\n"
            . "- Result observation is separate from command execution. After submission, use independent work or a short wait so the first exact-ID read is at least 5 seconds later. If unavailable, make at most one second observation at least 10 seconds later, honoring a stricter user retry limit. Two missing reads mean pending_or_unknown, not execution failure or proof that no result was generated. Record observed GitHub status and elapsed time; do not claim generation delay without evidence. Never resubmit or switch transport on this evidence alone. A 401/403 is an access error; stop unchanged retries.\n"
            . "- Read the first 80 lines of the matching result when bounded GitHub file reads are available. Its outcome header reports succeeded/partial/failed, status and reason before large command/output data. A header excerpt need not be a complete JSON document. Fetch the needed output after checking the header; full-read size/truncation errors mean retrieval_error, not execution failure. Older results without outcome require inspecting result.ok and nested operation status.\n"
            . "- Batch output omission is not necessarily execution failure: result_omitted with execution_ok=true means the read ran but its output exceeded the return budget. failed_count counts unsatisfied items; execution_failed_count, result_omitted_count and not_started_count distinguish the reasons. Use outcome.failed_indexes/omitted_indexes/not_started_indexes to narrow further reads; preserve successful items.\n"
            . "- Fetch the exact `wordpress-bridge/results/<id>.json` path. A missing result is pending/unknown, not proof of failure. Record submission-to-result time separately from `duration_ms`. Results include source_commit and timing for command loading and execution; timing.excludes lists unmeasured phases. Compare source/result commit timestamps only as second-resolution commit-creation evidence, not exact visibility. Never describe execution time as total user wait. Avoid fixed long sleeps: do independent work while pending and use bounded result checks without resubmitting mutations. Automatic recovery first uses command `created_at` or an ID timestamp, then falls back to the exact pending file's latest GitHub commit timestamp. Only when all age evidence is unavailable does it skip without execution; a pending file older than 86400 seconds is quarantined without executing it.\n"
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
            . "Pending recovery age uses command metadata/ID first and the exact pending file's latest GitHub commit timestamp as a fallback; only when both are unavailable is execution skipped.\n\n"
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
