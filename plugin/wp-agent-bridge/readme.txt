=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.1.5
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
* Media transport selected according to whether the source is ChatGPT-local or already GitHub-manageable.

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

Media files up to 6 MiB are supported. The runtime instructions select transport from the source of the file rather than forcing every image through GitHub media payload files.

For a ChatGPT conversation attachment or sandbox/container file that cannot be passed to the GitHub connector as a local file reference, the preferred path is `/wp-agent-bridge-media/v1/upload-chunk`. The local binary is split into ordered chunks, each chunk is Base64-encoded and integrity-checked, and sequential normal runtime commands send the chunks to WordPress. The final chunk triggers whole-file reconstruction, byte-count/SHA-256 validation, Media Library creation, and WordPress-side temporary-file cleanup.

For media already available to the GitHub connector as manageable text/blob input, the self-contained staged-media path remains available under `wordpress-bridge/media/pending/`. The original binary is split first, each chunk is Base64-encoded independently, staged blobs are verified before publication, and the payload files plus upload command can be published atomically in one Git tree/commit/ref update. WordPress verifies the reconstructed file and removes successful temporary payloads in one bounded-retry cleanup commit.

== Delivery recovery ==

A GitHub push is not treated as a durable queue by itself. Every valid runtime push also reconciles the current `wordpress-bridge/commands/pending/` directory. Self-generated media/result/completed bookkeeping pushes are ignored by the recovery scanner so they cannot recursively redispatch the command that created them. Authenticated runtime push handling is serialized before the primary executor so reconciliation cannot race an already-running command and persist a temporary idempotency-in-progress response as a terminal result. If WordPress completed a command but GitHub bookkeeping failed, the same request ID is replayed from WordPress's completed-response idempotency rather than repeating the side effect, and the pending/result/completed files are repaired.

== Self-update safety ==

Bridge self-update requires a full manifest. Existing plugin files omitted from the manifest are not silently treated as deletions. Deletions require explicit delete paths and confirmation. PHP syntax and required bootstrap dependencies are validated before replacement.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

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
* Added completed-response request ID persistence so an identical retried runtime command replays the original response without re-running its WordPress side effect.
* Rejects reuse of the same request ID with a different payload instead of executing the changed command.
* Hardened Bridge self-update so a full manifest is required; omitted live plugin files are no longer interpreted as implicit deletions.
* Plugin-file deletion during self-update now requires explicit delete paths and explicit deletion confirmation, and required PHP bootstrap dependencies are checked before replacement.
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