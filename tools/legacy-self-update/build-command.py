#!/usr/bin/env python3
"""Build and verify the compact one-time WP Agent Bridge 1.1.5 migration command."""
from __future__ import annotations
import argparse, base64, hashlib, json, subprocess
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
BOOTSTRAP = HERE / "bootstrap-1.1.6.907.php"
LEGACY_FILES = HERE / "1.1.5-files.txt"
BOOTSTRAP_VERSION = "1.1.6.907"
TARGET_VERSION = "1.1.7"
TARGET_COMMIT = "9a034f443441112d862762eb13898071d7016c6f"
TARGET_MANIFEST = "293223ed5597062fcf7e4ac8635d632b22a615d9d49092ff4e5c618bb0900d13"
TARGET_FILE_COUNT = 72
MAIN = "takka-wordpress-bridge.php"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def one_file_manifest(path: str, data: bytes) -> str:
    digest = sha256(data)
    canonical = f"{path}\0{digest}\0{len(data)}\n".encode()
    return sha256(canonical)


def git_target_manifest() -> tuple[int, int, str]:
    raw = subprocess.check_output([
        "git", "-C", str(REPO), "ls-tree", "-r", "-z", TARGET_COMMIT, "--", "plugin/wp-agent-bridge"
    ])
    rows = []
    total = 0
    prefix = "plugin/wp-agent-bridge/"
    for record in raw.split(b"\0"):
        if not record:
            continue
        meta, raw_path = record.split(b"\t", 1)
        _, kind, blob_sha = meta.decode("ascii").split()
        if kind != "blob":
            continue
        path = raw_path.decode("utf-8")
        if not path.startswith(prefix):
            raise SystemExit(f"unexpected target path: {path}")
        rel = path[len(prefix):]
        data = subprocess.check_output(["git", "-C", str(REPO), "cat-file", "blob", blob_sha])
        digest = sha256(data)
        rows.append((rel, digest, len(data)))
        total += len(data)
    rows.sort()
    canonical = b"".join(
        rel.encode() + b"\0" + digest.encode() + b"\0" + str(size).encode() + b"\n"
        for rel, digest, size in rows
    )
    return len(rows), total, sha256(canonical)


def check_inputs() -> tuple[bytes, list[str]]:
    boot = BOOTSTRAP.read_bytes()
    text = boot.decode("utf-8")
    for needle in [
        f"Version: {BOOTSTRAP_VERSION}", TARGET_COMMIT, TARGET_MANIFEST, "const C=72", "wp-agent-bridge-runtime/v1",
    ]:
        if needle not in text:
            raise SystemExit(f"bootstrap invariant missing: {needle}")

    files = [line.strip() for line in LEGACY_FILES.read_text().splitlines() if line.strip()]
    if files != sorted(set(files)) or len(files) != 70 or MAIN not in files:
        raise SystemExit("1.1.5-files.txt must be 70 unique sorted paths including the main plugin file")

    count, total, manifest = git_target_manifest()
    if count != TARGET_FILE_COUNT or manifest != TARGET_MANIFEST:
        raise SystemExit(
            f"pinned target changed: files={count}, bytes={total}, manifest={manifest}"
        )
    return boot, files


def build(command_id: str) -> dict:
    boot, files = check_inputs()
    file_sha = sha256(boot)
    command = {
        "id": command_id,
        "request_id": command_id,
        "type": "bridge",
        "action": "bridge.self_update.apply",
        "params": {
            "confirm": True,
            "expected_current_version": "1.1.5",
            "target_version": BOOTSTRAP_VERSION,
            "full_manifest": True,
            "delete_paths": [p for p in files if p != MAIN],
            "confirm_delete_paths": True,
            "manifest_sha256": one_file_manifest(MAIN, boot),
            "files": [{
                "path": MAIN,
                "data_b64": base64.b64encode(boot).decode("ascii"),
                "sha256": file_sha,
            }],
        },
    }
    raw = json.dumps(command, separators=(",", ":"), ensure_ascii=False).encode()
    if len(raw) >= 65536:
        raise SystemExit(f"legacy migration command unexpectedly large: {len(raw)} bytes")
    decoded = base64.b64decode(command["params"]["files"][0]["data_b64"], validate=True)
    if sha256(decoded) != file_sha or one_file_manifest(MAIN, decoded) != command["params"]["manifest_sha256"]:
        raise SystemExit("generated command integrity self-check failed")
    return command


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--id", default="wpab-legacy-1.1.5-to-1.1.7")
    parser.add_argument("--output", type=Path)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    command = build(args.id)
    raw = json.dumps(command, separators=(",", ":"), ensure_ascii=False) + "\n"
    if args.output:
        args.output.write_text(raw, encoding="utf-8")
    elif not args.check:
        print(raw, end="")
    if args.check:
        print(
            "legacy self-update bootstrap OK: "
            f"command_bytes={len(raw.encode())} bootstrap_sha256={command['params']['files'][0]['sha256']} "
            f"manifest_sha256={command['params']['manifest_sha256']} delete_paths={len(command['params']['delete_paths'])}"
        )


if __name__ == "__main__":
    main()
