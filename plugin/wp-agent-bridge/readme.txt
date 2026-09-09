=== WP Agent Bridge ===
Contributors: takka-d
Tags: automation, rest-api, github, administration, ai
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 1.1.14
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
* Strict read-only batching for independent search/read/inspect/status operations without adding a generic mutation surface.
* A deterministic high-level operation router for common ChatGPT workflows so callers do not have to reconstruct versioned routes or action spellings.
* Atomic Direct Runtime result/completed/pending bookkeeping so a visible result is a reliable completion barrier for the next command.

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

== Deterministic operation routing ==

Common workflows use the internal `/takka-v099/v1/operate` route with one allowlisted high-level operation name and params object. This keeps query parameters separate from route paths and removes the need for model clients to guess which versioned Bridge route implements a common task.

`post.get` is metadata-oriented and bounded by default; post source content is read through `post.content.search` or `post.content.read_range`. Generic `post.update` cannot replace post content, so content changes use the guarded preview/apply path. `readonly.batch` accepts the same documented high-level read operation names and normalizes them to the strict read-only action map.

The canonical runtime repository receives generated `AGENTS.md`, `WEBHOOK_RUNTIME.md`, and `wordpress-bridge/RUNTIME_CAPABILITIES.json` guidance for the installed Bridge version. Existing connected installations resynchronize this guidance when the routing contract changes.

== Media transfer ==

Media files up to 6 MiB are supported.

For local or conversation-uploaded media up to 1 MiB decoded, the preferred route is one inline `media.upload.inline` operation. The deterministic inline policy can validate caller-supplied `expected_bytes` and `expected_sha256` before the lower-level media handler runs. Files above the 1 MiB deterministic inline threshold are directed to staged Media Fast Path instead of silently taking an oversized inline route.

For larger files, the preferred route is the batched staged-media path when GitHub can write UTF-8 text/blobs. Split the original binary before Base64 encoding, stage bounded independent chunks under `wordpress-bridge/media/pending/`, and publish the payloads plus one pending upload command together when Git Data operations are available. The upload itself validates whole-file bytes/SHA-256 and optional per-chunk integrity before creating the attachment, so a separate verify-first pass is not used in the normal successful path.

The fast path resolves all ordered staged paths from one current Git tree snapshot and reads the resulting Git blobs directly. Optional `data_blob_shas` can pin a staged path to the blob SHA already returned by the staging operation. Successful fast-path media handling runs from `rest_pre_dispatch`, which guarantees that the registered legacy media callback does not run a second time after the staged payload has already been consumed and deleted. When staged-media transfer is unavailable or actually fails, `/wp-agent-bridge-media/v1/upload-chunk` remains the authenticated sequential fallback.

`/wp-agent-bridge-runtime/v1/media-verify` is reserved for explicit verify-only requests, uncertain staging, or integrity-409 diagnosis/recovery. Integrity failures include chunk diagnostics so only mismatched staged payloads need to be replaced when identifiable.

== Development workspace ==

WP Agent Bridge can keep persistent development artifacts under `wordpress-bridge/workspace/` in the user's private canonical runtime repository. This is intended for iterative work such as a large HTML/JavaScript tool that would otherwise need to be rediscovered from ChatGPT File Library and re-read in full on every continuation.

The internal `/takka-v097/v1/manage` surface provides `workspace.list`, full or ranged reads, bounded literal search, guarded full writes, exact-fragment patching, deletion, compact line diffing, snapshot creation/listing, and snapshot rollback. Existing files require `expected_current_sha256`; new files require `expected_current_absent=true`; mutating operations require explicit confirmation. Workspace paths are restricted to an allowlist of text-oriented development extensions, and no arbitrary filesystem, shell, PHP, or executable upload surface is added.

Snapshots store the source Git blob identity rather than duplicating the whole file. Git history remains the underlying version store while the snapshot manifest gives ChatGPT a stable rollback handle.

== Read-only batching ==

The internal `/takka-v098/v1/manage` surface can execute multiple independent allowlisted read/search/inspect/status operations behind one pending command and one signed webhook. A batch is preflighted in full before any operation runs; unknown or mutating actions reject the entire batch. The batch does not expose generic arbitrary REST calls, writes, uploads, preview creation, publishing, deletion, snapshot creation, or rollback.

Batches are bounded to 12 operations, 256 KiB of params per operation, a 1 MiB aggregate result, and a 20 second total execution budget. Individual operation failures are returned in a structured result array and can either stop the batch or allow remaining read-only operations to continue.

== Delivery recovery ==

A GitHub push is not treated as a durable queue by itself. Every valid runtime push also reconciles the current `wordpress-bridge/commands/pending/` directory. Self-generated media/result/completed bookkeeping pushes are ignored when their changed paths are available. Every active command owns a per-request ID in-flight marker through WordPress execution and GitHub bookkeeping, so concurrent recovery does not re-dispatch a command while it is still running.

After WordPress execution returns, Direct Runtime persists the exact sanitized result in a local non-autoloaded recovery journal before GitHub bookkeeping. Result creation, completed-command storage, and pending-command deletion are then assembled into one Git tree and published with one branch ref update. If the runtime branch moves concurrently, bookkeeping is rebuilt on the latest head and retried with a bounded attempt count while verifying that the pending command blob is still the exact command that was executed. If publication cannot be proven durable, the journal remains available so a later recovery reuses the original execution result instead of re-running the WordPress side effect. Once the matching result is visible, completed storage and pending deletion are already durable in the same commit; the journal can be cleared and the next command may be submitted immediately. Stale journals expire after 24 hours, and changed command content under the same request ID is rejected rather than replayed.

== Self-update safety ==

Bridge self-update requires a full manifest. Existing plugin files omitted from the manifest are not silently treated as deletions. Deletions require explicit delete paths and confirmation. PHP syntax and required bootstrap dependencies are validated before replacement.

== License ==

Free of charge to download, install, and use. Private modification is permitted. Redistribution of original or modified copies is prohibited without prior written permission. See LICENSE.md in the plugin distribution for the complete terms.

This is a custom proprietary/source-available license, not an open-source license and not GPL-compatible. This distribution is not intended for the WordPress.org Plugin Directory under its current license.

== Security ==

High-impact writes remain subject to the Bridge's preview, confirmation, state-hash, plan-hash, impact-hash, active-theme/plugin, and sensitive-key protections.

== Changelog ==

= 1.1.21 =
* Repair stale runtime guidance and capability catalogs after overlapping self-update requests by fingerprinting generated content, not only the disk version.

= 1.1.20 =
* Add bounded post/page candidate search and exact unique-title target binding; reject ambiguous names without changing any candidate.
* Add draft-default post/page creation and automatic post/page metadata routing, preserving deterministic command replay.
* Document ordinary native workflows and avoid unnecessary local wrapper scripts.

= 1.1.19 =
* Resolve user-supplied post target URLs locally and reject conflicting IDs before reads or writes.
* Add source commit and scoped command/execution timing to runtime results without extra network calls.

= 1.1.18 =
* Remove two GitHub requests from atomic result publication by creating the result in the tree request and reusing the verified pending blob for the completed command.
* Make native operation commands the single preferred route, reuse the known catalog, specify batch limitations, and distinguish stored changes from visual verification.

= 1.1.17 =
* Summarize uploaded binary input in runtime results instead of echoing Base64; preserve byte counts and SHA-256 for verification.

= 1.1.16 =
* Adds `/takka-v099/v1/operate`, a deterministic high-level operation router for common post, media, diagnostics, Workspace, read-only batch, and self-update workflows.
* Adds bounded post-content source range reads and exposes them through the deterministic router and read-only batch path.
* Generates concise canonical `AGENTS.md` and `WEBHOOK_RUNTIME.md` routing guidance so existing runtime repositories stop reintroducing stale route/media instructions.
* Keeps `post.get` metadata-oriented by default and blocks full post-content retrieval through that operation; source content uses dedicated search/range operations.
* Blocks post content replacement through generic `post.update`, requiring guarded content preview/apply instead.
* Enforces the 1 MiB deterministic inline-media threshold and validates optional expected byte count/SHA-256 before inline media upload.
* Normalizes documented high-level read operation names inside `readonly.batch`, removing a second action-name vocabulary.
* Adds clean-WordPress regression coverage for deterministic post read/update, bounded content access, small-media upload, featured-image assignment, batch aliases, and route registration.

= 1.1.15 =
* Prefer one inline `media.upload_base64` command for local or conversation-uploaded media up to 1 MiB decoded.
* Keep staged Media Fast Path for larger media and reserve verify-only for explicit verification or failure diagnosis.
* Publish the size-based routing rule through `RUNTIME_CAPABILITIES.json` so ChatGPT can choose the correct path without rediscovering media transport behavior.

= 1.1.14 =
* Commits Direct Runtime result creation, completed-command storage, and pending-command deletion in one Git tree/commit/ref update.
* Retries bounded non-fast-forward ref races against the latest runtime head while preserving unrelated concurrent changes and verifying the original pending-command blob SHA.
* Treats a visible matching result as the command-bookkeeping completion barrier, removing the former post-result branch-movement window that could make the next connector `create_file` hit 409.

= 1.1.13 =
* Adds strict read-only batching under `/takka-v098/v1/manage` so multiple independent search/read/inspect/status operations can share one pending command, webhook, and result.
* Preflights the full batch against explicit read-only action maps; generic REST calls and all known mutation actions are blocked.
* Bounds batches to 12 operations, 256 KiB params per operation, 1 MiB aggregate result, and a 20 second total budget with structured per-operation status and timing.
* Advertises the batch route, allowlist, limits, and preferred two-or-more-read fast path in `RUNTIME_CAPABILITIES.json`.

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
