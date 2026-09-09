# WP Agent Bridge Public Release Checklist

## Candidate and evidence

Read the development version from plugin/wp-agent-bridge/takka-wordpress-bridge.php and the pinned source/manifest from UPDATE_MANIFEST.json. The Stable tag in plugin/wp-agent-bridge/readme.txt and published release artifacts describe the public distribution. Do not infer a stable release from a merge or a successful production self-update. Never replace an old release artifact with new bytes.

A release assessment must name the exact commit, artifact hash, test results, live scenarios, and known limitations. Historical green checks are not current evidence.

## Architecture and identity

Normal operation uses the user's private runtime repository and site-specific signed GitHub App webhook. No operator relay, per-command Actions worker, old Bridge Key, takka-d/chatgpt-data, or WPVibe is the normal path.

Verify wordpress-bridge/RUNTIME_CONNECTION.json: status=canonical, transport=direct-github-webhook, ownership=user-owned, operator_relay=false, and matching repository/branch/site_host. Reuse the catalog for the verified connection/version. Generated AGENTS.md, WEBHOOK_RUNTIME.md, RUNTIME_CAPABILITIES.json and packaged documentation must agree.

Use native type=operation commands for catalogued workflows. A user-supplied target_url must be preserved; target_title requires a unique exact match. Candidate search must not automatically choose the first result. Post/page metadata routing follows the actual object type.

## Media and workspace

- Up to 1 MiB decoded: one media.upload.inline operation. No split or verify-first command.
- Above 1 MiB through 6 MiB: split original binary before independently Base64-encoding each part. Publish staged payloads and one upload command in one Git tree/commit/ref update when supported.
- Staged sources live under wordpress-bridge/media/pending/. Ordered data_paths, filename, expected_bytes and expected_sha256 describe the complete file.
- Sequential upload-chunk is a fallback when staged writes cannot reliably be used; a missing local-file parameter alone is not a reason to use it.
- Integrity must be verified before attachment creation. Successful staged uploads must clean their sources.
- Existing staged data need not be downloaded and restaged just to change transport.
- Workspace search/range/patch/snapshot is the persistent artifact path.
- Site Icon uses its dedicated get/set/clear operations with confirmation and stale-state checks.

## Delivery and recovery

- Same command ID and identical payload replay the recorded result without repeating a WordPress side effect.
- Same ID with changed payload is a conflict.
- Self-generated bookkeeping must not redispatch commands.
- Concurrent branch changes must be preserved; a changed pending command must not be deleted.
- Journal recovery and pending reconciliation must recover publication failures without duplicate mutations.
- A matching visible result is the atomic completion barrier. Missing results are unknown, not proof of failure.
- Record submission, operation execution and result availability separately. Scoped timing excludes unmeasured phases; do not present duration_ms as total wait.
- Record client approval interruptions separately from Bridge transport failures. Bridge cannot override client approval policy.

## Update and package gates

Use self_update.apply with pinned official source, complete manifest, expected current version, and confirmation. Verify the installed version in a separate request afterward. Ordinary updates do not require temporary PHP installers.

Require PHP parse checks, dependency verification, complete per-file/manifest integrity, explicit confirmed deletion paths, backup before replacement, and restoration on failed replacement. Packages exclude runtime payloads, secrets, operator data and unrelated project files.

All checks must pass on the final candidate: core CI, deterministic operation integration, clean WordPress installation, direct runtime, idempotency, migration, self-update safety and external test kit. Record the deterministic package SHA-256.

## Practical acceptance

Run docs/EXTERNAL_TEST_GUIDE_JA.md against the actual candidate. Include:
1. Post and page creation (draft default), unique-title and URL selection, ambiguous-name rejection, bounded discovery/read, guarded content editing, and independent verification.
2. Small inline and larger staged images with byte/SHA checks and cleanup.
3. Workspace guarded editing and stale rejection.
4. Theme list/search/read-many; draft preview and rollback without altering the active theme merely for a test.
5. Duplicate delivery, uncertain-result recovery and concurrent publication.
6. A fresh client session using only installed runtime guidance: no old-runtime discovery, wrong target, unnecessary fallback, or duplicate mutation.
7. End-to-end timing across representative workflows, including long-delay/recovery cases and approval counts.

Completing deterministic server tests establishes those behaviors, not fresh-model reliability or visual correctness. A visual change requires a rendered output check.

## WPVibe comparison boundary

Compare the same user workflows and evidence, not feature names or unmeasured speed. WPVibe documents REST access, theme draft/preview, rendered-page inspection, page audits and guided workflows (https://wpvibe.ai/features/). It uses a hosted relay (https://wpvibe.ai/security/); Bridge's GitHub transport has different latency and ownership tradeoffs.

Do not claim full WPVibe equivalence until the relevant workflows, client experience and latency have actually been compared. No new service or cost is implied by this checklist.

## Publication

Stable distribution and the public completion article are separate from production validation. Keep existing private development articles private until completion and publication are explicitly authorized. Preserve the project license and distribution terms in LICENSE.md.
