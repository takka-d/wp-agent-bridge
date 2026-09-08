# Legacy self-update bootstrap

This directory exists only to move an already-installed **WP Agent Bridge 1.1.5** onto the small pinned-source self-update path introduced in 1.1.7.

## Intended agent behavior

- Normal users must **not** manually upload a large self-update JSON file.
- For 1.1.7 and later, use the normal compact `bridge.self_update.apply` source mode (`source_commit`, `target_version`, aggregate `manifest_sha256`, `expected_file_count`, `expected_current_version`, `confirm=true`).
- Only when the installed version is exactly 1.1.5, generate the tested one-time migration command with `build-command.py` and write that compact JSON directly to the user's canonical runtime `wordpress-bridge/commands/pending/` path.
- The legacy command installs one small bootstrap file. It requires the 1.1.5 self-update rollback backup before proceeding, pins the public `takka-d/wp-agent-bridge` source to commit `9a034f443441112d862762eb13898071d7016c6f`, verifies the 72-file 1.1.7 manifest and PHP syntax, writes the plugin bootstrap last, and then leaves future upgrades to normal source mode.
- The bootstrap also keeps the Direct Runtime webhook route alive while the one-time migration is in progress, so GitHub deliveries do not need a user-side manual wake-up step.
- After migration, verify with a **separate** `bridge.self_update.status` command and then one ordinary Bridge command. Do not treat the migration as complete before both succeed.

## Build/check

```bash
python tools/legacy-self-update/build-command.py --check
python tools/legacy-self-update/build-command.py --id <unique-id> --output /tmp/<unique-id>.json
```

The builder verifies the pinned 1.1.7 plugin tree directly from Git objects, checks the target aggregate manifest, checks the authoritative 1.1.5 file list, Base64 round-trips the bootstrap, recalculates its per-file and one-file manifest SHA-256 values, and refuses to produce a command at or above 64 KiB.

This is a **migration compatibility tool**, not the normal update transport.
