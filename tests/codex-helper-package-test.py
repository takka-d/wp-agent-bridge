"""Validate the distributable Codex helper plugin ZIP against its source."""
import json
from pathlib import Path
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "codex-plugin" / "wp-agent-bridge-codex-helper"
ZIP = ROOT / "downloads" / "wp-agent-bridge-codex-helper-0.1.0.zip"

required = [
    "plugin.json",
    "skills/wp-agent-bridge/SKILL.md",
    "README.md",
]

with zipfile.ZipFile(ZIP) as archive:
    if sorted(archive.namelist()) != sorted(required):
        raise SystemExit("Codex helper ZIP file list mismatch")
    for relative in required:
        expected = (SOURCE / relative).read_bytes()
        actual = archive.read(relative)
        if actual != expected:
            raise SystemExit(f"Codex helper ZIP content mismatch: {relative}")

manifest = json.loads((SOURCE / "plugin.json").read_text(encoding="utf-8"))
if manifest.get("$schema") != "https://agent-plugins.org/schemas/1.0.0/plugin.schema.json":
    raise SystemExit("Portable plugin schema missing")
if manifest.get("name") != "wp-agent-bridge-codex-helper" or manifest.get("version") != "0.1.0":
    raise SystemExit("Codex helper identity mismatch")

skill = (SOURCE / "skills/wp-agent-bridge/SKILL.md").read_text(encoding="utf-8")
for marker in [
    "wordpress-bridge/RUNTIME_CONNECTION.json",
    "wordpress-bridge/RUNTIME_CAPABILITIES.json",
    "wordpress-bridge/results/<id>.json",
    "wordpress-bridge/prepare-media.py",
    "legacy operator-owned runtime repository",
]:
    if marker not in skill:
        raise SystemExit(f"Codex helper skill missing marker: {marker}")

print("codex-helper-package-test: ok")
