# WP Agent Bridge Helper 0.2.2

This plugin packages the WP Agent Bridge workflow for ChatGPT desktop/Codex and references the standard GitHub app as a required dependency.

It does not replace the WordPress-side WP Agent Bridge plugin and it does not contain WordPress or GitHub credentials.

## Install from the distributed ZIP

The ZIP distributed with WP Agent Bridge is the primary installation package.

1. Extract `wp-agent-bridge-helper-0.2.2.zip`.
2. Create the personal plugin directory if it does not already exist:
   - Windows: `%USERPROFILE%\.codex\plugins\wp-agent-bridge-helper\`
   - macOS/Linux: `~/.codex/plugins/wp-agent-bridge-helper/`
3. Copy the extracted plugin files into that `wp-agent-bridge-helper` directory. The directory must contain `plugin.json`, `.app.json`, `.codex-plugin/plugin.json`, `skills/`, and this README.
4. Create or update the personal marketplace file:
   - Windows: `%USERPROFILE%\.agents\plugins\marketplace.json`
   - macOS/Linux: `~/.agents/plugins/marketplace.json`
5. Ensure its `plugins` array contains this entry:

```json
{
  "name": "wp-agent-bridge-helper",
  "source": {
    "source": "local",
    "path": "./.codex/plugins/wp-agent-bridge-helper"
  },
  "policy": {
    "installation": "AVAILABLE",
    "authentication": "ON_INSTALL"
  },
  "category": "Developer Tools"
}
```

A minimal personal marketplace containing only this plugin is:

```json
{
  "name": "personal-plugins",
  "interface": {
    "displayName": "Personal Plugins"
  },
  "plugins": [
    {
      "name": "wp-agent-bridge-helper",
      "source": {
        "source": "local",
        "path": "./.codex/plugins/wp-agent-bridge-helper"
      },
      "policy": {
        "installation": "AVAILABLE",
        "authentication": "ON_INSTALL"
      },
      "category": "Developer Tools"
    }
  ]
}
```

If `marketplace.json` already contains other plugins, do not overwrite it; add the WP Agent Bridge Helper entry to the existing `plugins` array.

6. Fully quit and restart the ChatGPT desktop app or Codex.
7. Open Plugins, choose the personal marketplace, and install/enable WP Agent Bridge Helper.
8. Connect the standard GitHub app when required.

The plugin cannot bypass GitHub authorization, workspace policy, or provider permissions.

## Updating

For a ZIP-based installation, replace the files in the same `wp-agent-bridge-helper` directory with the files from the newer ZIP, then fully restart ChatGPT desktop/Codex.

Source: https://github.com/takka-d/wp-agent-bridge
