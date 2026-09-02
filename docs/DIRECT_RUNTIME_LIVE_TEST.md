# Direct runtime live test

Purpose: validate the direct GitHub -> WordPress runtime on takka-note.com before any further release preparation.

Test order:

1. Keep the currently working bridge available for rollback.
2. Install the direct-runtime prototype separately from the currently active plugin.
3. Complete a site-specific GitHub App Manifest flow whose webhook points directly to the WordPress REST endpoint.
4. Create/select one private runtime repository only.
5. Send a health command through the new repository.
6. Verify command/result round-trip without the operator relay and without GitHub Actions.
7. Only after success, consider replacing the old runtime.

Do not merge to main or create a stable release during this test.
