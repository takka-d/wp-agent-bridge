---
name: wp-agent-bridge
description: Use when the user asks ChatGPT or Codex to inspect or change WordPress through WP Agent Bridge and a user-owned GitHub Direct Runtime, including continuation of long-running WordPress tasks.
---

# WP Agent Bridge workflow

Use WP Agent Bridge for WordPress work when the user's site is connected through its user-owned GitHub Direct Runtime.

## Non-negotiable execution contract

When this skill is invoked, or when the user explicitly names WP Agent Bridge:

1. Do not answer with a tool-availability explanation before attempting the required GitHub dependency path.
2. Do not use the model's apparent/visible tool roster as evidence that GitHub or WP Agent Bridge is unavailable.
3. Do not say "this turn cannot use WP Agent Bridge", "GitHub is not connected", "there is no executable tool", "WP Agent Bridge is unavailable", or equivalent unless an actual GitHub dependency/tool call or plugin/app discovery attempt has returned concrete failure evidence in this turn.
4. If the user supplied a concrete WordPress task, make the GitHub dependency/tool attempt in the same response. Do not stop after describing what would be done.
5. If the GitHub dependency becomes available after discovery, immediately continue the original WordPress task. Do not ask the user to repeat the task, start a new chat, or invoke the helper again.
6. If WP Agent Bridge or GitHub worked earlier in the same conversation, treat that as positive evidence that the configured path exists. A later missing tool surface is a client exposure problem until an actual call proves otherwise.
7. Never claim success merely because the helper skill is active. Success requires the matching WP Agent Bridge result file and operation outcome.

The GitHub app declared in this plugin is required. Invocation of this plugin is a request to use that dependency for the WordPress task, not merely to provide prose guidance.

## GitHub app/tool recovery

Follow this order exactly:

1. If GitHub read/write actions are directly available, use them.
2. Otherwise invoke the available app/plugin/tool discovery mechanism once for GitHub.
3. If discovery reports GitHub installed/enabled/available, use the discovered GitHub actions immediately. Do not convert "installed but not yet surfaced" into "not connected".
4. If a direct GitHub action returns an authorization, workspace-policy, repository-access, or provider-connection error, report that exact error category.
5. Only if both the dependency/tool invocation path and the available discovery path fail or explicitly report unavailability may you state that the current client turn cannot execute the GitHub step.
6. A missing item in an internally visible tool list, without an attempted call/discovery, is never sufficient evidence of unavailability.
7. Never ask the user to reconnect WordPress or rebuild the WP Agent Bridge GitHub App solely because the ChatGPT/Codex tool surface changed.
8. Selecting or invoking WP Agent Bridge Helper should be sufficient to request its required GitHub app. Asking the user to separately select @GitHub is a last-resort client workaround only after the dependency path was actually attempted and could not be surfaced.

## Runtime binding and capability resolution

1. Reuse a runtime repository and branch already verified in the current task or conversation.
2. A known repository/branch binding is sufficient for normal command publication. Do not require `wordpress-bridge/RUNTIME_CONNECTION.json` as a startup/preflight gate.
3. Read `wordpress-bridge/RUNTIME_CONNECTION.json` only after a reconnect/migration signal, a mapping contradiction, or an actual pending-command publication failure.
4. Read `AGENTS.md` and `wordpress-bridge/RUNTIME_CAPABILITIES.json` when capability details are needed. Reuse the verified catalog instead of rereading it before every command.
5. A marker/bootstrap read 404 alone does not prove that GitHub writes or WP Agent Bridge are unavailable.

## Commands

- Prefer the operation catalog advertised in `RUNTIME_CAPABILITIES.json`.
- Submit normal work as a unique JSON file under `wordpress-bridge/commands/pending/<id>.json`.
- Keep `id` and `request_id` stable for one logical operation.
- Read the matching `wordpress-bridge/results/<id>.json` to determine the outcome.
- Do not stop after a connection check when the user asked for an actual WordPress change.
- Do not treat a successful GitHub write, webhook delivery, or outer HTTP 200 as proof that the WordPress operation succeeded.
- Do not resubmit a mutating operation with a new ID merely because the result is delayed.

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

- Use the preview/plan/apply guard advertised for the operation.
- For post metadata, obtain or reuse the current concurrency snapshot and send the documented expected field hashes.
- For content/theme patches, never convert approximate or normalized diagnostic candidates directly into writes. Produce a new exact preview first.
- Preserve unrelated changes from other chats and stop on a stale same-field or stale-plan conflict.

## Media

For a local or conversation attachment, use the runtime's `wordpress-bridge/prepare-media.py`. Publish its connector-safe chunk commands in ascending chunk order. Do not put the whole attachment's Base64 into one GitHub tool argument and do not concatenate generated chunks.

## Do not fall back to retired paths

Do not use the old operator relay, old Bridge Key, a legacy operator-owned runtime repository, or a per-command GitHub Actions worker. Do not switch to browser-side WordPress tooling unless the task explicitly requires browser DOM/event behavior that the server-side Bridge cannot perform.
