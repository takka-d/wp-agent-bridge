# GitHub transport by client

WP Agent Bridge never needs a client-specific API. Every client only has to do two things against the user's private runtime repository:

1. Write one JSON file to `wordpress-bridge/commands/pending/<id>.json` on branch `wp-agent-bridge-runtime`.
2. Read `wordpress-bridge/results/<id>.json` from the same branch.

The repository name is fixed by the WordPress admin screen (**Tools > WP Agent Bridge**). The branch is always `wp-agent-bridge-runtime`. Pass both explicitly on every call; a read without `ref` lands on the default branch, which only mirrors read-only bootstrap files (`README.md`, `AGENTS.md`, `RUNTIME_CONNECTION.json`, `RUNTIME_CAPABILITIES.json`).

| Client | Transport | Notes |
| --- | --- | --- |
| ChatGPT (web/desktop) with the GitHub app | GitHub app actions (`create_file`, file read) | Declared as required in `.app.json`. Set `branch`/`ref` on every call. |
| Codex (desktop app) | Same GitHub app | Same as ChatGPT. |
| Codex CLI | `scripts/wpab.py` (uses `gh api`) or `gh api` directly | `gh auth status` must succeed for the user's own account. |
| Claude Code (terminal, IDE, desktop Code tab) | `scripts/wpab.py` (uses `gh api`), `gh api`, or a configured GitHub MCP server | `${CLAUDE_SKILL_DIR}/scripts/wpab.py` resolves to this skill's folder. |
| Claude Code on the web / cloud session | Attach the runtime repository with the session's repository tool (`add_repo`, access `push`) first, then `scripts/wpab.py` or `gh api` | Anonymous read-only access is not enough; the runtime repository is private and needs a write. |
| claude.ai / Cowork with the desktop app linked to the user's computer | Device shell on the user's computer running `gh api` (or `wpab.py` copied once into a connected folder) | Uses the user's existing `gh auth` login; nothing is stored in the plugin. |
| claude.ai chat only (web/mobile, no linked computer, no GitHub connector) | None available by default | Report the missing transport precisely and name one of the rows above. GitHub's hosted MCP server does not currently complete OAuth from claude.ai/Claude Desktop custom connectors, so do not promise that route. |

The user-owned principle is unchanged on every row: the runtime repository, the GitHub App, and the login used by the client all belong to the user. The helper plugin ships no credentials.

## `scripts/wpab.py` (shell-capable clients)

Standard library only. Resolves the GitHub transport in this order: `gh api` (whenever `gh` is installed; gh honors the user's login or `GH_TOKEN`, and hosted sessions can serve `gh api` for an attached repository even when `gh auth status` reports no login) → `GITHUB_TOKEN` / `GH_TOKEN` environment variable with the REST API when `gh` is absent. The repository comes from `--repo owner/name` or the `WPAB_REPO` environment variable; the branch defaults to `wp-agent-bridge-runtime`.

```bash
# Claude Code: ${CLAUDE_SKILL_DIR} is this skill's folder. Codex CLI: use the plugin path instead.
WPAB="python3 ${CLAUDE_SKILL_DIR}/scripts/wpab.py --repo OWNER/RUNTIME_REPO"

$WPAB connection                       # wordpress-bridge/RUNTIME_CONNECTION.json (only when a signal requires it)
$WPAB capabilities --operations        # operation names from RUNTIME_CAPABILITIES.json
$WPAB submit --id post-get-123 --operation post.get --params '{"post_id":123}'
$WPAB submit --file ./command.json     # publish an exact command JSON you already prepared
$WPAB result post-get-123 --wait       # 5 s, then one more read at >=10 s; prints outcome header
$WPAB result post-get-123 --full       # whole result JSON after the header looked fine
$WPAB publish-media ./prepared-dir     # output dir of wordpress-bridge/prepare-media.py; <=20 commands per push
```

`submit` refuses to overwrite an existing pending file with a different payload, refuses to re-publish an ID that already has a result, and adds `created_at` (UTC) when the command lacks it. `result --wait` implements the observation rule from the Bridge's `AGENTS.md`: first read ≥5 s after submission, at most one more at ≥10 s, otherwise `pending_or_unknown`.

`publish-media` reads `git-tree.json` from the directory `prepare-media.py` created and publishes its entries in ascending `chunk_index` order with the Git Data API (one tree + commit + ref update per batch of ≤20 commands). It skips chunk commands whose result file already exists, and it never rewrites a chunk payload.

## Raw `gh api` recipes (when the script cannot be used)

Read a runtime file as raw text:

```bash
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/OWNER/REPO/contents/wordpress-bridge/RUNTIME_CAPABILITIES.json?ref=wp-agent-bridge-runtime"
```

Publish one pending command (file must not already exist; a 422 means it does, so check its result instead of re-publishing):

```bash
gh api -X PUT "repos/OWNER/REPO/contents/wordpress-bridge/commands/pending/ID.json" \
  -f message="WP Agent Bridge: publish command ID" \
  -f branch=wp-agent-bridge-runtime \
  -f content="$(python3 -c 'import base64,sys;print(base64.b64encode(open(sys.argv[1],"rb").read()).decode())' ID.json)"
```

Read the result header (first 80 lines) and then, if needed, the full file:

```bash
gh api -H "Accept: application/vnd.github.raw+json" \
  "repos/OWNER/REPO/contents/wordpress-bridge/results/ID.json?ref=wp-agent-bridge-runtime" | head -80
```

Atomic batch of prepared media chunk commands (Git Data API; `git-tree.json` from `prepare-media.py` is already in Create-Tree format):

1. `GET repos/OWNER/REPO/git/ref/heads/wp-agent-bridge-runtime` → head commit SHA.
2. `GET repos/OWNER/REPO/git/commits/<head>` → `tree.sha`.
3. `POST repos/OWNER/REPO/git/trees` with `{"base_tree": "<tree.sha>", "tree": [<=20 entries from git-tree.json]}`.
4. `POST repos/OWNER/REPO/git/commits` with `{"message": "...", "tree": "<new tree>", "parents": ["<head>"]}`.
5. `PATCH repos/OWNER/REPO/git/refs/heads/wp-agent-bridge-runtime` with `{"sha": "<new commit>"}` (no force). On 422, re-read the head once and repeat from step 2 for the same batch.

## Claude Code on the web / cloud sessions

The session's git proxy serves anonymous reads of public repositories only. The runtime repository is private and must be written, so call the session's repository attach tool with push access for `OWNER/RUNTIME_REPO` before the first command. If the attach is refused by policy, report that exact category (repository access / session policy); it is not a WP Agent Bridge error, and no other transport in that session can substitute for it.

## claude.ai / Cowork with a linked computer

The device shell is scoped to folders the user connected in the Claude desktop app. Ask the user to connect any working folder once, confirm `gh auth status` succeeds there, then run the `gh api` recipes above from that folder, or place `wpab.py` into the folder with the device file tools and run it. The plugin does not install anything on the computer by itself.

## What is not a transport

- WPVibe or any other third-party WordPress connector: usable only when the task explicitly needs browser DOM/event behavior; never for normal Bridge work.
- GitHub Actions: source CI/package/release only, never a per-command worker.
- The old operator relay, old Bridge Key, or a legacy operator-owned runtime repository: retired.
