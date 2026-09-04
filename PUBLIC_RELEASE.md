# WP Agent Bridge Public Release Checklist

## Release architecture

WP Agent Bridge 1.1.0 is the first self-contained runtime release candidate.

Normal end-user operation must use only resources controlled by that end user:

`ChatGPT -> user's private runtime repository -> user's site-specific GitHub App signed Webhook -> user's WordPress -> user's runtime repository -> ChatGPT`

The distribution must not require an operator-owned runtime Organization, operator-owned private repository, operator relay server, per-command GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.

Version 1.0.1 remains the previous signed-runtime/chunk-media generation. Do not publish the self-contained architecture under the same 1.0.1 version number. The self-contained release line starts at **1.1.0**.

## Public repository contents

The public distribution intentionally excludes:

- central/operator Onboarding Service source;
- diagnostics/test helper plugins;
- existing runtime command/result/media payloads;
- unrelated project directories;
- credentials, tokens, private keys, Webhook secrets, development-only data;
- operator-owned runtime repository configuration.

The public ZIP contains the WP Agent Bridge plugin itself plus its license/readme files. Development and test workflows remain repository-side and are not included in the installed plugin directory unless explicitly part of the package source.

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

`AGENTS.md` and `WEBHOOK_RUNTIME.md` must describe the same architecture and must not tell ChatGPT to substitute an operator-owned Organization runtime.

## Delivery and replay safety

Required behaviors:

- every runtime command uses unique `id` and `request_id` values;
- identical completed request IDs replay the stored response instead of repeating the WordPress side effect;
- reuse of one request ID with a different payload is rejected;
- a valid push reconciles the current `commands/pending/` directory so a missed Webhook or interrupted GitHub bookkeeping write can recover;
- result/completed/pending conflicts are retried without duplicating WordPress mutations.

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

## 1.1.0 release gates

Completed in isolation before live cutover:

- [x] PHP syntax / release metadata / obvious-secret CI passes.
- [x] exact packaged plugin installs and activates on clean WordPress 7.1 + MySQL 8.
- [x] content stale-write rejection, active plugin/theme protection, sensitive option protection, Draft Theme publish/rollback pass.
- [x] request-ID completed-response idempotency and changed-payload rejection pass.
- [x] GitHub result-write conflict recovery completes bookkeeping without repeating the WordPress side effect.
- [x] missed-pending reconciliation is exercised by the self-contained runtime test.
- [x] self-update regression test rejects destructive incomplete manifests and validates required dependencies.
- [x] self-contained migration test preserves rollback metadata and writes a user-owned canonical runtime identity.
- [x] approximately 2.4 MiB media reconstruction passes in isolated WordPress testing.
- [x] external tester kit is generated from the exact plugin version metadata rather than a hard-coded prior version.

Production observations before 1.1.0 cutover:

- [x] TakKa Note's existing canonical signed runtime remains healthy before migration.
- [x] TakKa Note's existing 1.0.1 chunk-media route successfully staged two chunks, reconstructed a PNG with matching full SHA-256, created the attachment, and the test attachment was then deleted.
- [x] current production plugin/version and self-update backup inventory were captured before any 1.1.0 replacement.
- [x] current production 1.0.1 does not expose the self-contained onboarding v2 route, proving 1.1.0 is an actual architecture change rather than a same-build reinstall.

Still required before stable 1.1.0 publication/cutover:

- [ ] 1.1.0 branch CI passes after the version separation changes.
- [ ] exact 1.1.0 distribution artifact SHA-256 is recorded.
- [ ] full-manifest live preflight is performed without writes and does not reveal undeclared existing-file deletion.
- [ ] coexistence/migration behavior with the currently active production Onboarding Service is resolved before replacing live plugin files.
- [ ] Direct Runtime is established in parallel while the current canonical signed runtime remains usable.
- [ ] Direct Runtime health, retry/idempotency, and media upload are proven on the live site before canonical cutover.
- [ ] only after successful Direct validation is the old operator-owned runtime path retired for this site.
- [ ] download/article license notice matches `LICENSE.md`.
