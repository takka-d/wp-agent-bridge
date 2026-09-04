# Security Policy

## Supported versions

The current self-contained release line is **1.1.x**. The current release candidate is **1.1.2**.

The older operator-relay / GitHub Actions transport generation is not the current supported architecture for normal end-user operation.

## Reporting a vulnerability

Do not publish credentials, exploit details, private WordPress data, private runtime repository contents, or a working proof of concept in a public issue.

For a public repository, use a **private GitHub Security Advisory** when available. If private reporting is unavailable, open a minimal issue asking for a private contact channel without including exploit details.

## Security model

WP Agent Bridge is an administrator-level automation bridge. A compromise of its authenticated control path can therefore become equivalent to a WordPress administrator compromise.

The current normal transport is self-contained:

`ChatGPT -> user-owned private runtime repository -> site-specific GitHub App signed Webhook -> user's WordPress -> same runtime repository -> ChatGPT`

Normal runtime traffic does not pass through an operator-owned relay, operator-owned runtime repository, per-command GitHub Actions worker, WPVibe, or `takka-d/chatgpt-data`.

### Direct GitHub Webhook authentication

The public WordPress Webhook route accepts GitHub events, but processing begins only after the raw request body passes SHA-256 HMAC verification with the **site-specific GitHub App Webhook secret**.

For push processing, WP Agent Bridge additionally requires all of the following to match the stored Direct Runtime connection:

- event type is `push`;
- ref is exactly `refs/heads/wp-agent-bridge-runtime`;
- repository is private;
- repository full name is valid and exactly matches the stored repository;
- repository ID exactly matches the stored repository ID;
- GitHub App installation ID exactly matches the stored installation ID;
- the pushed commit SHA is structurally valid.

A correctly signed Webhook from a different repository or App installation is therefore not sufficient to execute commands for the connected site.

GitHub delivery IDs are tracked temporarily so duplicate delivery of the same Webhook does not re-run the same push while it is processing or after completion.

### GitHub App credentials

Each WordPress site uses a site-specific private GitHub App created through the onboarding manifest flow.

The App private key and Webhook secret are stored only in that WordPress installation and are encrypted at rest with **AES-256-GCM**, using key material derived from WordPress salts.

The App private key is used to sign short-lived GitHub App JWTs. WordPress then requests installation tokens for the configured installation/repository and uses those tokens to read commands and write results back to the user's private runtime repository.

Installation tokens are short-lived and cached only temporarily.

Changing WordPress salts can make previously encrypted Direct GitHub credentials undecryptable. After an intentional salt rotation, reconnect the GitHub App if necessary.

Database encryption primarily reduces exposure from a **database-only** disclosure. It does not protect against an attacker who can also read the WordPress salts or execute PHP in the WordPress environment.

### Runtime repository boundary

The Direct Runtime accepts command files only from the configured private repository and runtime branch.

Normal command files must be located under:

`wordpress-bridge/commands/pending/<id>.json`

Command size is bounded to 2 MiB. IDs and request IDs are restricted to a conservative filename-safe character set and length.

WordPress writes results to the same user-owned repository under `wordpress-bridge/results/`, records the original command under `wordpress-bridge/commands/completed/`, and removes the pending command after successful bookkeeping.

Result serialization redacts values whose keys indicate common secret material such as tokens, authorization values, passwords, private keys, cookies, nonces, or secrets.

### WordPress-internal Bridge authentication

Direct Runtime does not expose the internal Bridge core as an unauthenticated administrative surface.

After a valid GitHub command is accepted, Direct Runtime invokes the guarded Bridge REST handlers **inside the same WordPress process** using `rest_do_request()`. Those internal requests are authenticated with the Bridge HMAC secret and bind:

- Unix timestamp;
- HTTP method;
- local REST route;
- SHA-256 of the exact request body.

This internal HMAC layer is distinct from the external GitHub Webhook signature. End users do not send the Bridge HMAC secret through the runtime repository as part of normal Direct Runtime operation.

### Guarded administrative surface

WP Agent Bridge does not intentionally expose arbitrary shell execution, arbitrary WP-CLI strings, or unrestricted SQL writes.

Administrative operations use explicit actions/routes and, depending on the operation, additional guards including:

- preview / confirm stages;
- expected SHA-256 checks;
- plan hash / impact hash checks;
- stale-write rejection;
- request-ID idempotency;
- active plugin/theme protection;
- sensitive option protection;
- filesystem path validation;
- self-update full-manifest validation and rollback controls.

A vulnerability that bypasses one of these boundaries is security-sensitive.

### Request-ID idempotency and recovery

Every side-effecting Direct Runtime command should use a unique `request_id`.

If the same completed `request_id` and identical payload are seen again, WordPress replays the stored completed response rather than repeating the side effect. Reuse of the same request ID with a different payload is rejected.

This protects against a failure pattern such as:

`WordPress mutation succeeds -> GitHub result/completed write fails`

A later valid push can reconcile pending bookkeeping without repeating the WordPress mutation.

Direct Runtime also has a loop guard so GitHub pushes created by its own result/media bookkeeping do not recursively execute the same in-progress request ID.

### Media transport

The decoded WordPress media limit is 6 MiB. A runtime command JSON itself remains limited to 2 MiB.

Large media should not be embedded as one complete Base64 value inside a command JSON. The normal self-contained path stores one or more Base64 payload files under the user's own private runtime repository at `wordpress-bridge/media/pending/` and references them from a small media command.

Before creating a Media Library attachment, WordPress verifies the reconstructed original file against both `expected_bytes` and `expected_sha256`. Temporary payload files are removed after successful registration.

A bounded authenticated chunk route remains available as a fallback and performs chunk and whole-file integrity validation.

### Self-update safety

Plugin self-update requires a complete manifest rather than treating omitted files as implicit deletions.

The update path validates submitted file SHA-256 values, canonical manifest SHA-256, PHP syntax, required PHP dependencies, explicit deletion paths, and backup/rollback state before replacement.

A live production migration should still retain an independent recovery path until the new runtime has passed health and required end-to-end checks.

## Legacy transport notes

Older development/release generations used operator relay and GitHub Actions-based transport components. Those descriptions may still exist in repository history for audit purposes, but they are **not** the current normal runtime architecture.

Do not configure a new installation around an old Organization runtime, old Bridge Key workflow, or per-command GitHub Actions worker.

## Out of scope by design

The following are considered security-sensitive if unintentionally exposed or bypassed:

- arbitrary shell or arbitrary WP-CLI execution;
- unrestricted SQL writes;
- filesystem traversal outside an allowed root;
- bypass of confirmation/state/hash guards;
- unauthorized plugin/theme activation or modification;
- credential or private runtime data disclosure;
- accepting a Webhook from the wrong repository or installation;
- replay that repeats a completed WordPress side effect;
- unauthenticated or under-authorized administrator-level execution.
