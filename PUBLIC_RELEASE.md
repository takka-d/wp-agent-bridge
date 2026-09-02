# WP Agent Bridge Public Release Checklist

## Do not make the development repository public

The current development repository contains unrelated project data and historical runtime queue/result commits. A public release must be created in a **new repository with fresh Git history**.

Deleting runtime files in a later commit is not sufficient because earlier commits remain reachable from Git history.

## Public repository contents

Initialize the public repository from the clean generated public-source artifact, not by mirroring the development repository.

The public distribution must intentionally exclude:

- central/operator Onboarding Service source;
- diagnostics/test helper plugins;
- existing command/result JSON;
- unrelated project directories;
- credentials, tokens, private keys, development-only data;
- Git history from the development repository.

## Public onboarding target

The end-user setup remains six user-visible steps:

1. Download the WP Agent Bridge ZIP.
2. Install and activate it in WordPress.
3. Select `Connect GitHub` in `Tools > WP Bridge Setup`.
4. Complete the GitHub authorization flow.
5. Add the automatically created runtime repository to ChatGPT's GitHub connection.
6. Ask ChatGPT to update WordPress.

Repository creation, runtime initialization, branch/path creation, secret generation, and WordPress pairing are automated behind the onboarding flow.

## License

The public source is source-visible but is **not open source**. It uses the `WP Agent Bridge License 1.0`:

- download/install/use: free of charge;
- private modification: permitted;
- redistribution of original or modified copies: prohibited without prior written permission;
- GitHub-hosted public repositories remain subject to GitHub's platform Terms of Service.

The license is custom, proprietary/source-available, and not GPL-compatible.

## WordPress.org

Do not submit the current licensed distribution to the WordPress.org Plugin Directory under this license.

## Release gate

Verified for the 1.0.0 release candidate:

- [x] main plugin contains guided onboarding client functionality;
- [x] GitHub authorization is limited to public read-only + repository invitation handling for the temporary setup OAuth flow;
- [x] dedicated private runtime repository is automatically created in the runtime Organization;
- [x] runtime branch and command/result paths are initialized automatically;
- [x] temporary Setup OAuth grant is revoked after onboarding;
- [x] ChatGPT can be connected to only the generated runtime repository while the existing personal GitHub installation remains intact;
- [x] ChatGPT can write a command into the generated runtime repository;
- [x] signed Webhook relay executes the command in WordPress and writes the result back;
- [x] processed command moves from `commands/pending` to `commands/completed`;
- [x] normal command transport does not use GitHub Actions;
- [x] plugin header Version and `readme.txt` Stable tag are set to 1.0.0;
- [x] plugin package includes `LICENSE.md`;
- [x] public package build excludes central Onboarding Service and development runtime data by construction.

Still required before publishing the download/article:

- [ ] current 1.0.0 package workflow completes successfully;
- [ ] generated ZIP is installed on a separate clean supported WordPress instance;
- [ ] fresh-user onboarding is tested once without relying on a prior OAuth grant;
- [ ] content preview/apply stale-write rejection is rechecked on the packaged build;
- [ ] active theme/plugin and sensitive-key protections are rechecked on the packaged build;
- [ ] rollback path is rechecked on the packaged build;
- [ ] clean public repository is created from the generated public-source ZIP with fresh history;
- [ ] TakKa Note download/article license notice matches `LICENSE.md`.
