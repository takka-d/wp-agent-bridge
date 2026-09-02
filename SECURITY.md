# Security Policy

## Supported versions

Until 1.0.0, only the latest development release is supported for security fixes. After 1.0.0, this file will define the supported release line explicitly.

## Reporting a vulnerability

Do not publish credentials, exploit details, private WordPress data, or a working proof of concept in a public issue.

For a public repository, use a **private GitHub Security Advisory** when available. If private reporting is unavailable, open a minimal issue asking for a private contact channel without including exploit details.

## Security model

TakKa WordPress Bridge is an administrator-level automation bridge. The security boundary is therefore different from an ordinary content plugin.

### HMAC control plane

`WP_BRIDGE_SECRET` authenticates Bridge requests and should be treated as administrator-equivalent control-plane material.

The signature binds:

- Unix timestamp
- HTTP method
- WordPress REST route
- SHA-256 of the exact request body

Requests outside the accepted clock-skew window are rejected.

Compromise of `WP_BRIDGE_SECRET` is a control-plane compromise. Encrypted Git payloads do not change that fact: an attacker who can authenticate arbitrary Bridge requests can invoke the allowlisted administrative surface directly.

### Secure Git transport

Secure commands use a hybrid envelope:

- AES-256-GCM for request/response content
- RSA wrapping for the per-message AES key
- authenticated metadata binding for request ID, server key ID, and reply key

GitHub Actions forwards secure ciphertext but does not receive the request plaintext or the response plaintext.

Plain `rest`, `bridge`, and other non-secure command types may still place payload content in Git history. Use secure commands for sensitive content.

### Secrets at rest

From v0.9.2, the plugin attempts to encrypt the following WordPress options using AES-256-GCM with key material derived from WordPress salts:

- Bridge HMAC secret
- secure-transport RSA private key

This primarily reduces exposure from a **database-only** disclosure. It does not protect against an attacker who can also read the WordPress salts or execute PHP in the WordPress environment.

Advanced installations may define `TAKKA_BRIDGE_SECRET` in `wp-config.php`; that value takes precedence over the database copy.

Changing WordPress salts can make previously encrypted Bridge options undecryptable. Regenerate the Bridge key and rotate the secure server key after an intentional salt rotation.

### Secure server key rotation

The active RSA server key can be rotated. The plugin does not intentionally retain the previous active private key.

This is not perfect forward secrecy. Hosting snapshots, database backups, filesystem backups, hypervisor snapshots, or other infrastructure copies may retain historical secrets.

### GitHub Actions

The supplied workflows:

- declare explicit `GITHUB_TOKEN` permissions;
- pin third-party actions to full commit SHAs;
- restrict the worker to repository queue/result paths when committing execution results;
- validate command IDs before using them as result paths;
- require HTTPS for `WP_URL`, except explicit loopback-only development mode.

Repository write access and Actions secret administration remain high-trust capabilities.

## Out of scope by design

The Bridge does not expose arbitrary shell execution or arbitrary WP-CLI strings. A vulnerability that bypasses an allowlist, escapes a filesystem boundary, bypasses a confirmation/state guard, leaks credentials, or enables unauthenticated/under-authorized administration is security-sensitive and should be reported privately.
