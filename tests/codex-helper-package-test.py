"""Validate the distributable WP Agent Bridge Helper plugin ZIP against its source.

The helper is one folder for ChatGPT/Codex and Claude (Claude Code, claude.ai, Cowork), so
this test checks the Codex manifests, the Claude manifest, the repository-level Claude
marketplace, the shared skill, and the bundled transport script together.
"""
import ast
import json
from pathlib import Path
import re
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "plugins" / "wp-agent-bridge-helper"
VERSION = "0.3.1"
ZIP = ROOT / "downloads" / "wp-agent-bridge-helper-{}.zip".format(VERSION)

required = [
    "plugin.json",
    ".app.json",
    ".codex-plugin/plugin.json",
    ".claude-plugin/plugin.json",
    "skills/wp-agent-bridge/SKILL.md",
    "skills/wp-agent-bridge/references/transports.md",
    "skills/wp-agent-bridge/scripts/wpab.py",
    "README.md",
    "LICENSE.md",
]

with zipfile.ZipFile(ZIP) as archive:
    if sorted(archive.namelist()) != sorted(required):
        raise SystemExit("WP Agent Bridge Helper ZIP file list mismatch: {}".format(sorted(archive.namelist())))
    for relative in required:
        expected = (SOURCE / relative).read_bytes()
        actual = archive.read(relative)
        if actual != expected:
            raise SystemExit(f"WP Agent Bridge Helper ZIP content mismatch: {relative}")

# --- portable manifest + OpenAI extension (ChatGPT desktop / Codex) ---------------
manifest = json.loads((SOURCE / "plugin.json").read_text(encoding="utf-8"))
if manifest.get("$schema") != "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json":
    raise SystemExit("Portable plugin schema missing")
if manifest.get("name") != "wp-agent-bridge-helper" or manifest.get("version") != VERSION:
    raise SystemExit("WP Agent Bridge Helper identity mismatch")

openai = ((manifest.get("extensions") or {}).get("com.openai") or {})
if openai.get("apps") != "./.app.json":
    raise SystemExit("OpenAI app manifest mapping missing")
interface = openai.get("interface") or {}
if interface.get("displayName") != "WP Agent Bridge Helper":
    raise SystemExit("OpenAI display name mismatch")
if "Write" not in (interface.get("capabilities") or []):
    raise SystemExit("OpenAI write capability missing")

apps = json.loads((SOURCE / ".app.json").read_text(encoding="utf-8"))
github = ((apps.get("apps") or {}).get("github") or {})
if github.get("id") != "connector_76869538009648d5b282a4bb21c3d157":
    raise SystemExit("Canonical GitHub app mapping missing")
if github.get("required") is not True:
    raise SystemExit("GitHub app must be required")

compat = json.loads((SOURCE / ".codex-plugin" / "plugin.json").read_text(encoding="utf-8"))
if compat.get("apps") != "./.app.json" or compat.get("skills") != "./skills/":
    raise SystemExit("Compatibility plugin mappings missing")
if compat.get("name") != "wp-agent-bridge-helper" or compat.get("version") != VERSION:
    raise SystemExit("Compatibility plugin identity mismatch")

# --- Claude manifest (Claude Code / claude.ai / Cowork) ------------------------------
claude = json.loads((SOURCE / ".claude-plugin" / "plugin.json").read_text(encoding="utf-8"))
if claude.get("name") != "wp-agent-bridge-helper" or claude.get("version") != VERSION:
    raise SystemExit("Claude plugin identity mismatch")
if not re.fullmatch(r"[a-z0-9]+(-[a-z0-9]+)*", claude["name"]):
    raise SystemExit("Claude plugin name must be kebab-case")
for field in ("description", "author", "license", "homepage", "repository"):
    if not claude.get(field):
        raise SystemExit(f"Claude plugin manifest missing {field}")
if not (claude["author"] or {}).get("name"):
    raise SystemExit("Claude plugin author.name missing")
if "claude" not in (claude.get("keywords") or []):
    raise SystemExit("Claude plugin keywords should include claude")
if (SOURCE / ".mcp.json").exists():
    raise SystemExit("Helper must not bundle an MCP server: GitHub's hosted MCP cannot complete OAuth from claude.ai")
if (SOURCE / "bin").exists():
    raise SystemExit("A top-level bin/ directory would block claude.ai and Cowork installs")

marketplace = json.loads((ROOT / ".claude-plugin" / "marketplace.json").read_text(encoding="utf-8"))
if marketplace.get("name") != "wp-agent-bridge" or not (marketplace.get("owner") or {}).get("name"):
    raise SystemExit("Claude marketplace identity mismatch")
entries = [entry for entry in marketplace.get("plugins") or [] if entry.get("name") == "wp-agent-bridge-helper"]
if len(entries) != 1 or entries[0].get("source") != "./plugins/wp-agent-bridge-helper":
    raise SystemExit("Claude marketplace must list ./plugins/wp-agent-bridge-helper once")
if "version" in entries[0]:
    raise SystemExit("Keep version in plugin.json only; a marketplace entry version triggers validate warnings")
if not (ROOT / entries[0]["source"]).is_dir():
    raise SystemExit("Claude marketplace source path does not exist")

codex_marketplace = json.loads((ROOT / ".agents" / "plugins" / "marketplace.json").read_text(encoding="utf-8"))
codex_entries = [entry for entry in codex_marketplace.get("plugins") or [] if entry.get("name") == "wp-agent-bridge-helper"]
if len(codex_entries) != 1 or (codex_entries[0].get("source") or {}).get("path") != "./plugins/wp-agent-bridge-helper":
    raise SystemExit("Codex marketplace must point at the same plugin folder")

# --- shared skill -------------------------------------------------------------------
skill = (SOURCE / "skills/wp-agent-bridge/SKILL.md").read_text(encoding="utf-8")
frontmatter = re.match(r"---\n(.*?)\n---\n", skill, re.S)
if not frontmatter:
    raise SystemExit("Skill frontmatter missing")
keys = {line.split(":", 1)[0].strip() for line in frontmatter.group(1).splitlines() if ":" in line}
allowed = {"name", "description", "license", "compatibility", "metadata", "allowed-tools"}
if not keys <= allowed or not {"name", "description"} <= keys:
    raise SystemExit(f"Skill frontmatter must stay within the Agent Skills spec for claude.ai uploads: {sorted(keys)}")
if not re.search(r"^name:\s*wp-agent-bridge\s*$", frontmatter.group(1), re.M):
    raise SystemExit("Skill name must match its folder")
if skill.count("\n") > 500:
    raise SystemExit("Keep SKILL.md under 500 lines")
for marker in [
    "wordpress-bridge/RUNTIME_CONNECTION.json",
    "wordpress-bridge/RUNTIME_CAPABILITIES.json",
    "wordpress-bridge/results/<id>.json",
    "wordpress-bridge/prepare-media.py",
    "GitHub app/tool recovery",
    "client exposure problem",
    "app/plugin/tool discovery mechanism once",
    "Do not answer with a tool-availability explanation before attempting the required GitHub dependency path.",
    "Non-negotiable execution contract",
    "Do not use the model's apparent/visible tool roster as evidence",
    "actual GitHub dependency/tool call",
    "Selecting or invoking WP Agent Bridge Helper should be sufficient",
    "GitHub transport by client",
    "references/transports.md",
    "scripts/wpab.py",
    "wp-agent-bridge-runtime",
    "pending_or_unknown",
    "Do not switch normal WordPress work to WPVibe",
]:
    if marker not in skill:
        raise SystemExit(f"WP Agent Bridge Helper skill missing marker: {marker}")

transports = (SOURCE / "skills/wp-agent-bridge/references/transports.md").read_text(encoding="utf-8")
for marker in ["ChatGPT", "Codex CLI", "Claude Code", "claude.ai", "add_repo", "gh api", "git/trees", "wp-agent-bridge-runtime", "git push", "--via"]:
    if marker not in transports:
        raise SystemExit(f"transports.md missing marker: {marker}")

# --- transport script ---------------------------------------------------------------
script_path = SOURCE / "skills/wp-agent-bridge/scripts/wpab.py"
script = script_path.read_text(encoding="utf-8")
tree = ast.parse(script, filename=str(script_path))
imports = {node.names[0].name.split(".")[0] for node in ast.walk(tree) if isinstance(node, ast.Import)}
imports |= {(node.module or "").split(".")[0] for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)}
stdlib_only = {"argparse", "base64", "datetime", "json", "os", "re", "shutil", "subprocess", "sys", "time", "urllib"}
if not imports <= stdlib_only:
    raise SystemExit(f"wpab.py must stay standard-library only: {sorted(imports - stdlib_only)}")
for marker in ['RUNTIME_BRANCH = "wp-agent-bridge-runtime"', "MAX_COMMANDS_PER_PUSH = 20",
               "FIRST_OBSERVATION_SECONDS = 5", "SECOND_OBSERVATION_SECONDS = 10", "result_exists", "pending_conflict",
               "class ApiStore", "class GitStore", "class FallbackStore", '"--via"', "--single-branch"]:
    if marker not in script:
        raise SystemExit(f"wpab.py missing marker: {marker}")
if re.search(r"ghp_[A-Za-z0-9]{10,}|github_pat_[A-Za-z0-9_]{10,}", script):
    raise SystemExit("wpab.py must not contain a token")

print("wp-agent-bridge-helper-package-test: ok")
