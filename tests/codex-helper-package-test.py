"""Validate the distributable WP Agent Bridge Helper plugin ZIP against its source."""
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "codex-plugin" / "wp-agent-bridge-codex-helper"
ZIP = ROOT / "downloads" / "wp-agent-bridge-helper-0.2.1.zip"

required = [
    "plugin.json",
    ".app.json",
    ".codex-plugin/plugin.json",
    "skills/wp-agent-bridge/SKILL.md",
    "README.md",
]

with zipfile.ZipFile(ZIP) as archive:
    if sorted(archive.namelist()) != sorted(required):
        raise SystemExit("WP Agent Bridge Helper ZIP file list mismatch")
    for relative in required:
        expected = (SOURCE / relative).read_bytes()
        actual = archive.read(relative)
        if actual != expected:
            raise SystemExit(f"WP Agent Bridge Helper ZIP content mismatch: {relative}")

manifest = json.loads((SOURCE / "plugin.json").read_text(encoding="utf-8"))
if manifest.get("$schema") != "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json":
    raise SystemExit("Portable plugin schema missing")
if manifest.get("name") != "wp-agent-bridge-helper" or manifest.get("version") != "0.2.1":
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
if compat.get("name") != "wp-agent-bridge-helper" or compat.get("version") != "0.2.1":
    raise SystemExit("Compatibility plugin identity mismatch")

skill = (SOURCE / "skills/wp-agent-bridge/SKILL.md").read_text(encoding="utf-8")
for marker in [
    "wordpress-bridge/RUNTIME_CONNECTION.json",
    "wordpress-bridge/RUNTIME_CAPABILITIES.json",
    "wordpress-bridge/results/<id>.json",
    "wordpress-bridge/prepare-media.py",
    "GitHub app/tool recovery",
    "client exposure problem",
    "app/plugin/tool discovery mechanism once",
    "Do not say \"this chat cannot execute WP Agent Bridge\"",
    "Non-negotiable execution contract",
    "Do not use the model's apparent/visible tool roster as evidence",
    "actual GitHub dependency/tool call",
    "Selecting or invoking WP Agent Bridge Helper should be sufficient",
]:
    if marker not in skill:
        raise SystemExit(f"WP Agent Bridge Helper skill missing marker: {marker}")

print("wp-agent-bridge-helper-package-test: ok")
