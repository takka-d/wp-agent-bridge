---
name: wp-agent-bridge
description: Use when the user asks Codex to inspect or change WordPress through WP Agent Bridge and a user-owned GitHub Direct Runtime.
---

# WP Agent Bridge workflow for Codex

Use WP Agent Bridge for WordPress work when the user's site is connected through its user-owned GitHub Direct Runtime.

## Connection and capability resolution

1. Reuse a runtime repository/branch already verified in the current task. Do not repeatedly rediscover it.
2. When verification is needed, inspect `wordpress-bridge/RUNTIME_CONNECTION.json`.
3. Treat the runtime as canonical only when:
   - `status=canonical`
   - `transport=direct-github-webhook`
   - `ownership=user-owned`
   - `operator_relay=false`
   - the repository, runtime branch, and requested site match.
4. Read `AGENTS.md` and `wordpress-bridge/RUNTIME_CAPABILITIES.json` once for the verified connection/version and follow those current instructions instead of reconstructing old routes from memory.

## Commands

- Prefer the operation catalog advertised in `RUNTIME_CAPABILITIES.json`.
- Submit normal work as a unique JSON file under `wordpress-bridge/commands/pending/<id>.json`.
- Keep `id` and `request_id` identical and stable for one logical operation.
- Read the matching `wordpress-bridge/results/<id>.json` to determine the outcome.
- Do not treat a successful GitHub write, webhook delivery, or outer HTTP 200 as proof that the WordPress operation succeeded.
- Do not resubmit a mutating operation with a new ID merely because the result is delayed.

## Safe writes

- Use the preview/plan/apply guard advertised for the operation.
- For post metadata, obtain or reuse the current concurrency snapshot and send the documented expected field hashes.
- For content/theme patches, never convert approximate or normalized diagnostic candidates directly into writes. Produce a new exact preview first.
- Preserve unrelated changes from other chats and stop on a stale same-field or stale-plan conflict.

## Media

For a local or conversation attachment, use the runtime's `wordpress-bridge/prepare-media.py`. Publish its connector-safe chunk commands in ascending chunk order. Do not put the whole attachment's Base64 into one GitHub tool argument and do not concatenate generated chunks.

## Do not fall back to retired paths

Do not use the old operator relay, old Bridge Key, `legacy operator-owned runtime repository`, or a per-command GitHub Actions worker. Do not switch to browser-side WordPress tooling unless the task explicitly requires browser DOM/event behavior that the server-side Bridge cannot perform.
