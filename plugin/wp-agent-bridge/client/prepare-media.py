#!/usr/bin/env python3
"""Prepare unchanged local media for the existing GitHub Direct Runtime.

Standard library only. No network, credentials, upload, or article mutation.
Call prepare_file() from an available client execution tool, then pass the
returned strings to the existing GitHub tools without model transcription.
"""

import argparse
import base64
import hashlib
import json
import mimetypes
from pathlib import Path
import re
import sys

INLINE_BYTES = 1_048_576
MAX_BYTES = 6_291_456
PREFERRED_CHUNK_BYTES = 131_072
MAX_CHUNKS = 32
RUNTIME_BRANCH = "wp-agent-bridge-runtime"


class PreparationError(ValueError):
    def __init__(self, code, message):
        super().__init__(message)
        self.code = code


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def encoded_json(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n"


def connection_binding(connection):
    required = {"status": "canonical", "transport": "direct-github-webhook",
                "ownership": "user-owned", "operator_relay": False,
                "runtime_branch": RUNTIME_BRANCH}
    if any(connection.get(key) != value for key, value in required.items()):
        raise PreparationError("connection_mismatch", "A canonical user-owned Direct Runtime marker is required.")
    if connection.get("operator_relay") is not False:
        raise PreparationError("connection_mismatch", "operator_relay must be the JSON boolean false.")
    repository = connection.get("repository", "")
    host = connection.get("site_host", "")
    if not isinstance(repository, str) or not re.fullmatch(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository):
        raise PreparationError("connection_mismatch", "The marker must identify its repository.")
    if not isinstance(host, str) or not host or any(char.isspace() for char in host) or "/" in host:
        raise PreparationError("connection_mismatch", "The marker must identify its site_host.")
    return {"repository": repository, "runtime_branch": RUNTIME_BRANCH, "site_host": host}


def prepare_bytes(data, filename, request_id, connection, metadata=None):
    binding = connection_binding(connection)
    if not isinstance(request_id, str) or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,79}", request_id):
        raise PreparationError("invalid_request_id", "Use a stable request ID of 1-80 ASCII letters, digits, dots, underscores, or hyphens.")
    if not isinstance(filename, str) or not filename or any(c in filename for c in ("/", "\\", "\x00", "\r", "\n")):
        raise PreparationError("invalid_filename", "filename must be a basename, not a path.")
    mime = mimetypes.guess_type(filename)[0]
    if not mime:
        raise PreparationError("invalid_filename", "Use the original file extension so its MIME type can be determined.")
    if not isinstance(data, bytes) or not 1 <= len(data) <= MAX_BYTES:
        raise PreparationError("asset_size", "Original media must contain 1 to 6291456 bytes; it will not be resized or recompressed.")
    metadata = dict(metadata or {})
    if set(metadata) - {"post_id", "title", "alt_text", "caption", "description"}:
        raise PreparationError("invalid_metadata", "Metadata cannot override the transport or integrity fields.")
    if "post_id" in metadata and (type(metadata["post_id"]) is not int or metadata["post_id"] < 1):
        raise PreparationError("invalid_metadata", "post_id must be a positive integer when supplied.")
    if any(not isinstance(value, str) for key, value in metadata.items() if key != "post_id"):
        raise PreparationError("invalid_metadata", "Text metadata must be strings.")

    digest = sha256(data)
    params = dict(metadata, filename=filename, mime_type=mime,
                  expected_bytes=len(data), expected_sha256=digest)
    entries = []
    command = {"id": request_id, "request_id": request_id}
    if len(data) <= INLINE_BYTES:
        params["data_b64"] = base64.b64encode(data).decode("ascii")
        command.update(type="operation", operation="media.upload.inline", params=params)
        mode = "inline"
    else:
        # 128 KiB alone would exceed the server's 32-chunk limit above 4 MiB.
        chunk_bytes = max(PREFERRED_CHUNK_BYTES, (len(data) + MAX_CHUNKS - 1) // MAX_CHUNKS)
        paths, integrity = [], []
        for index, offset in enumerate(range(0, len(data), chunk_bytes)):
            chunk = data[offset:offset + chunk_bytes]
            path = "wordpress-bridge/media/pending/{}-{:02d}.b64".format(request_id, index)
            content = base64.b64encode(chunk).decode("ascii")
            entries.append({"path": path, "mode": "100644", "type": "blob", "content": content})
            paths.append(path)
            integrity.append({"expected_bytes": len(chunk), "expected_sha256": sha256(chunk)})
        params.update(data_paths=paths, chunk_integrity=integrity)
        command.update(type="rest", method="POST", route="/wp-agent-bridge-runtime/v1/media-upload", body=params)
        mode = "staged"

    command_path = "wordpress-bridge/commands/pending/{}.json".format(request_id)
    entries.append({"path": command_path, "mode": "100644", "type": "blob", "content": encoded_json(command)})
    manifest = dict(binding, schema=1, request_id=request_id, mode=mode,
                    filename=filename, mime_type=mime, expected_bytes=len(data), expected_sha256=digest,
                    payload_count=len(entries) - 1, command_path=command_path,
                    result_path="wordpress-bridge/results/{}.json".format(request_id),
                    uploaded=False)
    # Do not include source paths or credentials in the transferable package.
    return {"manifest": manifest, "command": command, "tree": entries}


def prepare_file(source, filename, request_id, connection, metadata=None):
    path = Path(source)
    try:
        with path.open("rb") as handle:
            data = handle.read(MAX_BYTES + 1)
    except OSError as error:
        raise PreparationError("asset_unavailable", "The original file could not be read in this execution environment.") from error
    return prepare_bytes(data, filename or path.name, request_id, connection, metadata)


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", help="Readable original file in the current execution environment")
    parser.add_argument("--connection", required=True, help="Saved RUNTIME_CONNECTION.json")
    parser.add_argument("--request-id", required=True)
    parser.add_argument("--output", required=True, help="New local directory; never overwritten")
    parser.add_argument("--filename")
    parser.add_argument("--post-id", type=int)
    for field in ("title", "alt-text", "caption", "description"):
        parser.add_argument("--" + field)
    args = parser.parse_args(argv)
    try:
        connection = json.loads(Path(args.connection).read_text(encoding="utf-8-sig"))
        if not isinstance(connection, dict):
            raise PreparationError("connection_mismatch", "The connection marker must be a JSON object.")
        metadata = {key: getattr(args, key) for key in ("post_id", "title", "alt_text", "caption", "description") if getattr(args, key) is not None}
        package = prepare_file(args.source, args.filename, args.request_id, connection, metadata)
        output = Path(args.output)
        output.mkdir(parents=True, exist_ok=False)
        (output / "manifest.json").write_text(encoded_json(package["manifest"]), encoding="utf-8")
        (output / "command.json").write_text(encoded_json(package["command"]), encoding="utf-8")
        (output / "git-tree.json").write_text(encoded_json({"tree": package["tree"]}), encoding="utf-8")
        print(encoded_json({"ok": True, "stage": "prepared", "output": str(output.resolve()), **package["manifest"]}), end="")
        return 0
    except (PreparationError, OSError, ValueError) as error:
        print(encoded_json({"ok": False, "stage": "preparation", "code": getattr(error, "code", "local_preparation_error"), "message": str(error), "uploaded": False}), end="")
        return 1


if __name__ == "__main__":
    sys.exit(main())
