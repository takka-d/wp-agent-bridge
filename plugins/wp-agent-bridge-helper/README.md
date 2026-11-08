# WP Agent Bridge Helper 0.3.1

One plugin folder for every client: ChatGPT desktop / Codex, Codex CLI, Claude Code, claude.ai, and Cowork. It packages the WP Agent Bridge workflow skill, declares the ChatGPT/Codex GitHub app as a required dependency, and ships a small GitHub transport script for shell-capable clients.

It does not replace the WordPress-side WP Agent Bridge plugin and it contains no WordPress or GitHub credentials. Every client still uses the user's own GitHub login against the user's own private runtime repository.

## What is in the folder

| File | Read by |
| --- | --- |
| `plugin.json` | Portable manifest (agent-plugins.org schema) with the `com.openai` extension |
| `.codex-plugin/plugin.json`, `.app.json` | ChatGPT desktop / Codex: plugin identity and the required GitHub app |
| `.claude-plugin/plugin.json` | Claude Code, claude.ai, Cowork |
| `skills/wp-agent-bridge/SKILL.md` | All clients. Frontmatter is limited to `name` and `description` so the same file is accepted by claude.ai uploads and by Codex |
| `skills/wp-agent-bridge/references/transports.md` | Exact GitHub calls per client |
| `skills/wp-agent-bridge/scripts/wpab.py` | Standard-library GitHub transport for Claude Code and Codex CLI: GitHub REST via `gh api` / `GH_TOKEN`, switching to a shallow clone + `git push` when a write is refused (`--via auto|api|git`): `connection`, `capabilities`, `submit`, `result --wait`, `publish-media` |
| `README.md`, `LICENSE.md` | This file and the WP Agent Bridge License 1.0 |

## What 0.3.x changes

- Adds Claude support without forking the skill: `.claude-plugin/plugin.json` and the repository-level `.claude-plugin/marketplace.json` make the same folder installable in Claude Code, claude.ai, and Cowork.
- Rewrites the skill to be client-neutral. The execution contract, recovery order, result verification, guarded writes, and media rules are unchanged; only the "which GitHub tool" part is split out into `references/transports.md`.
- Adds `scripts/wpab.py` so terminal clients publish commands and read results deterministically (explicit runtime branch, `created_at`, no re-publication of finished IDs, 5 s / 10 s result observation rule, media chunk batches of at most 20 per push). 0.3.1 adds the git store: when a hosted session refuses Contents-API writes with HTTP 403 (verified in Claude Code on the web), the script finishes the write with a shallow clone and `git push` instead of reporting an access error.
- Keeps the ChatGPT/Codex GitHub app requirement and marketplace entries from 0.2.x.

## Install: ChatGPT desktop / Codex

From the GitHub marketplace:

`codex plugin marketplace add takka-d/wp-agent-bridge --ref main`

Then reload ChatGPT/Codex, open Plugins, choose the WP Agent Bridge marketplace, and install or refresh WP Agent Bridge Helper. Connect the standard GitHub app when prompted.

From the distributed ZIP (`wp-agent-bridge-helper-0.3.1.zip`):

1. Extract the ZIP into `~/.codex/plugins/wp-agent-bridge-helper/` (Windows: `%USERPROFILE%\.codex\plugins\wp-agent-bridge-helper\`).
2. Add this entry to the `plugins` array of `~/.agents/plugins/marketplace.json` (create the file with `{"name":"personal-plugins","interface":{"displayName":"Personal Plugins"},"plugins":[]}` if it does not exist; never overwrite other entries):

```json
{
  "name": "wp-agent-bridge-helper",
  "source": { "source": "local", "path": "./.codex/plugins/wp-agent-bridge-helper" },
  "policy": { "installation": "AVAILABLE", "authentication": "ON_INSTALL" },
  "category": "Developer Tools"
}
```

3. Fully restart ChatGPT desktop / Codex, open Plugins, choose the personal marketplace, and install/enable WP Agent Bridge Helper.

## Install: Claude Code

```bash
claude plugin marketplace add takka-d/wp-agent-bridge
claude plugin install wp-agent-bridge-helper@wp-agent-bridge
```

Inside a session the same two steps are `/plugin marketplace add takka-d/wp-agent-bridge` and `/plugin install wp-agent-bridge-helper@wp-agent-bridge`. The skill then appears as `/wp-agent-bridge-helper:wp-agent-bridge` and also loads automatically when WordPress or WP Agent Bridge work comes up.

GitHub transport: `gh` must be installed and signed in with the user's own account (`gh auth login`). No token is written into any config file. To try the folder without installing: `claude --plugin-dir ./plugins/wp-agent-bridge-helper`.

## Install: claude.ai and Cowork

Either of these, in claude.ai **Customize > Plugins > Add**:

- **Add marketplace** with the repository URL `https://github.com/takka-d/wp-agent-bridge`, then install WP Agent Bridge Helper from it.
- **Upload plugin** with `wp-agent-bridge-helper-0.3.1.zip` (the ZIP holds the plugin folder contents at its root, which the upload accepts).

A plugin added on the claude.ai account also reaches Claude Code as a synced plugin at the next session start.

GitHub transport on these surfaces:

- Cowork or a chat linked to the Claude desktop app: the device shell runs `gh api` on the user's computer with the user's existing `gh` login. Connect any working folder first.
- Claude Code on the web / cloud sessions: attach the private runtime repository with push access in the session; `wpab.py` reads through `gh api` and writes through `git push` (the session proxy refuses API writes). Verified end to end on 2026-11-08 with a read-only `health` command.
- Plain claude.ai chat with no linked computer and no GitHub connector has no GitHub write path. The skill reports that limit instead of claiming WP Agent Bridge is unavailable. GitHub's hosted MCP server does not currently complete OAuth from claude.ai/Claude Desktop custom connectors, so this plugin intentionally does not bundle a `.mcp.json` entry for it.

## Updating

- Marketplace installs: refresh/update the plugin from the Plugins screen (`claude plugin update wp-agent-bridge-helper@wp-agent-bridge` for Claude Code).
- ZIP installs: replace the files in the same folder with the newer ZIP, then fully restart the client.

The plugin cannot bypass GitHub authorization, workspace policy, or provider permissions on any client.

Source: https://github.com/takka-d/wp-agent-bridge
