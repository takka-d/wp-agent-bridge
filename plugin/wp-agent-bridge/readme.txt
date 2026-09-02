=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: WP Agent Bridge License 1.0

Secure WordPress management bridge for ChatGPT using a site-specific GitHub App, signed push Webhook transport, guarded writes, previews, and rollback.

== Description ==

WP Agent Bridge exposes a deliberately bounded WordPress management surface for ChatGPT.

Normal command execution uses the user's own private GitHub runtime repository and a site-specific GitHub App. GitHub sends a signed push Webhook directly to the user's WordPress site. Results are written back to the same private repository for ChatGPT to read.

Normal operation does not require an operator-owned relay server, WPVibe, or a per-command GitHub Actions workflow.

The project is designed around these principles:

* Site-specific GitHub App with repository scope limited by the user.
* Signed GitHub push Webhook delivered directly to the connected WordPress site.
* Allowlisted management operations rather than arbitrary shell or WP-CLI execution.
* Preview/plan hashes and stale-write detection before high-risk content changes.
* Draft theme preview, backup, publish, and rollback workflows.
* Idempotent request IDs for retry-safe delivery.
* Protected handling for user data, post meta, options, and other sensitive WordPress state.
* Private key and Webhook secret stored encrypted inside the user's WordPress installation.

== Installation ==

1. Download the WP Agent Bridge ZIP.
2. Install and activate it in WordPress.
3. Open Tools > WP Agent Bridge.
4. Choose "Create private repository on GitHub" and create the pre-filled repository as Private in your own GitHub account.
5. Return to WordPress and choose "Connect GitHub".
6. Create the site-specific GitHub App on GitHub.
7. During installation choose "Only select repositories" and select only the private runtime repository created in step 4.
8. Install the GitHub App and return to WordPress.
9. Confirm "Status: Connected (direct GitHub webhook)".
10. Connect GitHub in ChatGPT and ask ChatGPT to use the repository shown on the WP Agent Bridge Connected screen.

The runtime branch and required queue files are initialized by WP Agent Bridge. No PAT, Bridge Key, GitHub Actions secret, or operator relay configuration is required for ordinary users.

== Main capabilities ==

* Posts and pages: read, create, update, search, partial/batch content editing.
* Media upload, including base64 upload and URL-based upload paths.
* Theme file read/search/edit and draft-theme preview/publish/rollback.
* Plugin and theme management.
* Post meta, options, taxonomies, menus, users/roles, cron, and selected REST operations with guards.
* Guarded writes, preview/confirm, SHA-256, plan/impact hashes, stale-write rejection, and rollback protections.

Arbitrary shell commands, arbitrary WP-CLI strings, and unrestricted SQL writes are not exposed.

== Runtime repository identity ==

A connected runtime repository contains `wordpress-bridge/RUNTIME_CONNECTION.json` and `AGENTS.md`. ChatGPT should verify the exact repository shown by the WordPress Connected screen and the canonical marker instead of choosing a repository only because it has a similar branch or folder name.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

= 1.0.0 =
* Added Direct Runtime using the user's private GitHub repository and a site-specific GitHub App.
* Added signed push Webhook delivery directly from GitHub to the user's WordPress site.
* Added runtime repository identity hardening to reduce accidental use of stale repositories.
* Removed the legacy manual Bridge Key / GitHub Actions setup path from the public admin UI.
* Added media upload and guarded WordPress management operations.
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
