---
name: wp-agent-bridge
description: Use when the user asks ChatGPT or Codex to inspect or change WordPress through WP Agent Bridge and a user-owned GitHub Direct Runtime, including continuation of long-running WordPress tasks.
---

# WP Agent Bridge workflow

Use WP Agent Bridge for WordPress work when the user's site is connected through its user-owned GitHub Direct Runtime.

## Core rule

If the user says to use WP Agent Bridge, or the current conversation has already used WP Agent Bridge successfully, do not switch to another WordPress transport merely because GitHub tools are not visible on the first pass.

A concrete WordPress request should proceed through the canonical runtime whenever the required GitHub app/tools are available.

## GitHub app/tool recovery

1. If GitHub read/write tools are already visible, use them directly.
2. If GitHub tools are not visible, use the available app/plugin/tool discovery facility once to locate the standard GitHub app before claiming it is unavailable.
3. If GitHub worked earlier in the same conversation and later disappears, treat that as a client-side tool-exposure change. It is not evidence that WP Agent Bridge, WordPress, or the runtime repository failed.
4. Do not say "this chat cannot execute WP Agent Bridge", "WP Agent Bridge is unavailable", or equivalent until the GitHub rediscovery attempt has actually been made.
5. If discovery confirms that GitHub is installed/enabled but the current surface still exposes no usable GitHub actions, report that exact client-side limitation. Only then ask the user to reselect the GitHub app/plugin with `@GitHub` or the app picker where available.
6. Never ask the user to reconnect WordPress or rebuild the WP Agent Bridge GitHub App solely because the ChatGPT/Codex tool list changed.

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

Keep these distinct:

- GitHub app/tool not exposed in the current client turn.
- GitHub provider authorization or workspace restriction.
- GitHub repository/branch/path access error.
- WP Agent Bridge transport error.
- WordPress operation error.
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
