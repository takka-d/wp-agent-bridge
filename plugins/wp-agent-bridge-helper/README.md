# WP Agent Bridge Helper 0.2.2

This plugin packages the WP Agent Bridge workflow for ChatGPT and Codex and references the standard GitHub app as a required dependency.

It does not replace the WordPress-side WP Agent Bridge plugin and it does not contain WordPress or GitHub credentials. It declares the standard GitHub app as required and adds a reusable WP Agent Bridge skill.

## What 0.2.2 changes

- Keeps the ChatGPT/Codex and required-GitHub-app integration introduced in 0.2.0.
- Forbids claiming that GitHub or WP Agent Bridge is unavailable merely because the model does not see an expected tool name in its apparent tool roster.
- Requires an actual GitHub dependency/tool attempt or one app/plugin discovery attempt before any unavailable/not-connected conclusion.
- If discovery finds GitHub installed/enabled, requires the model to continue the original WordPress task immediately instead of asking the user to repeat it.
- Treats @GitHub as a last-resort client workaround, not the normal requirement when WP Agent Bridge Helper itself has been invoked.
- Keeps success verification tied to the matching WP Agent Bridge result file rather than to helper activation or a GitHub write alone.

## Install from the GitHub marketplace

Add the repository marketplace once:

`codex plugin marketplace add takka-d/wp-agent-bridge --ref main`

Then reload ChatGPT/Codex, open Plugins, choose the WP Agent Bridge marketplace/source, and install or refresh WP Agent Bridge Helper.

The GitHub app still has to be available to the current ChatGPT/Codex surface and authorized for the intended account/repositories. Plugin installation cannot bypass provider authorization or workspace restrictions.

Source: https://github.com/takka-d/wp-agent-bridge
