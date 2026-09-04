# WP Agent Bridge Public Release Checklist

## Release architecture

WP Agent Bridge 1.1.x is the self-contained runtime release line. The first self-contained candidate was 1.1.0; 1.1.1 added the Direct Runtime self-webhook loop guard after live migration testing exposed a recursive re-scan case. **1.1.2 is the current release candidate**, retaining the 1.1.1 runtime implementation while aligning distributed version metadata and packaged documentation with the self-contained architecture.

Normal end-user operation must use only resources controlled by that end user:

`ChatGPT -> user's private runtime repository -> user's site-specific GitHub App signed Webhook -> user's WordPress -> user's runtime repository -> ChatGPT`

The distribution must not require an operator-owned runtime Organization, operator-owned private repository, operator relay server, per-command GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.

Version 1.0.1 remains the previous signed-runtime/chunk-media generation. The self-contained architecture must never be published as a replacement build under the 1.0.1 version number.

## Public repository contents

The public distribution intentionally excludes:

- central/operator Onboarding Service source;
- diagnostics/test helper plugins;
- existing runtime command/result/media payloads;
- unrelated project directories;
- credentials, tokens, private keys, Webhook secrets, development-only data;
- operator-owned runtime repository configuration.

The public ZIP contains the self-contained WP Agent Bridge plugin itself, including its direct GitHub onboarding/runtime implementation, plus its license/readme files. Development and test workflows remain repository-side and are not included in the installed plugin directory unless explicitly part of the package source.

## End-user onboarding target

1. Download the WP Agent Bridge ZIP.
2. Install and activate it in WordPress.
3. Open `Tools > WP Agent Bridge`.
4. Create the suggested private runtime repository in the user's own GitHub account.
5. Choose `Connect GitHub`; WordPress supplies a GitHub App manifest.
6. Create/install the site-specific private GitHub App in the user's own GitHub account, selecting only that runtime repository.
7. WP Agent Bridge initializes the `wp-agent-bridge-runtime` branch, canonical marker, command/result directories, and media payload directory.
8. Connect the same GitHub account to ChatGPT and verify that ChatGPT can access that runtime repository.
9. Ask ChatGPT to operate WordPress through that repository.

Ordinary setup must not require the user to paste a PAT, private key, Webhook secret, Bridge Key, or GitHub Actions workflow configuration.

## Runtime identity requirements

A completed Direct Runtime repository must contain a machine-readable `wordpress-bridge/RUNTIME_CONNECTION.json` identifying at least:

- `status: canonical`
- `transport: direct-github-webhook`
- `ownership: user-owned`
- `operator_relay: false`
- the actual repository name
- runtime branch `wp-agent-bridge-runtime`
- the target site host

`AGENTS.md` and `wordpress-bridge/WEBHOOK_RUNTIME.md` must describe the same architecture and must not tell ChatGPT to substitute an operator-owned Organization runtime.

## Delivery and replay safety

Required behaviors:

- every runtime command uses unique `id` and `request_id` values;
- identical completed request IDs replay the stored response instead of repeating the WordPress side effect;
- reuse of one request ID with a different payload is rejected;
- a valid push reconciles the current `commands/pending/` directory so a missed Webhook or interrupted GitHub bookkeeping write can recover;
- result/completed/pending conflicts are retried without duplicating WordPress mutations;
- GitHub writes produced by an in-progress Direct Runtime command do not cause that same request ID to be recursively reexecuted.

## Media transport

WordPress media limit: 6 MiB decoded.

The ordinary self-contained media path must not embed a large file's complete Base64 payload into one command JSON. Base64 payload files are stored only in the user's own private runtime repository under `wordpress-bridge/media/pending/`, then fetched by the user's WordPress through the site-specific GitHub App.

WordPress must verify original `expected_bytes` and `expected_sha256` before attachment creation and remove temporary payload files after success. The bounded authenticated chunk route may remain as a fallback and must verify per-chunk and whole-file integrity.

## Self-update safety

A Bridge self-update must:

- require `full_manifest=true`;
- reject partial manifests;
- verify every submitted file SHA-256;
- verify the canonical manifest SHA-256;
- parse-check submitted PHP before replacement;
- verify literal bootstrap PHP dependencies are present;
- require every omitted existing file to be explicitly present in `delete_paths`;
- require separate deletion confirmation when `delete_paths` is non-empty;
- capture the current plugin before writing;
- automatically restore that capture if file replacement itself fails.

A live migration must not rely on plugin-internal rollback as the only recovery mechanism for a post-write bootstrap fatal. Existing production transport must remain available until the new Direct Runtime has independently passed health and media/retry checks.

## Privacy and ownership

Normal runtime commands, results, article text, WordPress settings, GitHub App private keys, and Webhook secrets are not sent to or stored by the WP Agent Bridge operator.

The site-specific GitHub App private key and Webhook secret are encrypted at rest inside that user's WordPress installation. The runtime repository is private and user-owned.

External test result sheets should not request the tester's GitHub username, WordPress URL, article contents, secrets, or runtime command/result payloads unless a tester independently chooses to provide diagnostic material for a specific failure.

## License

The public source is source-visible but is not an open-source license. `WP Agent Bridge License 1.0` permits free download/install/use and private modification, while redistribution of original or modified copies requires prior written permission.

Do not submit the current licensed distribution to the WordPress.org Plugin Directory under this license.

## 1.1.2 release gates

### Isolation / CI gates already demonstrated by the 1.1.1 runtime implementation

- [x] PHP syntax / release metadata / obvious-secret CI passes.
- [x] exact packaged plugin installs and activates on clean WordPress 7.1 + MySQL 8.
- [x] content stale-write rejection, active plugin/theme protection, sensitive option protection, Draft Theme publish/rollback pass.
- [x] request-ID completed-response idempotency and changed-payload rejection pass.
- [x] GitHub result-write conflict recovery completes bookkeeping without repeating the WordPress side effect.
- [x] missed-pending reconciliation is exercised by the self-contained runtime test.
- [x] self-update regression test rejects destructive incomplete manifests and validates required dependencies.
- [x] self-contained migration test preserves rollback metadata and writes a user-owned canonical runtime identity.
- [x] approximately 2.4 MiB media reconstruction passes in isolated WordPress testing.
- [x] Direct Runtime self-webhook recursion has a dedicated regression test.
- [x] external tester kit derives its plugin version from package metadata rather than a hard-coded prior version.

### Live migration / production validation completed on TakKa Note

- [x] the previous production transport remained available until Direct Runtime validation was complete.
- [x] full 60-file source manifest was verified before live replacement; canonical manifest SHA-256 was `875fad803cd985a2ece7615b90a48202e75c4069a52422a84935b2a982479379`.
- [x] live plugin update completed with an independent filesystem backup and post-copy version/guard verification.
- [x] user-owned Direct Runtime identity reports `status=canonical`, `transport=direct-github-webhook`, `ownership=user-owned`, and `operator_relay=false`.
- [x] `AGENTS.md` and `wordpress-bridge/WEBHOOK_RUNTIME.md` describe the same user-owned direct architecture.
- [x] Direct Runtime health succeeds from the user-owned runtime repository.
- [x] 2,458,838-byte PNG was split into five runtime payload files, reconstructed without shrinking or alternate transport, verified by full SHA-256, registered in Media Library, and source payloads were removed after success.
- [x] the test media attachment and all temporary media E2E routes were removed after verification.
- [x] live completed-response idempotency was verified with a reversible draft side effect: identical request ID/payload returned the same post ID without a second post, while changed payload with the same request ID returned HTTP 409.
- [x] the idempotency test draft was removed after verification.
- [x] central/operator Onboarding Service was deactivated only after Direct Runtime health/media/idempotency validation.
- [x] Direct Runtime remained healthy after central/operator Onboarding Service deactivation.
- [x] the retired central onboarding callback now returns the controlled HTTP 410 tombstone.
- [x] TakKa Note was updated from 1.1.1 to 1.1.2 using source commit `2a71861dd0645e45f4c2da0a12ea08cd63d74a29`; all 60 plugin files passed post-copy SHA verification, the plugin remained active, and post-cutover Direct Runtime health succeeded.
- [x] the final packaging commit `5a7d290c10505863d241dd0868124b327c28e092` changes only the tester-kit workflow relative to the live-cutover source, so its `plugin/wp-agent-bridge` tree is the same plugin tree already validated on TakKa Note.
- [x] all temporary 1.1.2 cutover routes were removed; the active child theme `functions.php` returned byte-for-byte to pre-cutover SHA-256 `8a5753c6e56b3fa61a44a84231e5658e52ff9203a8bd14b77dcf4dce01aaf8e4`, and the cleanup route subsequently returned WordPress `rest_no_route` 404.

### 1.1.2 package-finalization gates

- [x] packaged `plugin/wp-agent-bridge/README.md` no longer describes the old 1.0.1/relay onboarding model.
- [x] root README and packaged README describe the same self-contained ownership/transport model.
- [x] license notices in repository documentation match `WP Agent Bridge License 1.0` restrictions.
- [x] all seven runtime/package CI workflows passed on the 1.1.2 plugin source before live cutover; the subsequent packaging-only reproducibility change also passed its CI and tester-kit workflow.
- [x] the tester plugin ZIP is built with `git archive` from the exact commit rather than checkout mtimes; the workflow builds it twice and `cmp` verifies byte-for-byte equality before publication.
- [x] final packaging commit: `5a7d290c10505863d241dd0868124b327c28e092`.
- [x] reproducible 1.1.2 plugin ZIP SHA-256 from that commit: `92e82b181444f810e656294bac748f66919c17c0249d00e8bd73f584db3aa354`.
- [x] TakKa Note is running the same 1.1.2 plugin tree used by the final packaging commit and Direct Runtime health succeeds after the update.
- [x] final external tester kit was generated from merged 1.1.2 `main`: artifact ID `9930830506`, artifact SHA-256 digest `3cbb7ebd002bf0bf33881ddf78a010ae94aa92753126cb8c7d69409c059482b8`.

All listed 1.1.2 package-finalization gates are complete. **1.1.2 is ready for external tester distribution.** A broader public/stable release remains a separate release decision rather than an implicit consequence of completing these tester-distribution gates.
