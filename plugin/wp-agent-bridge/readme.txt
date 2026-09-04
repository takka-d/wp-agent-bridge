=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.1.0
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
* Media transport that keeps large Base64 payloads out of a single runtime command JSON.

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

Media files up to 6 MiB can be transferred without embedding the entire Base64 payload in one command JSON. The preferred self-contained path stores one or more Base64 payload files under `wordpress-bridge/media/pending/` in the user's own private runtime repository. A small command calls `/wp-agent-bridge-runtime/v1/media-upload` with the payload path(s), filename, original byte count, and original SHA-256. WordPress reconstructs and verifies the original file before adding it to the media library, then removes the temporary payload files after success.

A bounded authenticated chunk REST route remains available as a fallback transport.

== Delivery recovery ==

A GitHub push is not treated as a durable queue by itself. Every valid runtime push also reconciles the current `wordpress-bridge/commands/pending/` directory. If WordPress completed a command but GitHub bookkeeping failed, the same request ID is replayed from WordPress's completed-response idempotency rather than repeating the side effect, and the pending/result/completed files are repaired.

== Self-update safety ==

Bridge self-update requires a full manifest. Existing plugin files omitted from the manifest are not silently treated as deletions. Deletions require explicit delete paths and confirmation. PHP syntax and required bootstrap dependencies are validated before replacement.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

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
