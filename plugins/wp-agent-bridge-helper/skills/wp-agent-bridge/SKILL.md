---
name: wp-agent-bridge
description: Use when the user asks ChatGPT, Codex, or Claude to inspect or change WordPress through WP Agent Bridge and a user-owned GitHub Direct Runtime (private runtime repository, branch wp-agent-bridge-runtime), including continuation of long-running WordPress tasks and recovery after a tool surface changed.
---

# WP Agent Bridge workflow

Use WP Agent Bridge for WordPress work when the user's site is connected through its user-owned GitHub Direct Runtime. The WordPress side is the WP Agent Bridge plugin; the client side (ChatGPT, Codex, or Claude) only needs a GitHub read/write transport to one private repository.

This skill is client-neutral. The transport differs per client and is described in `references/transports.md`; the command/result contract below is identical everywhere.

## Non-negotiable execution contract

When this skill is invoked, or when the user explicitly names WP Agent Bridge:

1. Do not answer with a tool-availability explanation before attempting the required GitHub dependency path.
2. Do not use the model's apparent/visible tool roster as evidence that GitHub or WP Agent Bridge is unavailable.
3. Do not say "this turn cannot use WP Agent Bridge", "GitHub is not connected", "there is no executable tool", "WP Agent Bridge is unavailable", or equivalent unless an actual GitHub dependency/tool call or plugin/app discovery attempt has returned concrete failure evidence in this turn.
4. If the user supplied a concrete WordPress task, make the GitHub dependency/tool attempt in the same response. Do not stop after describing what would be done.
5. If the GitHub dependency becomes available after discovery, immediately continue the original WordPress task. Do not ask the user to repeat the task, start a new chat, or invoke the helper again.
6. If WP Agent Bridge or GitHub worked earlier in the same conversation, treat that as positive evidence that the configured path exists. A later missing tool surface is a client exposure problem until an actual call proves otherwise.
7. Never claim success merely because the helper skill is active. Success requires the matching WP Agent Bridge result file and operation outcome.

On ChatGPT/Codex the GitHub app declared in this plugin is required. On Claude the required dependency is whichever GitHub transport the session provides (see "GitHub transport by client"). Invocation of this plugin is a request to use that dependency for the WordPress task, not merely to provide prose guidance.

## GitHub app/tool recovery

Follow this order exactly:

1. If GitHub read/write actions are directly available, use them.
2. Otherwise invoke the available app/plugin/tool discovery mechanism once for GitHub (ChatGPT/Codex: app or plugin discovery; Claude: tool search, connector list, repository attach, or a shell with `gh`).
3. If discovery reports GitHub installed/enabled/available, use the discovered GitHub actions immediately. Do not convert "installed but not yet surfaced" into "not connected".
4. If a direct GitHub action returns an authorization, workspace-policy, repository-access, or provider-connection error, report that exact error category.
5. Only if both the dependency/tool invocation path and the available discovery path fail or explicitly report unavailability may you state that the current client turn cannot execute the GitHub step.
6. A missing item in an internally visible tool list, without an attempted call/discovery, is never sufficient evidence of unavailability.
7. Never ask the user to reconnect WordPress or rebuild the WP Agent Bridge GitHub App solely because the client tool surface changed.
8. Selecting or invoking WP Agent Bridge Helper should be sufficient to request its required GitHub dependency. Asking the user to separately select @GitHub (ChatGPT) or to add a connector (Claude) is a last-resort client workaround only after the dependency path was actually attempted and could not be surfaced.

## GitHub transport by client

Read `references/transports.md` once per conversation when you need the exact calls. Summary:

- ChatGPT / Codex (app): the required GitHub app; `create_file` / file read with explicit `branch`/`ref`.
- Codex CLI, Claude Code (terminal, desktop Code tab): the bundled `scripts/wpab.py` (GitHub REST through `gh api`, with an automatic `git push` fallback), or any GitHub MCP tool already configured. `wpab.py` is this plugin's deterministic GitHub transport for shell-capable clients; it only moves JSON files to and from the runtime branch and never changes command semantics.
- Claude Code on the web / cloud sessions: attach the runtime repository with the session's repository tool using push access, then use `scripts/wpab.py --git-dir <workspace dir>`. The session proxy refuses Contents-API writes (HTTP 403) while serving reads and `git push`; the script switches to git on its own, so that 403 is not an access failure.
- claude.ai / Cowork with the desktop app linked to the user's computer: run `scripts/wpab.py` (or `gh api`) through the device shell on the user's computer, where `gh auth` already holds the user's own GitHub login.
- claude.ai chat without any GitHub write tool (web/mobile only, no linked computer, no GitHub connector): say precisely which transport is missing and which of the above would supply it. That is a client transport limit, not a WP Agent Bridge failure.

Every runtime read and write must name the repository and set branch/ref `wp-agent-bridge-runtime` explicitly. Omitting ref reads the repository default branch, which only mirrors read-only bootstrap files.

## Runtime binding and capability resolution

1. Reuse a runtime repository and branch already verified in the current task or conversation, or the binding pasted from the WordPress admin screen (**Tools > WP Agent Bridge**).
2. A known repository/branch binding is sufficient for normal command publication. Do not require `wordpress-bridge/RUNTIME_CONNECTION.json` as a startup/preflight gate.
3. Read `wordpress-bridge/RUNTIME_CONNECTION.json` only after a reconnect/migration signal, a mapping contradiction, or an actual pending-command publication failure. It must show `status=canonical`, `transport=direct-github-webhook`, `ownership=user-owned`, `operator_relay=false`, and the expected `repository` / `site_host`.
4. Read `AGENTS.md` and `wordpress-bridge/RUNTIME_CAPABILITIES.json` when capability details are needed. Reuse the verified catalog instead of rereading it before every command.
5. A marker/bootstrap read 404 alone does not prove that GitHub writes or WP Agent Bridge are unavailable.

## Commands

- Prefer the operation catalog advertised in `RUNTIME_CAPABILITIES.json`.
- Submit normal work as a unique JSON file under `wordpress-bridge/commands/pending/<id>.json` on the runtime branch: `{"id":"<id>","created_at":"<UTC ISO-8601>","type":"operation","operation":"<operation>","params":{}}`. Use `type=bridge` with `action` only for a low-level action that is not in the catalog.
- Keep `id` and `request_id` stable for one logical operation. A new ID means a new operation; never generate a fresh ID to retry an unconfirmed mutation.
- Read the matching `wordpress-bridge/results/<id>.json` to determine the outcome. Check `outcome` / `ok` and the nested operation status, not the outer HTTP status.
- Do not stop after a connection check when the user asked for an actual WordPress change.
- Do not treat a successful GitHub write, webhook delivery, or outer HTTP 200 as proof that the WordPress operation succeeded.
- Do not resubmit a mutating operation with a new ID merely because the result is delayed.
- Result observation: make the first exact-ID read at least 5 seconds after submission; if the result is still missing, make at most one more read at least 10 seconds after submission. Two missing reads mean `pending_or_unknown`, not failure. Record the elapsed time you observed. `scripts/wpab.py result --wait` applies exactly this rule.
- Never write pending commands to the default branch, and never put `?query=` inside a REST `route`; use the `query` object or operation params.

## Failure classification

Keep these distinct and report only categories supported by observed evidence:

- GitHub app/tool not exposed in the current client turn after an actual dependency/discovery attempt.
- GitHub provider authorization or workspace restriction returned by the app/tool path.
- GitHub repository/branch/path access error returned by an actual GitHub operation.
- WP Agent Bridge transport error returned by the runtime.
- WordPress operation error returned by the Bridge result.
- Missing original media bytes.

Never convert one category into a blanket "WP Agent Bridge cannot be used" conclusion without evidence.

## Safe writes

- Use the preview/plan/apply guard advertised for the operation (`post.content.patch_preview` → `post.content.patch_apply`, `uploads.json.write_preview` → `uploads.json.write_apply`, theme patch preview/apply, `confirm=true` where documented).
- For post metadata, obtain or reuse the current concurrency snapshot and send the documented expected field hashes. `post.update` must not replace `content`.
- For content/theme patches, never convert approximate or normalized diagnostic candidates directly into writes. Produce a new exact preview first.
- Preserve unrelated changes from other chats and stop on a stale same-field or stale-plan conflict (409): re-read only the affected state, regenerate the preview, retry the guarded write once.
- When the user supplies a post/page URL, keep it as `params.target_url`; do not drop or replace it to bypass a target conflict. Use `post.find` with `search` for candidates and never silently pick the first one.
- Before changing a user-visible target, verify its identity against the requested page/post and the affected content. A matching hash proves storage, not the intended visual result; say explicitly when visual correctness is unverified.

## Media

For a local or conversation attachment, use the runtime's `wordpress-bridge/prepare-media.py` with the saved `RUNTIME_CONNECTION.json`. It splits the original bytes into 8192-byte chunks and emits one pending command per chunk with whole-file and per-chunk SHA-256. Publish those commands in ascending `chunk_index` order, at most 20 per Git push, preserving each exact command ID and payload. Do not put the whole attachment's Base64 into one GitHub tool argument, do not concatenate generated chunks, and do not retype Base64 through model prose. On shell-capable clients, `scripts/wpab.py publish-media <prepared-dir>` publishes the prepared directory with these rules. The upload finalizes automatically when all chunks have arrived; only the final chunk's successful result supplies the attachment ID for a separately guarded article patch.

Upload an existing approved image without regenerating or editing it unless the user asks for that change. If the original bytes cannot be read in this client, report that asset-access limit separately from Bridge media support.

## Do not fall back to retired paths

Do not use the old operator relay, old Bridge Key, a legacy operator-owned runtime repository, or a per-command GitHub Actions worker. Do not switch normal WordPress work to WPVibe or other browser-side/third-party WordPress tooling unless the task explicitly requires browser DOM/event behavior that the server-side Bridge cannot perform. Do not create ad-hoc local scripts to wrap a cataloged operation; the bundled `scripts/wpab.py` and the runtime's `prepare-media.py` are the only client-side helpers this workflow needs.

## Additional resources

- `references/transports.md`: exact GitHub calls per client, repository attach / device shell notes, and the Contents API / Git Data API recipes `wpab.py` implements.
- `scripts/wpab.py --help`: `connection`, `capabilities`, `submit`, `result`, `publish-media`.
