# WP Agent Bridge Helper 0.2.0

This optional plugin helps ChatGPT and Codex keep using WP Agent Bridge consistently in long-running WordPress conversations.

It does not replace the WordPress-side WP Agent Bridge plugin, and it does not contain WordPress or GitHub credentials. It declares the standard GitHub app as required and adds a reusable WP Agent Bridge skill.

## What 0.2.0 changes

- Supports both ChatGPT and Codex rather than treating the helper as Codex-only.
- Declares the standard GitHub app as a required app for the plugin.
- Reuses a verified WP Agent Bridge runtime instead of repeatedly rediscovering it.
- Sends concrete WordPress work to `wordpress-bridge/commands/pending/<id>.json` instead of stopping at connection checks.
- When GitHub tools disappear after they worked earlier in the same conversation, treats that as a client-side tool-exposure change, not as evidence that WP Agent Bridge stopped working.
- Requires one GitHub app/plugin discovery attempt before claiming that GitHub is unavailable.
- Separates GitHub tool exposure, GitHub authorization, repository access, Bridge transport, and WordPress operation failures.

## Install

Import or upload `wp-agent-bridge-helper-0.2.0.zip` as a plugin in a supported ChatGPT/Codex plugin environment.

The GitHub app still has to be available to the current ChatGPT/Codex surface and connected to the intended GitHub account. Plugin installation cannot bypass GitHub authorization or workspace restrictions.

If a conversation suddenly says it cannot use WP Agent Bridge even though it used it earlier, invoke the installed helper (or select it with `@` / the plugin picker where available). The helper instructs the model to rediscover GitHub once before concluding that the tool is unavailable.

Source: https://github.com/takka-d/wp-agent-bridge
