# WP Agent Bridge Public Release Checklist

## Current candidate

- Version: `1.1.5`
- Status: **release candidate / validation in progress**
- Source includes merged local-media routing fix: `766a8dc468d36aff7cae7118e6f7dea310f0e866`
- Reproducible plugin ZIP SHA-256: **pending merged-main packaging**
- External-test prerelease: **pending**
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

For 1.1.5, generated runtime guidance must also make media source selection explicit:

- ChatGPT-local / conversation-uploaded / sandbox files -> `/wp-agent-bridge-media/v1/upload-chunk` through normal runtime commands;
- media already manageable by the GitHub connector -> staged `wordpress-bridge/media/pending/*.b64` transport remains available.

The local-file path must not waste time trying to copy a sandbox file into GitHub payload files when the connector has no local-file parameter.

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

### ChatGPT-local media

1. Compute whole-file byte count and SHA-256 from the original local binary.
2. Split the binary into ordered chunks; maximum 1,200,000 decoded bytes/chunk, 32 chunks, 6 MiB total.
3. Base64-encode each chunk independently and include per-chunk bytes/SHA-256 plus whole-file integrity fields.
4. Send each chunk sequentially to `/wp-agent-bridge-media/v1/upload-chunk` through normal runtime REST commands.
5. Wait for command completion before the next chunk.
6. Final chunk reconstruction must verify the whole file before attachment creation and clean WordPress-side temporary staging.

### GitHub-staged media

1. Compute expected byte count and SHA-256 from the complete source binary.
2. Split the original binary first.
3. Base64-encode each binary chunk independently.
4. Verify staged blobs before moving the runtime branch.
5. Publish verified payload files plus upload command in one Git tree/commit/ref update when available.
6. WordPress reconstructs and verifies before attachment creation.
7. Successful upload removes temporary payloads in one Git tree cleanup commit with bounded retry.

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

## 1.1.5 regression reason

The GitHub connector used by ChatGPT can write text/blob data to repositories but does not expose an arbitrary local/sandbox file-reference parameter for repository writes. Previous generated runtime guidance treated GitHub staged media as the preferred path even when the source existed only inside the ChatGPT conversation/sandbox. That caused avoidable local-file-to-Base64-to-GitHub staging work and could leave image workflows appearing stuck.

1.1.5 makes the existing authenticated chunk route the preferred transport for those local sources while retaining the staged GitHub media path for sources that are already GitHub-manageable.

## CI / packaging gates

- [ ] PHP syntax checks pass for the final 1.1.5 candidate.
- [ ] release metadata is internally consistent at 1.1.5.
- [ ] obvious-secret/development-payload checks pass.
- [ ] Direct Runtime self-webhook loop regression passes.
- [ ] media transport regression passes with source-aware routing assertions.
- [ ] clean WordPress package test passes.
- [ ] runtime idempotency test passes.
- [ ] self-update safety test passes.
- [ ] self-contained runtime test passes.
- [ ] self-contained migration safety test passes.
- [ ] external tester kit workflow passes.
- [ ] deterministic 1.1.5 plugin ZIP is rebuilt and verified.
- [ ] merged-main 1.1.5 plugin ZIP SHA-256 is recorded.
- [ ] 1.1.5 external-test prerelease is created without altering `v1.1.4-rc1`.

## TakKa Note live validation

- [x] canonical runtime marker remains `status=canonical`, `transport=direct-github-webhook`, `ownership=user-owned`, `operator_relay=false`.
- [x] current live `AGENTS.md` already directs ChatGPT-local media to authenticated chunk upload and keeps GitHub-staged media as a separate path.
- [ ] TakKa Note plugin is updated from 1.1.4 to the reproducible 1.1.5 package.
- [ ] runtime identity sync after the 1.1.5 update preserves the local-media routing guidance.
- [ ] a ChatGPT-local image is uploaded through the chunk route on TakKa Note and whole-file integrity is confirmed.
- [ ] validation attachment/temp data is removed after verification.

## Distribution state

1.1.5 is being prepared specifically so fresh installations and future runtime-identity syncs retain the already-adopted local-media routing fix. External tester readiness will be declared only after merged-main packaging and live validation.

Broader public/stable release remains undeclared.

## License

The distribution uses `WP Agent Bridge License 1.0`: free download/install/use and private modification are permitted; redistribution of original or modified copies requires prior written permission. It is not an open-source/GPL-compatible license and is not intended for WordPress.org Plugin Directory distribution under the current license.