# Direct runtime prototype

This branch prototypes a WP Agent Bridge runtime in which normal WordPress operations do not traverse an operator-owned relay server.

## Non-negotiable goals

- Normal runtime path: ChatGPT -> GitHub -> user's WordPress -> GitHub -> ChatGPT.
- No GitHub Actions in the normal command path.
- No operator-owned relay in the normal command path.
- WordPress content/settings/results are not centralized on an operator server.
- Runtime repository is private.
- GitHub access is least-privilege and limited to the runtime repository where practical.
- User-visible onboarding must stay close to the existing Connect GitHub flow and must not require manual token copying.

## Confirmed GitHub constraints

1. A GitHub App has one app-level webhook URL. A shared app therefore cannot directly point its app webhook at a different WordPress URL for every installation.
2. Repository webhooks can have per-repository URLs, but creating them requires repository Webhooks write permission and an authenticated token.
3. Installation access tokens require the GitHub App private key and expire after one hour.
4. A GitHub App Manifest can create a site-owned GitHub App and returns its App ID, client credentials, private key and webhook secret to the site that completes the manifest exchange.
5. A GitHub App requiring repository permissions cannot currently be installed with zero selected repositories when "Only select repositories" is used. This blocks a pure "install first, create private repo second" least-privilege bootstrap.
6. A GitHub App user access token can create a repository for the authenticated user with Administration write permission, but the app/user token still depends on app installation access; the current pre-install approach returned 403 in prior testing.
7. GitHub documents that a repository created by an installed GitHub App is automatically added to that installation.
8. GitHub recommends minimum permissions, webhook-driven operation instead of polling, and respecting primary/secondary rate limits.

## Prototype direction

### Runtime

Per WordPress site:

- site-specific GitHub App created with GitHub App Manifest,
- webhook URL points directly to the site's WP Agent Bridge REST endpoint,
- private key and webhook secret are stored only in that WordPress installation,
- app installation is restricted to the site's runtime repository,
- Contents: read/write,
- Metadata: read,
- no Actions or Workflows permission,
- no operator relay.

The WordPress plugin creates short-lived installation access tokens locally and uses them to read commands and write results.

### Bootstrap problem still under test

The remaining design problem is creation of the first private runtime repository without either:

- granting temporary broad access to existing repositories,
- using a long-lived/manual PAT,
- using an operator-owned OAuth relay,
- or adding too many user steps.

The prototype must test the following in order:

1. Whether a site-specific Manifest-created GitHub App can complete a minimal install/authorize/create/reinstall flow with repository preselection and no manual token handling.
2. Whether a private runtime repository can be created with only a short-lived local credential and immediately constrained to that repository.
3. If not, measure the minimum additional user action required for a secure bootstrap (for example one explicit GitHub "Create private repository" confirmation) instead of silently granting broad repository access.

## User-visible target

Preferred flow remains:

1. Install/activate WP Agent Bridge.
2. Click Connect GitHub.
3. Approve GitHub configuration/installation screens.
4. Connect GitHub to ChatGPT if not already connected.
5. Ask ChatGPT to update WordPress.

The prototype is not considered successful if it requires users to copy PATs, edit repository webhooks manually, edit JSON, create branches/files manually, or configure GitHub Actions.

## Limit/rate-limit acceptance criteria

Normal operation must not introduce a WP Agent Bridge-specific monthly operation quota.

The implementation must:

- use GitHub push webhooks rather than polling,
- cache installation tokens until near expiry instead of minting one per API call,
- batch GitHub content operations where reasonable,
- honor `Retry-After` and rate-limit reset headers,
- use exponential backoff for secondary rate-limit responses,
- avoid per-command GitHub Actions runs,
- avoid background repository/App creation loops.

Remaining limits are platform limits from ChatGPT, GitHub and the user's WordPress/server; the product must not describe these as unlimited.

## Security acceptance criteria

Before stable release verify that:

- stopping the operator website/server does not break normal command execution,
- no operator endpoint receives command bodies, result bodies, WordPress article text or settings during normal operation,
- GitHub webhook signatures are verified before processing,
- installation tokens are repository-scoped and short-lived,
- the site-specific private key/webhook secret are never committed to GitHub,
- active plugin/theme and sensitive-option guards remain enforced,
- disconnect removes or disables local credentials and documents GitHub-side cleanup,
- a second tester cannot access another tester's runtime repository.

## Release status

Do not promote RC1 to stable until this prototype is validated or a different architecture is explicitly chosen after measuring the security/UX trade-offs.
