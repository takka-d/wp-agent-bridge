# WP Agent Bridge Public Release Checklist

## Current candidate

- Version: `1.1.4`
- Status: **release candidate / external tester distribution ready**
- Source merge commit: `dd6fbdb435cbee8c6d0e740724b8338c519f7201`
- Reproducible plugin ZIP SHA-256: `f63179ebe454b4ee42bf6b5bb1e3e23f935ee976b81ec8bc69e750828b1871c0`
- Merged-main external tester kit artifact ID: `9963218724`
- Artifact container SHA-256: `568172ea64b0a9ba7bbd05f8974c3c39091fc4e58f9503b22adbebb52e0fe35b`
- Artifact expiration: `2026-10-05T04:50:05Z`
- Broader public/stable release: **not declared**

The GitHub Actions artifact is an outer, temporary distribution container. The reproducibility guarantee applies to the inner `wp-agent-bridge-1.1.4.zip` plugin package identified by the plugin ZIP SHA-256 above.

## Release architecture

Normal end-user operation uses resources controlled by the end user:

`ChatGPT -> user's private runtime repository -> user's site-specific GitHub App signed Webhook -> user's WordPress -> user's runtime repository -> ChatGPT`

Normal operation must not require an operator-owned runtime Organization, operator-owned private repository, operator relay server, per-command GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.

The public package contains the self-contained WP Agent Bridge plugin and its package documentation/license. It excludes operator runtime data, runtime command/result/media payloads, unrelated project data, and secrets.

## Runtime identity requirements

A connected runtime repository must identify itself with `wordpress-bridge/RUNTIME_CONNECTION.json` containing at least:

- `status: canonical`
- `transport: direct-github-webhook`
- `ownership: user-owned`
- `operator_relay: false`
- the actual repository name
- `runtime_branch: wp-agent-bridge-runtime`
- the target `site_host`

`AGENTS.md` and `wordpress-bridge/WEBHOOK_RUNTIME.md` must describe the same architecture.

## Delivery / replay safety

Required behaviors:

- unique `id` and `request_id` for normal commands;
- identical completed request ID + identical payload replays the stored response;
- same request ID + different payload returns conflict;
- valid pushes reconcile the current `commands/pending/` directory;
- self-generated result/completed/media bookkeeping does not recursively redispatch the command that created it;
- authenticated runtime push handling is serialized before the primary executor so recovery cannot overlap an already-running command and persist a temporary `idempotency_in_progress` response as terminal bookkeeping;
- result/completed/pending bookkeeping recovery does not duplicate WordPress side effects.

## Media transport

WordPress decoded-media limit: 6 MiB.

For the self-contained GitHub media path:

1. Compute expected byte count and SHA-256 from the complete source binary.
2. Split the **original binary** first.
3. Base64-encode each binary chunk independently.
4. Keep each `.b64` payload at or below 8,000 Base64 characters for the preferred Git Data staging path.
5. Read each staged blob back by blob SHA and verify the exact staged text before moving the runtime branch.
6. Publish all verified payload files plus the upload command in one Git tree/commit/ref update when Git Data operations are available.
7. WordPress strict-decodes each payload independently, concatenates the binary chunks in order, then checks full `expected_bytes` and `expected_sha256` before attachment creation.
8. Successful upload removes all temporary payload files in one Git tree cleanup commit with bounded retry after branch movement.

The bounded authenticated chunk REST route remains a fallback.

## Self-update safety

Bridge self-update must:

- require a full manifest;
- reject destructive partial manifests;
- verify per-file and manifest SHA-256;
- parse-check PHP before replacement;
- verify required literal bootstrap dependencies;
- require explicit deletion paths and confirmation for removed existing files;
- capture a backup before replacement;
- restore the previous plugin if replacement fails.

## 1.1.4 regression reason

Two concrete failures observed during TakKa Note validation led to 1.1.4:

1. A historical media request expected 41,946 bytes, but the GitHub runtime payload itself was already truncated before WordPress read it. WordPress therefore reconstructed only 13,429 bytes. 1.1.4 hardens the staging contract with binary-first chunking, small independent Base64 payloads, and pre-publish blob read-back verification.
2. Concurrent authenticated runtime pushes could let recovery overlap a still-running primary command. The duplicate request correctly returned `takka_bridge_idempotency_in_progress`, but the legacy primary path could persist that temporary response as terminal result/completed bookkeeping. 1.1.4 serializes runtime push processing before primary/recovery dispatch.

## CI / packaging gates

- [x] PHP syntax checks pass.
- [x] release metadata is internally consistent at 1.1.4.
- [x] obvious-secret/development-payload checks pass.
- [x] Direct Runtime self-webhook loop regression passes.
- [x] 41,946-byte binary-first media staging regression passes.
- [x] clean WordPress package test passes.
- [x] runtime idempotency test passes.
- [x] self-update safety test passes.
- [x] self-contained runtime test passes.
- [x] self-contained migration safety test passes.
- [x] external tester kit workflow passes.
- [x] deterministic ZIP is rebuilt twice across a wall-clock delay and compared byte-for-byte.
- [x] merged-main package output is `wp-agent-bridge-1.1.4.zip` with SHA-256 `f63179ebe454b4ee42bf6b5bb1e3e23f935ee976b81ec8bc69e750828b1871c0`.

## TakKa Note live validation

- [x] canonical runtime marker remains `status=canonical`, `transport=direct-github-webhook`, `ownership=user-owned`, `operator_relay=false`.
- [x] TakKa Note was updated to WP Agent Bridge 1.1.4 from the reproducible merged-main package.
- [x] installed version reported `1.1.4` and the updater verified the exact plugin ZIP SHA-256 `f63179ebe454b4ee42bf6b5bb1e3e23f935ee976b81ec8bc69e750828b1871c0` before replacement.
- [x] the temporary update route added to the active child theme was removed immediately after installation; `functions.php` returned to its exact pre-update SHA-256.
- [x] an 8-payload live media request reconstructed a 16,596-byte WebP exactly.
- [x] reconstructed media SHA-256 matched `8e83796467ccabeb224c43f83dfc6c32f326e3e1f83b78c3a10b78497b0b4d0c`.
- [x] Media Library attachment creation succeeded.
- [x] source payload cleanup succeeded in one Git tree commit on the first attempt.
- [x] the matching pending command disappeared and completed command was written.
- [x] `wordpress-bridge/media/pending/` returned to `.gitkeep` only.
- [x] the live-test Media attachment was force-deleted after verification.

## Distribution state

1.1.4 has passed the current external-tester gates. The current tester kit is a **temporary GitHub Actions artifact**, not a permanent public download endpoint. The repository currently still has the historical `v1.0.0-rc1` prerelease as its only GitHub Release.

Therefore:

- **external tester readiness is complete**;
- **broader public/stable release is not yet declared**;
- a permanent 1.1.4 public download endpoint must be established separately before a TakKa Note article links ordinary visitors directly to a test ZIP.

## License

The distribution uses `WP Agent Bridge License 1.0`: free download/install/use and private modification are permitted; redistribution of original or modified copies requires prior written permission. It is not an open-source/GPL-compatible license and is not intended for WordPress.org Plugin Directory distribution under the current license.
