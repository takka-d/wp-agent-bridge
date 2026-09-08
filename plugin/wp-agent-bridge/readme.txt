=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.1.12
Requires PHP: 7.4
License: WP Agent Bridge License 1.0

Secure WordPress management bridge for ChatGPT using a user-owned private GitHub runtime repository and a site-specific signed GitHub App webhook.

== Description ==

WP Agent Bridge exposes a deliberately bounded WordPress management surface for ChatGPT.

Normal command execution is self-contained: the user owns the private GitHub runtime repository and the site-specific GitHub App. GitHub sends the signed push Webhook directly to the user's WordPress, and WordPress writes the result back to that same user-owned repository.

Normal operation does not use an operator-owned runtime repository, operator-owned relay server, per-command GitHub Actions worker, old Bridge Key, `takka-d/chatgpt-data`, or WPVibe.

The project is designed around these principles:

* Free of charge to download, install, and use.
* User-owned GitHub runtime storage rather than operator-owned runtime storage.
* Site-specific private GitHub App with signed Webhook delivery directly to the user's WordPress.
* GitHub App private key and Webhook secret encrypted at rest on that WordPress installation.
* No operator collection/storage of runtime commands, results, article text, or WordPress settings in normal operation.
* Allowlisted management operations rather than arbitrary shell or WP-CLI execution.
* Preview/plan hashes and stale-write detection before high-risk content changes.
* Draft theme preview, backup, publish, and rollback workflows.
* Completed-response request IDs for retry-safe delivery.
* Pending-directory reconciliation after missed Webhook/bookkeeping delivery.
* Protected handling for user data, post meta, options, and other sensitive WordPress state.
* Media transport selected according to source and available GitHub write capabilities.
* Private runtime workspace for persistent HTML, JavaScript, CSS, JSON, POV-Ray, Markdown, and related text artifacts.

== Installation ==

1. Download the WP Agent Bridge ZIP.
2. Install and activate it in WordPress.
3. Open Tools > WP Agent Bridge.
4. Use the provided GitHub link to create the suggested private runtime repository in your own GitHub account.
5. Choose "Connect GitHub". GitHub creates a private site-specific GitHub App from the manifest supplied by your WordPress.
6. When installing the GitHub App, choose "Only select repositories" and select only that private runtime repository.
7. WP Agent Bridge initializes the `wp-agent-bridge-runtime` branch and canonical runtime marker.
8. Connect your own GitHub account to ChatGPT and verify that ChatGPT can access your runtime repository.
9. Ask ChatGPT to update WordPress.

PATs, manual Webhook secrets, private keys, Bridge Keys, and GitHub Actions workflow configuration are not part of the ordinary setup.

== Media transfer ==

Media files up to 6 MiB are supported.

For ChatGPT-local, conversation-uploaded, sandbox, or connector-downloaded files, the preferred route is the batched staged-media path when GitHub can write UTF-8 text/blobs. Split the original binary before Base64 encoding, stage bounded independent chunks under `wordpress-bridge/media/pending/`, and publish the payloads plus one pending upload command together when Git Data operations are available. The upload itself validates whole-file bytes/SHA-256 and optional per-chunk integrity before creating the attachment, so a separate verify-first pass is not used in the normal successful path.

The fast path resolves all ordered staged paths from one current Git tree snapshot and reads the resulting Git blobs directly. Optional `data_blob_shas` can pin a staged path to the blob SHA already returned by the staging operation. Successful fast-path media handling now runs from `rest_pre_dispatch`, which guarantees that the registered legacy media callback does not run a second time after the staged payload has already been consumed and deleted. When staged-media transfer is unavailable or actually fails, `/wp-agent-bridge-media/v1/upload-chunk` remains the authenticated sequential fallback.

`/wp-agent-bridge-runtime/v1/media-verify` is reserved for explicit verify-only requests, uncertain staging, or integrity-409 diagnosis/recovery. Integrity failures include chunk diagnostics so only mismatched staged payloads need to be replaced when identifiable.

== Development workspace ==

WP Agent Bridge can keep persistent development artifacts under `wordpress-bridge/workspace/` in the user's private canonical runtime repository. This is intended for iterative work such as a large HTML/JavaScript tool that would otherwise need to be rediscovered from ChatGPT File Library and re-read in full on every continuation.

The internal `/takka-v097/v1/manage` surface provides `workspace.list`, full or ranged reads, bounded literal search, guarded full writes, exact-fragment patching, deletion, compact line diffing, snapshot creation/listing, and snapshot rollback. Existing files require `expected_current_sha256`; new files require `expected_current_absent=true`; mutating operations require explicit confirmation. Workspace paths are restricted to an allowlist of text-oriented development extensions, and no arbitrary filesystem, shell, PHP, or executable upload surface is added.

Snapshots store the source Git blob identity rather than duplicating the whole file. Git history remains the underlying version store while the snapshot manifest gives ChatGPT a stable rollback handle.

== Delivery recovery ==

A GitHub push is not treated as a durable queue by itself. Every valid runtime push also reconciles the current `wordpress-bridge/commands/pending/` directory. Self-generated media/result/completed bookkeeping pushes are ignored when their changed paths are available. Every active command owns a per-request ID in-flight marker through WordPress execution and GitHub bookkeeping, so concurrent recovery does not re-dispatch a command while it is still running.

After WordPress execution returns, Direct Runtime persists the exact sanitized result in a local non-autoloaded recovery journal before attempting the GitHub result write. If GitHub bookkeeping races a media cleanup commit or otherwise fails before the result becomes durable, a later recovery of the same request ID reuses that journal instead of executing the WordPress side effect again. The journal is cleared as soon as the GitHub result is durable; stale journals expire after 24 hours. Changed command content under the same request ID is rejected rather than replayed. This covers the failure window between a successful WordPress side effect and durable GitHub result/completed/pending bookkeeping.

== Self-update safety ==

Bridge self-update requires a full manifest. Existing plugin files omitted from the manifest are not silently treated as deletions. Deletions require explicit delete paths and confirmation. PHP syntax and required bootstrap dependencies are validated before replacement.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

= 1.1.12 =
* Adds automatic generation and version-aware synchronization of `wordpress-bridge/RUNTIME_CAPABILITIES.json` in the canonical private runtime repository.
* Publishes a deterministic machine-readable catalog of installed routes, actions, feature flags, limits, connector fast paths, and the official self-update release pointer.
* Uses a per-version sync marker plus a short in-flight lock so the catalog's own GitHub push does not recursively trigger duplicate synchronization.

= 1.1.11 =
* Adds a persistent private development workspace rooted at `wordpress-bridge/workspace/` in the user's canonical runtime repository.
* Adds structured list, full/ranged read, literal search, guarded write, exact patch, delete, compact diff, snapshot, and rollback operations for bounded UTF-8 development artifacts.
* Uses SHA-256 stale-write guards, explicit confirmations, extension allowlisting, bounded file/search limits, and Git blob-backed snapshots instead of arbitrary filesystem or shell access.

= 1.1.10 =
* Moves staged-media Auto Path execution from `rest_request_before_callbacks` to `rest_pre_dispatch` so a successful fast-path upload truly short-circuits the legacy media route callback.
* Prevents the legacy callback from re-reading an already-deleted staged payload and replacing a successful attachment result with a false GitHub 404.
* Keeps the existing bounded legacy fallback when Auto Path deliberately yields on GitHub-compatible 404/405/501 capability failures.

= 1.1.9 =
* Persists a local per-request completion journal after WordPress execution and before GitHub result bookkeeping.
* Replays the exact successful local result when GitHub bookkeeping fails after a side effect, instead of re-running the command against already-consumed media payloads.
* Rejects changed command content under an existing journaled request ID and expires abandoned journals after 24 hours.

= 1.1.8 =
* Adds per-request ID in-flight ownership around Direct Runtime command execution and GitHub bookkeeping.
* Prevents V2 pending recovery from re-dispatching a command that is still running, including media uploads whose own cleanup commit triggers another webhook before the original result is written.
* Treats stale in-flight ownership as expired after 10 minutes so crashed requests remain recoverable.

= 1.1.7 =
* Adds pinned-source self-update: a small runtime command can download the official GitHub source archive at an exact commit, reconstruct the full plugin manifest locally, verify the expected aggregate SHA-256, and then reuse the existing PHP-parse/backup/rollback/full-manifest safety path.
* Removes the need for ChatGPT or the user to transport a 1+ MiB Base64 self-update command after a site is on 1.1.7 or newer.
* Keeps source repository and download host fixed, requires a full commit SHA and expected manifest SHA-256, and retains explicit confirmation for source-package deletions.

= 1.1.6 =
* Adds exact guarded theme writes with expected source SHA-256 and stale current-target SHA checks while preserving existing lint/backup/rename behavior.
* Adds verify-only staged-media diagnostics and per-chunk integrity reporting for 409 recovery without attachment or Site Icon side effects.
* Speeds normal staged media upload by removing verify-first duplication, resolving ordered `data_paths` from one Git tree snapshot, and reading payload Git blobs directly.
* Keeps `data_blob_shas` optional as an additional path-to-blob integrity pin and preserves the original Direct Media implementation as the real compatibility fallback.
* Updates generated runtime guidance with the canonical-runtime fast path so already verified runtimes are reused instead of repeatedly exploring retired or neighboring repositories.

= 1.1.5 =
* Routes ChatGPT-local, conversation-uploaded, and sandbox media through the existing authenticated chunk-upload path instead of trying to stage local files into GitHub `media/pending/*.b64` first.
* Keeps the staged GitHub media path for sources that are already manageable by the GitHub connector.
* Updates generated canonical runtime guidance so fresh installations and later runtime-identity syncs preserve this source-aware media routing.

= 1.1.4 =
* Serializes authenticated Direct Runtime push handling before the primary executor, preventing concurrent recovery from persisting a temporary `idempotency_in_progress` response as terminal bookkeeping.
* Hardens media staging guidance: split the original binary first, Base64-encode chunks independently, keep payloads at or below 8,000 Base64 characters, and verify staged blobs by read-back before publishing the atomic command commit.
* Adds a 41,946-byte media staging regression fixture matching the size of the previously observed truncated payload case.

= 1.1.3 =
* Publishes multi-file media payloads and upload commands as one Git Data branch update when the connected ChatGPT GitHub surface supports Git Data operations.
* Removes successful runtime media payloads in one Git tree cleanup commit instead of one commit per `.b64` file.
* Retries media cleanup after concurrent runtime-branch movement and verifies source blob SHAs before deletion.
* Adds regression coverage for multi-part media reconstruction and a simulated cleanup ref conflict.

= 1.1.2 =
* Aligns packaged version metadata and bundled documentation with the self-contained Direct Runtime architecture validated in 1.1.1.
* Replaces stale 1.0.1/relay onboarding text in the packaged README without changing the validated runtime behavior.

= 1.1.1 =
* Prevents self-generated Direct Runtime media/result/completed bookkeeping pushes from recursively redispatching an in-flight pending command.
* Keeps new/modified pending-command pushes and non-internal runtime pushes available to the normal recovery path.

= 1.1.0 =
* Reworked the runtime architecture so normal operation uses the user's own private GitHub repository and a site-specific private GitHub App signed Webhook directly to that user's WordPress.
* Removed the operator-owned runtime/relay architecture from the distribution path.
* Added recovery scans of the current pending directory so a missed push or interrupted GitHub bookkeeping write can be recovered by a later valid push.
* Added conflict-safe result/completed/pending bookkeeping and retry behavior.
* Added completed-response request IDs for retry-safe delivery and changed-payload conflict rejection.
* Hardened Bridge self-update so a full manifest is required; omitted live plugin files are no longer interpreted as implicit deletions.
* Plugin-file deletion during self-update requires explicit delete paths and confirmation, and required PHP bootstrap dependencies are checked before replacement.
* Added large-media transport that keeps Base64 payloads outside one command JSON, verifies original byte count and SHA-256, and removes temporary source payloads after success.
* Retains bounded chunked media transport as an authenticated fallback.

= 1.0.1 =
* Added completed-response request ID persistence and guarded full-manifest self-update.
* Added canonical signed-runtime chunked media transfer so files within the 6 MiB media limit do not have to fit into one 2 MiB command JSON.

= 1.0.0 =
* Added guided GitHub onboarding to the main plugin.
* Added public distribution metadata and custom source-available license.

= 0.9.3 =
* Fixed an early plugin-bootstrap fatal in the 0.9.2 at-rest key derivation.
* Derives the at-rest encryption key only from wp-config key/salt constants available during plugin bootstrap.
* Keeps the 0.9.2 public-release security goals without depending on pluggable.php functions.

= 0.9.2 =
* Added public-release hardening.
* Added encryption at rest for the Bridge HMAC secret and secure RSA private key.
* Added optional `TAKKA_BRIDGE_SECRET` wp-config override.
* Added explicit secure server key status and rotation controls.
