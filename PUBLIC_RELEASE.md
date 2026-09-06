# WP Agent Bridge Public Release Checklist

## Current candidate

- Version: `1.1.5`
- Status: **release candidate / validation in progress**
- Source includes the merged v0.9.6 Site Icon / media / theme-file compatibility work: `6d00ad31cf556c4efa571abb8f06917e86b24c45`
- Reproducible plugin ZIP SHA-256: **pending merged-main packaging**
- External-test prerelease: **pending refresh**
- Broader public/stable release: **not declared**

The existing `v1.1.4-rc1` prerelease remains an immutable 1.1.4 test artifact and must not be overwritten with 1.1.5 bytes.

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

Generated runtime guidance for the current 1.1.5 candidate must also describe the v0.9.6 compatibility surfaces:

- ChatGPT-local / conversation-uploaded / sandbox / connector-downloaded files -> prefer one batched staged-media upload using `wordpress-bridge/media/pending/*.b64` plus `/wp-agent-bridge-runtime/v1/media-upload` when the GitHub connector can write UTF-8 text/blobs;
- a GitHub local-file parameter is **not** required: split the original binary, Base64-encode bounded chunks independently, stage those strings as text payloads, then submit one command with ordered `data_paths`;
- `/wp-agent-bridge-media/v1/upload-chunk` remains a sequential fallback only when the batched staged-media path cannot be used reliably or actually fails;
- `site.icon.get`, `site.icon.set`, `site.icon.clear`, and `media.upload.capabilities` are the dedicated Site Icon/media capability surface;
- upload-and-Site-Icon assignment may be performed in one media-upload command with `set_site_icon=true` and `confirm_site_icon=true`;
- `theme.files.list`, `theme.files.search`, and `theme.file.read.many` close the repeated single-file read gap for server-side theme inspection;
- files first obtained from Google Drive or another connector are treated as local bytes after retrieval; WordPress itself does not require connector credentials.

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

### ChatGPT-local / conversation / connector-downloaded media — preferred batched path

1. Compute whole-file byte count and SHA-256 from the original local binary.
2. Split the original binary into ordered bounded chunks before Base64 encoding; keep the decoded total within 6 MiB and use at most 32 chunks.
3. Base64-encode each binary chunk independently and stage each Base64 string as UTF-8 text under `wordpress-bridge/media/pending/`.
4. Build one `/wp-agent-bridge-runtime/v1/media-upload` command with `filename`, whole-file `expected_bytes`, whole-file `expected_sha256`, and ordered `data_paths`.
5. When Git Data operations are available, create the payload blobs and command blob first and publish them in one tree/commit/ref update.
6. When only ordinary file writes are available, stage all payload files first and create the single pending media-upload command last.
7. Wait once for the upload result; WordPress reconstructs and verifies the complete file before attachment creation and removes staged payloads with bounded cleanup.
8. When the same image should become the Site Icon, include `set_site_icon=true`, `confirm_site_icon=true`, and optionally `expected_site_icon_id` in the same upload command.

### Sequential chunk-command fallback

Use `/wp-agent-bridge-media/v1/upload-chunk` only when the connector cannot reliably stage bounded Base64 text payloads/blobs for the batched route or the batched staged-media write actually fails. The fallback still requires whole-file and per-chunk integrity validation and ordered sequential commands.

### Existing GitHub-staged media

Media already manageable by the GitHub connector uses the same batched `wordpress-bridge/media/pending/*.b64` + `/wp-agent-bridge-runtime/v1/media-upload` route. Compute whole-file integrity first, split the original binary before Base64-encoding each part, verify staged payloads, publish payloads plus command atomically when possible, and let WordPress verify and clean them with bounded retry.

## Site Icon / favicon guards

The generic scalar option patcher is not used for `site_icon`. The dedicated v0.9.6 surface is required:

- `site.icon.get` reads the current attachment ID and metadata;
- `site.icon.set` requires explicit confirmation and supports `expected_current_id` stale-write protection;
- `site.icon.clear` requires explicit confirmation and supports `expected_current_id` without deleting the attachment;
- `media.upload.capabilities` describes supported runtime media workflows;
- media upload may explicitly request Site Icon assignment in the same operation.

A no-change production E2E should read the current Site Icon ID and set that same ID with stale-write protection rather than changing the visible favicon merely to prove the route works.

## Bounded theme inspection

The v0.9.6 server-side theme inspection surface must provide:

- `theme.files.list` for bounded file enumeration;
- `theme.files.search` for bounded substring/context search;
- `theme.file.read.many` for up to 20 bounded file ranges in one Bridge command.

These reads must remain theme-root constrained and must not become arbitrary filesystem reads.

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

## v0.9.6 regression reason

The previous release-candidate documentation and generated runtime guidance had two practical gaps despite the existing Bridge core:

1. ChatGPT-local or connector-downloaded images could be treated as if a GitHub local-file parameter were necessary, leading to needless detours or repeated sequential WordPress chunk commands.
2. Site Icon assignment and multi-file theme inspection could appear unsupported because the generic option patcher intentionally rejects scalar `site_icon` and the earlier theme API exposed single-file reads more prominently.

v0.9.6 adds explicit guarded surfaces and runtime guidance rather than weakening the generic safety guards. The preferred media flow now batches staged Base64 text payloads into one media-upload command when connector write capabilities permit it, with the sequential chunk-command route retained as a fallback.

## CI / packaging gates

- [x] PHP syntax / core CI checks pass for the merged v0.9.6 source.
- [x] release metadata remains internally consistent at 1.1.5.
- [x] Direct Runtime regression suite passes on the PR #32 final head.
- [x] Site Icon / media flow regression passes.
- [x] clean WordPress package test passes.
- [x] runtime idempotency test passes.
- [x] self-update safety test passes.
- [x] self-contained runtime test passes.
- [x] self-contained migration safety test passes.
- [x] external tester kit workflow passes on the PR #32 final head.
- [ ] deterministic 1.1.5 plugin ZIP is rebuilt from merged `main` including v0.9.6.
- [ ] merged-main 1.1.5 plugin ZIP SHA-256 is recorded.
- [ ] 1.1.5 external-test prerelease/test kit is refreshed without altering `v1.1.4-rc1`.

## TakKa Note live validation

- [ ] apply the merged v0.9.6 files to the current TakKa Note 1.1.5 installation through the temporary installer and confirm the installer result.
- [ ] `site.icon.get` succeeds in production.
- [ ] the currently configured Site Icon attachment ID can be sent back through `site.icon.set` with confirmation and stale-write protection without changing the favicon.
- [ ] `theme.files.list`, `theme.files.search`, and `theme.file.read.many` succeed in production.
- [ ] generated/runtime guidance reflects batched staged-media as the preferred local/connector path and the sequential chunk route as fallback.
- [ ] the temporary installer is removed and the child-theme `functions.php` is restored to its verified pre-installer bytes/SHA state.

## Distribution state

The functional v0.9.6 source is merged. Documentation, live installer validation, deterministic merged-main package generation, tester-kit refresh, and public article/download references are deliberately handled as separate follow-up stages so distribution artifacts do not get mixed into the functional patch.

Broader public/stable release remains undeclared.

## License

The distribution uses `WP Agent Bridge License 1.0`: free download/install/use and private modification are permitted; redistribution of original or modified copies requires prior written permission. It is not an open-source/GPL-compatible license and is not intended for WordPress.org Plugin Directory distribution under the current license.