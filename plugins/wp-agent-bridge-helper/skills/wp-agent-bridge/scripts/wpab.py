#!/usr/bin/env python3
"""WP Agent Bridge GitHub transport for shell-capable clients.

Standard library only. Moves JSON files to and from the user's private runtime
repository on branch wp-agent-bridge-runtime through `gh api` (preferred) or a
GITHUB_TOKEN / GH_TOKEN environment variable. It never changes command semantics,
never stores credentials, and never contacts WordPress directly.

    wpab.py --repo OWNER/REPO connection
    wpab.py --repo OWNER/REPO capabilities [--operations]
    wpab.py --repo OWNER/REPO submit --id ID --operation post.get --params '{"post_id":1}'
    wpab.py --repo OWNER/REPO submit --file command.json
    wpab.py --repo OWNER/REPO result ID [--wait] [--full] [--head 80]
    wpab.py --repo OWNER/REPO publish-media PREPARED_DIR

Exit codes: 0 ok, 1 transport/argument error, 2 refused (unsafe or conflicting
request), 3 result pending_or_unknown.
"""

import argparse
import base64
import datetime as _dt
import json
import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request

RUNTIME_BRANCH = "wp-agent-bridge-runtime"
PENDING_DIR = "wordpress-bridge/commands/pending"
RESULTS_DIR = "wordpress-bridge/results"
CONNECTION_PATH = "wordpress-bridge/RUNTIME_CONNECTION.json"
CAPABILITIES_PATH = "wordpress-bridge/RUNTIME_CAPABILITIES.json"
ID_PATTERN = re.compile(r"[A-Za-z0-9][A-Za-z0-9._-]{0,79}")
REPO_PATTERN = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+")
MAX_COMMANDS_PER_PUSH = 20
FIRST_OBSERVATION_SECONDS = 5
SECOND_OBSERVATION_SECONDS = 10
RAW_ACCEPT = "application/vnd.github.raw+json"
JSON_ACCEPT = "application/vnd.github+json"

EXIT_OK = 0
EXIT_ERROR = 1
EXIT_REFUSED = 2
EXIT_PENDING = 3


class TransportError(RuntimeError):
    def __init__(self, status, message, body=""):
        super().__init__(message)
        self.status = status
        self.body = body


def utc_now():
    return _dt.datetime.now(_dt.timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def encoded_json(value):
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n"


def emit(payload):
    sys.stdout.write(encoded_json(payload))
    sys.stdout.flush()


# --------------------------------------------------------------------------- transports


class GhTransport:
    """`gh api` wrapper. Status codes come from gh's own `(HTTP nnn)` suffix."""

    name = "gh"

    def __init__(self, executable="gh"):
        self.executable = executable

    @staticmethod
    def available(executable="gh"):
        # `gh auth status` is deliberately not a gate: hosted sessions (for
        # example Claude Code on the web) can serve `gh api` for an attached
        # repository while `gh auth status` reports no login. gh also honors
        # GH_TOKEN / GITHUB_TOKEN on its own.
        return shutil.which(executable) is not None

    def request(self, method, path, body=None, accept=JSON_ACCEPT):
        command = [self.executable, "api", "-X", method, "-H", "Accept: " + accept, path]
        stdin = None
        if body is not None:
            command += ["--input", "-"]
            stdin = json.dumps(body)
        completed = subprocess.run(command, input=stdin, capture_output=True, text=True)
        if completed.returncode == 0:
            return 200, completed.stdout
        stderr = completed.stderr.strip()
        match = re.search(r"\(HTTP (\d{3})\)", stderr)
        status = int(match.group(1)) if match else 0
        if status == 0 and "auth login" in stderr:
            stderr += " | No GitHub login for gh in this environment: run `gh auth login` with the user's own " \
                      "account, set GH_TOKEN, or attach the runtime repository in this session."
        raise TransportError(status, stderr or "gh api failed", completed.stdout)


class TokenTransport:
    """REST API with a bearer token from GITHUB_TOKEN / GH_TOKEN."""

    name = "token"

    def __init__(self, token, api_base="https://api.github.com"):
        self.token = token
        self.api_base = api_base.rstrip("/")

    def request(self, method, path, body=None, accept=JSON_ACCEPT):
        url = self.api_base + "/" + path.lstrip("/")
        data = json.dumps(body).encode("utf-8") if body is not None else None
        request = urllib.request.Request(url, data=data, method=method)
        request.add_header("Authorization", "Bearer " + self.token)
        request.add_header("Accept", accept)
        request.add_header("X-GitHub-Api-Version", "2022-11-28")
        request.add_header("User-Agent", "wp-agent-bridge-helper-wpab")
        if data is not None:
            request.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(request) as response:
                return response.status, response.read().decode("utf-8")
        except urllib.error.HTTPError as error:
            text = error.read().decode("utf-8", "replace")
            raise TransportError(error.code, "HTTP {}: {}".format(error.code, text[:300]), text) from error
        except urllib.error.URLError as error:
            raise TransportError(0, "network error: {}".format(error.reason)) from error


def resolve_transport(explicit=None):
    if explicit is not None:
        return explicit
    if GhTransport.available():
        return GhTransport()
    token = os.environ.get("GITHUB_TOKEN") or os.environ.get("GH_TOKEN")
    if token:
        return TokenTransport(token)
    raise TransportError(
        0,
        "No GitHub transport: `gh` is not installed and neither GITHUB_TOKEN nor GH_TOKEN is set. "
        "Install GitHub CLI and run `gh auth login` (user-owned login), or attach the runtime repository in this session.",
    )


# --------------------------------------------------------------------------- runtime client


class Runtime:
    def __init__(self, transport, repository, branch=RUNTIME_BRANCH):
        if not REPO_PATTERN.fullmatch(repository or ""):
            raise TransportError(0, "repository must be owner/name (use --repo or WPAB_REPO)")
        self.transport = transport
        self.repository = repository
        self.branch = branch

    def _contents(self, path):
        return "repos/{}/contents/{}".format(self.repository, path)

    def read_text(self, path):
        """Return file text, or None on 404. Other errors propagate."""
        try:
            _, text = self.transport.request("GET", self._contents(path) + "?ref=" + self.branch, accept=RAW_ACCEPT)
            return text
        except TransportError as error:
            if error.status == 404:
                return None
            raise

    def put_text(self, path, text, message):
        body = {
            "message": message,
            "branch": self.branch,
            "content": base64.b64encode(text.encode("utf-8")).decode("ascii"),
        }
        _, response = self.transport.request("PUT", self._contents(path), body=body)
        try:
            data = json.loads(response)
        except ValueError:
            return {}
        commit = data.get("commit") or {}
        return {"commit": commit.get("sha"), "content_sha": (data.get("content") or {}).get("sha")}

    def head(self):
        _, text = self.transport.request("GET", "repos/{}/git/ref/heads/{}".format(self.repository, self.branch))
        ref = json.loads(text)
        commit_sha = ref["object"]["sha"]
        _, text = self.transport.request("GET", "repos/{}/git/commits/{}".format(self.repository, commit_sha))
        commit = json.loads(text)
        return commit_sha, commit["tree"]["sha"]

    def push_tree(self, entries, message):
        """Create one commit containing `entries` (Create-Tree format with inline content)."""
        last_error = None
        for _attempt in range(2):
            parent, base_tree = self.head()
            _, text = self.transport.request(
                "POST", "repos/{}/git/trees".format(self.repository), body={"base_tree": base_tree, "tree": entries}
            )
            tree_sha = json.loads(text)["sha"]
            _, text = self.transport.request(
                "POST",
                "repos/{}/git/commits".format(self.repository),
                body={"message": message, "tree": tree_sha, "parents": [parent]},
            )
            commit_sha = json.loads(text)["sha"]
            try:
                self.transport.request(
                    "PATCH", "repos/{}/git/refs/heads/{}".format(self.repository, self.branch), body={"sha": commit_sha}
                )
                return commit_sha
            except TransportError as error:
                last_error = error
                if error.status != 422:
                    raise
        raise TransportError(422, "runtime branch moved twice during publication; nothing was published: " + str(last_error))


# --------------------------------------------------------------------------- commands


def validate_id(value):
    if not isinstance(value, str) or not ID_PATTERN.fullmatch(value):
        raise TransportError(0, "id must be 1-80 ASCII letters, digits, dots, underscores, or hyphens")
    return value


def cmd_connection(runtime, args):
    text = runtime.read_text(CONNECTION_PATH)
    if text is None:
        emit({"ok": False, "code": "marker_not_found", "path": CONNECTION_PATH, "branch": runtime.branch,
              "note": "A marker 404 alone is not evidence that the Bridge is unavailable."})
        return EXIT_ERROR
    try:
        marker = json.loads(text)
    except ValueError:
        emit({"ok": False, "code": "marker_invalid_json", "path": CONNECTION_PATH})
        return EXIT_ERROR
    expected = {"status": "canonical", "transport": "direct-github-webhook", "ownership": "user-owned",
                "operator_relay": False, "runtime_branch": runtime.branch, "repository": runtime.repository}
    mismatches = {key: marker.get(key) for key, value in expected.items() if marker.get(key) != value}
    emit({"ok": not mismatches, "marker": marker, "mismatches": mismatches})
    return EXIT_OK if not mismatches else EXIT_REFUSED


def cmd_capabilities(runtime, args):
    text = runtime.read_text(CAPABILITIES_PATH)
    if text is None:
        emit({"ok": False, "code": "capabilities_not_found", "path": CAPABILITIES_PATH, "branch": runtime.branch})
        return EXIT_ERROR
    catalog = json.loads(text)
    if args.operations:
        operations = ((catalog.get("routes") or {}).get("operations") or {}).get("operations") or []
        emit({"ok": True, "bridge_version": catalog.get("bridge_version"), "operations": operations})
    else:
        sys.stdout.write(text if text.endswith("\n") else text + "\n")
    return EXIT_OK


def build_command(args):
    if args.file:
        with open(args.file, "r", encoding="utf-8-sig") as handle:
            command = json.load(handle)
        if not isinstance(command, dict):
            raise TransportError(0, "--file must contain one JSON object")
    else:
        if not args.id:
            raise TransportError(0, "--id is required unless --file is used")
        if args.type == "operation" and not args.operation:
            raise TransportError(0, "--operation is required for type=operation")
        if args.type == "bridge" and not args.action:
            raise TransportError(0, "--action is required for type=bridge")
        params = json.loads(args.params) if args.params else {}
        if not isinstance(params, dict):
            raise TransportError(0, "--params must be a JSON object")
        command = {"id": args.id, "type": args.type}
        if args.type == "operation":
            command["operation"] = args.operation
        else:
            command["action"] = args.action
        command["params"] = params
    validate_id(command.get("id"))
    if "request_id" in command:
        validate_id(command["request_id"])
    command.setdefault("created_at", utc_now())
    return command


def cmd_submit(runtime, args):
    command = build_command(args)
    command_id = command["id"]
    pending_path = "{}/{}.json".format(PENDING_DIR, command_id)
    result_path = "{}/{}.json".format(RESULTS_DIR, command_id)

    if runtime.read_text(result_path) is not None:
        emit({"ok": False, "code": "result_exists", "id": command_id, "result_path": result_path,
              "note": "Read the existing result; do not re-publish a finished ID."})
        return EXIT_REFUSED

    text = encoded_json(command)
    existing = runtime.read_text(pending_path)
    if existing is not None:
        same = json.loads(existing) == command or _same_ignoring_created_at(json.loads(existing), command)
        emit({"ok": same, "code": "already_pending" if same else "pending_conflict", "id": command_id,
              "pending_path": pending_path,
              "note": "Already published; observe its result." if same
              else "A different payload is pending under this ID. Use a new ID only for a new operation."})
        return EXIT_OK if same else EXIT_REFUSED

    written = runtime.put_text(pending_path, text, "WP Agent Bridge: publish command " + command_id)
    emit({"ok": True, "stage": "published", "id": command_id, "pending_path": pending_path,
          "result_path": result_path, "branch": runtime.branch, "submitted_at": utc_now(),
          "commit": written.get("commit"), "command": command,
          "next": "Read the result no earlier than {} seconds after submission (result --wait).".format(
              FIRST_OBSERVATION_SECONDS)})
    return EXIT_OK


def _same_ignoring_created_at(left, right):
    a = {key: value for key, value in left.items() if key != "created_at"}
    b = {key: value for key, value in right.items() if key != "created_at"}
    return a == b


def summarize_result(text):
    try:
        data = json.loads(text)
    except ValueError:
        return {"parsed": False}
    summary = {"parsed": True}
    outcome = data.get("outcome")
    if isinstance(outcome, dict):
        summary["outcome"] = {key: outcome.get(key) for key in ("status", "ok", "reason", "state") if key in outcome}
    for key in ("ok", "status", "error", "source_commit"):
        if key in data:
            summary[key] = data[key]
    return summary


def cmd_result(runtime, args, sleep=time.sleep, clock=time.monotonic):
    command_id = validate_id(args.id)
    result_path = "{}/{}.json".format(RESULTS_DIR, command_id)
    started = clock()
    observations = []

    def observe():
        text = runtime.read_text(result_path)
        observations.append({"elapsed_seconds": round(clock() - started, 1), "present": text is not None})
        return text

    if args.wait:
        sleep(FIRST_OBSERVATION_SECONDS)
        text = observe()
        if text is None:
            remaining = SECOND_OBSERVATION_SECONDS - (clock() - started)
            if remaining > 0:
                sleep(remaining)
            text = observe()
    else:
        text = observe()

    if text is None:
        emit({"ok": False, "code": "pending_or_unknown", "id": command_id, "result_path": result_path,
              "observations": observations,
              "note": "Missing result is pending/unknown, not failure. Do not resubmit with a new ID."})
        return EXIT_PENDING

    lines = text.splitlines()
    shown = text if args.full else "\n".join(lines[: args.head])
    emit({"ok": True, "id": command_id, "result_path": result_path, "observations": observations,
          "truncated": (not args.full) and len(lines) > args.head, "summary": summarize_result(text)})
    sys.stdout.write(shown + ("\n" if not shown.endswith("\n") else ""))
    return EXIT_OK


CHUNK_INDEX = re.compile(r"\.chunk\.(\d{4})\.json$")


def cmd_publish_media(runtime, args):
    directory = args.directory
    with open(os.path.join(directory, "manifest.json"), "r", encoding="utf-8-sig") as handle:
        manifest = json.load(handle)
    with open(os.path.join(directory, "git-tree.json"), "r", encoding="utf-8-sig") as handle:
        entries = json.load(handle)["tree"]

    if manifest.get("repository") != runtime.repository or manifest.get("runtime_branch") != runtime.branch:
        emit({"ok": False, "code": "binding_mismatch", "manifest_repository": manifest.get("repository"),
              "manifest_branch": manifest.get("runtime_branch"), "repository": runtime.repository, "branch": runtime.branch})
        return EXIT_REFUSED

    def index_of(entry):
        match = CHUNK_INDEX.search(entry["path"])
        if not match:
            raise TransportError(0, "unexpected chunk path: " + entry["path"])
        return int(match.group(1))

    ordered = sorted(entries, key=index_of)
    todo = []
    skipped = []
    for entry in ordered:
        result_path = entry["path"].replace(PENDING_DIR + "/", RESULTS_DIR + "/", 1)
        if runtime.read_text(result_path) is not None:
            skipped.append(entry["path"])
        else:
            todo.append({"path": entry["path"], "mode": entry.get("mode", "100644"), "type": "blob",
                         "content": entry["content"]})

    commits = []
    for start in range(0, len(todo), MAX_COMMANDS_PER_PUSH):
        batch = todo[start:start + MAX_COMMANDS_PER_PUSH]
        first, last = index_of(batch[0]), index_of(batch[-1])
        message = "WP Agent Bridge: publish media chunks {}-{} for {}".format(first, last, manifest.get("upload_id"))
        commits.append({"chunks": [first, last], "commit": runtime.push_tree(batch, message)})

    emit({"ok": True, "stage": "published" if todo else "nothing_to_publish", "upload_id": manifest.get("upload_id"),
          "published": len(todo), "skipped_with_result": len(skipped), "commits": commits,
          "final_result_path": manifest.get("final_result_path"),
          "note": "Prepared media is not uploaded media. Observe the final result path before using an attachment ID."})
    return EXIT_OK


# --------------------------------------------------------------------------- cli


def build_parser():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--repo", default=os.environ.get("WPAB_REPO"), help="owner/name of the runtime repository")
    parser.add_argument("--branch", default=RUNTIME_BRANCH)
    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser("connection", help="read and validate the canonical runtime marker")

    cap = sub.add_parser("capabilities", help="read RUNTIME_CAPABILITIES.json")
    cap.add_argument("--operations", action="store_true", help="print only the operation names")

    submit = sub.add_parser("submit", help="publish one pending command")
    submit.add_argument("--file", help="exact command JSON to publish")
    submit.add_argument("--id")
    submit.add_argument("--type", choices=["operation", "bridge"], default="operation")
    submit.add_argument("--operation")
    submit.add_argument("--action")
    submit.add_argument("--params", help="JSON object")

    result = sub.add_parser("result", help="read a result file")
    result.add_argument("id")
    result.add_argument("--wait", action="store_true", help="apply the 5 s / 10 s observation rule")
    result.add_argument("--full", action="store_true")
    result.add_argument("--head", type=int, default=80)

    media = sub.add_parser("publish-media", help="publish prepare-media.py output in chunk order")
    media.add_argument("directory")
    return parser


def main(argv=None, transport=None):
    args = build_parser().parse_args(argv)
    try:
        runtime = Runtime(resolve_transport(transport), args.repo, args.branch)
        handler = {
            "connection": cmd_connection,
            "capabilities": cmd_capabilities,
            "submit": cmd_submit,
            "result": cmd_result,
            "publish-media": cmd_publish_media,
        }[args.command]
        return handler(runtime, args)
    except TransportError as error:
        category = "access_error" if error.status in (401, 403) else "transport_error"
        emit({"ok": False, "code": category, "http_status": error.status or None, "message": str(error)})
        return EXIT_ERROR
    except (OSError, ValueError) as error:
        emit({"ok": False, "code": "local_error", "message": str(error)})
        return EXIT_ERROR


if __name__ == "__main__":
    sys.exit(main())
