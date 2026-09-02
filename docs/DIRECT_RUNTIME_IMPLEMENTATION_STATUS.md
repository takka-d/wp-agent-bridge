# Direct runtime implementation status

Prototype branch only. Do not merge to `main` or promote RC1 to stable until the direct path is proven end to end.

## Current implementation stages

- [x] Architecture and acceptance criteria fixed.
- [ ] Site-specific GitHub App Manifest onboarding implemented.
- [ ] GitHub App private key and webhook secret stored encrypted only on the user's WordPress site.
- [ ] GitHub push webhook delivered directly to the user's WordPress site.
- [ ] WordPress locally creates and caches installation access tokens.
- [ ] Commands are read from the private runtime repository and executed locally.
- [ ] Results/completed state are written back to the private runtime repository.
- [ ] Operator relay is absent from normal runtime traffic.
- [ ] Existing runtime repository migration E2E passes.
- [ ] Fresh-user bootstrap measured for user-visible steps and GitHub permissions.
- [ ] Rate-limit/API-call measurements recorded.
- [ ] External-user isolation test passes.

## UX guardrail

The prototype is rejected if normal setup requires manual PAT copying, webhook editing, JSON editing, branch/file creation, or GitHub Actions configuration.
