=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.0.1
Requires PHP: 7.4
License: WP Agent Bridge License 1.0

Secure WordPress management bridge for ChatGPT with GitHub App + signed Webhook transport, guarded writes, previews, and rollback.

== Description ==

WP Agent Bridge exposes a deliberately bounded WordPress management surface for ChatGPT.

Normal command execution uses a private GitHub runtime repository and signed GitHub App webhook relay. It does not depend on GitHub Actions for normal WordPress commands.

The project is designed around these principles:

* Guided GitHub onboarding from WordPress.
* Signed GitHub App webhook transport for normal command execution.
* Allowlisted management operations rather than arbitrary shell or WP-CLI execution.
* Preview/plan hashes and stale-write detection before high-risk content changes.
* Draft theme preview, backup, publish, and rollback workflows.
* Idempotent request IDs for retry-safe delivery.
* Protected handling for user data, post meta, options, and other sensitive WordPress state.
* Chunked media transfer for files that would exceed the runtime command JSON limit.

== Installation ==

1. Download the WP Agent Bridge ZIP.
2. Install and activate it in WordPress.
3. Open Tools > WP Bridge Setup and choose "Connect GitHub".
4. Complete the GitHub authorization flow.
5. Connect the generated runtime repository to ChatGPT's GitHub integration.
6. Ask ChatGPT to update WordPress.

The onboarding flow creates and initializes the dedicated private runtime repository automatically. Manual branch, webhook, or secret configuration is not required for ordinary users.

== Media transfer ==

Media files up to 6 MiB can be transferred without embedding the entire Base64 payload in one runtime command. The client splits the binary into bounded chunks, sends each chunk through the existing authenticated Bridge rest.call action, and WordPress verifies both per-chunk and whole-file byte counts and SHA-256 before adding the reconstructed file to the media library. Retried chunks are idempotent and stale staged uploads are cleaned automatically.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

= 1.0.1 =
* Added completed-response request ID persistence so an identical retried runtime command replays the original response without re-running its WordPress side effect.
* Rejects reuse of the same request ID with a different payload instead of executing the changed command.
* Hardened Bridge self-update so a full manifest is required; omitted live plugin files are no longer interpreted as implicit deletions.
* Plugin-file deletion during self-update now requires explicit delete paths and explicit deletion confirmation, and required PHP bootstrap dependencies are checked before replacement.
* Added chunked media transport over the canonical signed Webhook runtime path.
* Avoids embedding a full image Base64 payload in one command JSON, preventing the runtime 2 MiB command limit from conflicting with the 6 MiB WordPress media limit.
* Verifies per-chunk byte count and SHA-256 plus the reconstructed original file byte count and SHA-256.
* Supports retry-safe duplicate chunks, automatic finalization, and stale staging cleanup.

= 1.0.0 =
* Added guided GitHub onboarding to the main plugin.
* Added automatic dedicated private runtime repository creation and initialization.
* Added GitHub Organization runtime provisioning with minimal temporary OAuth scope for repository invitation handling.
* Normal command transport uses GitHub App + signed Webhook relay without GitHub Actions.
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
