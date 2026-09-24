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

## Install

### Recommended: GitHub marketplace

Add the repository marketplace once:

`codex plugin marketplace add takka-d/wp-agent-bridge --ref main`

Then restart the ChatGPT desktop app or Codex, open Plugins, choose the WP Agent Bridge marketplace/source, and install WP Agent Bridge Helper.

To refresh the marketplace later:

`codex plugin marketplace upgrade wp-agent-bridge`

### ZIP package

The downloadable ZIP is a distribution/archive package, not a one-click installer for the normal local Codex plugin flow. A manual ZIP installation requires extracting the plugin files into a local plugin directory and exposing that directory through a personal or repository marketplace as described in OpenAI's local marketplace documentation.

For most individual users, the GitHub marketplace command above is the simpler supported path. Workspace administrators can instead import the GitHub marketplace from Workspace settings > Plugins > Add > Import marketplace when that workspace feature is available.

The GitHub app still has to be available to the current ChatGPT/Codex surface and authorized for the intended account/repositories. Plugin installation cannot bypass provider authorization or workspace restrictions.

Source: https://github.com/takka-d/wp-agent-bridge
