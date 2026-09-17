# Direct Runtime delivery fallback

The canonical Direct Runtime remains webhook-first: GitHub push events are delivered to the WordPress `github-webhook` route and processed immediately.

Starting with 1.1.28, WordPress also schedules a bounded reconciliation pass for the canonical private runtime repository. This is a safety net for missed or temporarily undeliverable GitHub push webhooks; it is not a second transport and does not use GitHub Actions or an operator relay.

Key invariants:

- The stored Direct Runtime mapping and GitHub App installation are revalidated before scheduled reconciliation.
- The scheduled pass synthesizes the same authenticated runtime push internally, so Direct Runtime V2 reuses its existing pending-directory scan and recovery logic.
- Existing result files, request-id idempotency, in-flight guards, recovery age limits, and atomic bookkeeping remain authoritative.
- Recovery stays bounded to the existing per-pass command limit.
- The fallback is scheduled through WP-Cron every 120 seconds. WP-Cron still depends on normal WordPress cron execution/site traffic unless a real system cron drives `wp-cron.php`.
- A missing/unreadable result never authorizes mutation replay.

This fallback exists specifically so a transient GitHub webhook delivery failure cannot leave otherwise valid pending commands permanently stranded.
