"""Exercise the helper plugin's wpab.py GitHub transport against an in-memory GitHub."""
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
from contextlib import redirect_stdout

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "plugins" / "wp-agent-bridge-helper" / "skills" / "wp-agent-bridge" / "scripts" / "wpab.py"
PREPARER = ROOT / "plugin" / "wp-agent-bridge" / "client" / "prepare-media.py.txt"
REPO = "example/runtime"
BRANCH = "wp-agent-bridge-runtime"

spec = importlib.util.spec_from_file_location("wpab", SCRIPT)
wpab = importlib.util.module_from_spec(spec)
spec.loader.exec_module(wpab)


class FakeGitHub:
    """Minimal in-memory model of the endpoints wpab.py uses."""

    def __init__(self):
        self.files = {}          # path -> text on the runtime branch
        self.default_files = {}  # path -> text on the default branch
        self.commits = []        # list of {"sha", "tree", "parents", "message"}
        self.trees = {}          # sha -> entries
        self.head = "c0"
        self.commits.append({"sha": "c0", "tree": "t0", "parents": [], "message": "init"})
        self.trees["t0"] = []
        self.calls = []
        self.ref_patch_failures = 0

    def request(self, method, path, body=None, accept=wpab.JSON_ACCEPT):
        self.calls.append((method, path))
        contents = re.fullmatch(r"repos/example/runtime/contents/([^?]+)(?:\?ref=(.+))?", path)
        if contents and method == "GET":
            store = self.files if contents.group(2) == BRANCH else self.default_files
            if contents.group(1) in store:
                return 200, store[contents.group(1)]
            raise wpab.TransportError(404, "gh: Not Found (HTTP 404)")
        if contents and method == "PUT":
            assert body["branch"] == BRANCH
            if contents.group(1) in self.files:
                raise wpab.TransportError(422, 'gh: "sha" wasn\'t supplied. (HTTP 422)')
            self.files[contents.group(1)] = __import__("base64").b64decode(body["content"]).decode("utf-8")
            self.head = "c{}".format(len(self.commits))
            self.commits.append({"sha": self.head, "message": body["message"]})
            return 201, json.dumps({"commit": {"sha": self.head}, "content": {"sha": "blob"}})
        if method == "GET" and path == "repos/example/runtime/git/ref/heads/" + BRANCH:
            return 200, json.dumps({"object": {"sha": self.head}})
        commit = re.fullmatch(r"repos/example/runtime/git/commits/(.+)", path)
        if commit and method == "GET":
            for entry in self.commits:
                if entry["sha"] == commit.group(1):
                    return 200, json.dumps({"tree": {"sha": entry.get("tree", "t0")}})
            raise wpab.TransportError(404, "(HTTP 404)")
        if method == "POST" and path == "repos/example/runtime/git/trees":
            sha = "t{}".format(len(self.trees))
            self.trees[sha] = {"base": body["base_tree"], "entries": body["tree"]}
            return 201, json.dumps({"sha": sha})
        if method == "POST" and path == "repos/example/runtime/git/commits":
            sha = "c{}".format(len(self.commits))
            self.commits.append({"sha": sha, "tree": body["tree"], "parents": body["parents"], "message": body["message"]})
            return 201, json.dumps({"sha": sha})
        if method == "PATCH" and path == "repos/example/runtime/git/refs/heads/" + BRANCH:
            if self.ref_patch_failures:
                self.ref_patch_failures -= 1
                raise wpab.TransportError(422, "gh: Update is not a fast forward (HTTP 422)")
            target = next(c for c in self.commits if c["sha"] == body["sha"])
            assert target["parents"] == [self.head], "non fast-forward commit"
            for entry in self.trees[target["tree"]]["entries"]:
                self.files[entry["path"]] = entry["content"]
            self.head = body["sha"]
            return 200, json.dumps({"object": {"sha": self.head}})
        raise AssertionError("unexpected call {} {}".format(method, path))


def run(github, *argv):
    out = io.StringIO()
    with redirect_stdout(out):
        code = wpab.main(["--repo", REPO, *argv], transport=github)
    text = out.getvalue()
    first = json.loads(text.splitlines()[0])
    return code, first, text


def check(condition, message):
    if not condition:
        raise SystemExit("helper-wpab-transport-test: " + message)


# --- submit -----------------------------------------------------------------
gh = FakeGitHub()
code, out, _ = run(gh, "submit", "--id", "post-get-1", "--operation", "post.get", "--params", '{"post_id":1}')
check(code == 0 and out["ok"] and out["stage"] == "published", "submit should publish")
pending = json.loads(gh.files["wordpress-bridge/commands/pending/post-get-1.json"])
check(pending["type"] == "operation" and pending["operation"] == "post.get" and pending["params"] == {"post_id": 1},
      "pending command shape")
check(re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", pending["created_at"]) is not None, "created_at added")

code, out, _ = run(gh, "submit", "--id", "post-get-1", "--operation", "post.get", "--params", '{"post_id":1}')
check(code == 0 and out["code"] == "already_pending", "identical resubmit is reported as already pending")

code, out, _ = run(gh, "submit", "--id", "post-get-1", "--operation", "post.get", "--params", '{"post_id":2}')
check(code == wpab.EXIT_REFUSED and out["code"] == "pending_conflict", "different payload under same ID is refused")

code, out, _ = run(gh, "submit", "--id", "bad id", "--operation", "post.get")
check(code == wpab.EXIT_ERROR and "id must be" in out["message"], "unsafe id refused")

gh.files["wordpress-bridge/results/post-get-1.json"] = json.dumps({"ok": True, "outcome": {"status": "succeeded"}})
code, out, _ = run(gh, "submit", "--id", "post-get-1", "--operation", "post.get", "--params", '{"post_id":1}')
check(code == wpab.EXIT_REFUSED and out["code"] == "result_exists", "finished ID is not re-published")

with tempfile.TemporaryDirectory() as tmp:
    command_file = Path(tmp) / "command.json"
    command_file.write_text(json.dumps({"id": "bridge-1", "request_id": "bridge-1", "type": "bridge",
                                        "action": "theme.files.list", "params": {}}), encoding="utf-8")
    code, out, _ = run(gh, "submit", "--file", str(command_file))
    check(code == 0 and out["command"]["action"] == "theme.files.list", "exact command file publishes as-is")

# --- result -----------------------------------------------------------------
sleeps = []
clock = {"now": 100.0}


def fake_sleep(seconds):
    sleeps.append(round(seconds, 3))
    clock["now"] += seconds


def fake_clock():
    return clock["now"]


original = wpab.cmd_result
wpab.cmd_result = lambda runtime, args: original(runtime, args, sleep=fake_sleep, clock=fake_clock)

code, out, _ = run(gh, "result", "bridge-1", "--wait")
check(code == wpab.EXIT_PENDING and out["code"] == "pending_or_unknown", "missing result twice is pending_or_unknown")
check(len(out["observations"]) == 2, "exactly two observations with --wait")
check(sleeps == [5, 5] and out["observations"][0]["elapsed_seconds"] >= 5 and out["observations"][1]["elapsed_seconds"] >= 10,
      "first read >=5 s, second read >=10 s: {} {}".format(sleeps, out["observations"]))

long_result = "\n".join(["{\"ok\": true, \"outcome\": {\"status\": \"succeeded\", \"ok\": true},"] + ['"line%d": 1,' % i for i in range(120)] + ["\"end\": 1}"])
gh.files["wordpress-bridge/results/bridge-1.json"] = long_result
code, out, text = run(gh, "result", "bridge-1")
check(code == 0 and out["truncated"] is True and len(text.splitlines()) == 81, "default head shows 80 lines plus header")
code, out, text = run(gh, "result", "bridge-1", "--full")
check(code == 0 and out["truncated"] is False and len(text.splitlines()) == 1 + 122, "--full shows every line")
code, out, text = run(gh, "result", "post-get-1")
check(out["summary"]["outcome"]["status"] == "succeeded", "outcome header summarized")
wpab.cmd_result = original

# --- capabilities / connection ----------------------------------------------
gh.files["wordpress-bridge/RUNTIME_CAPABILITIES.json"] = json.dumps({
    "schema": 2, "bridge_version": "1.1.41",
    "routes": {"operations": {"operations": ["health", "post.get", "post.update"]}}})
code, out, _ = run(gh, "capabilities", "--operations")
check(code == 0 and out["operations"] == ["health", "post.get", "post.update"], "operation names extracted")

gh.default_files["wordpress-bridge/RUNTIME_CONNECTION.json"] = "{}"
code, out, _ = run(gh, "connection")
check(code == wpab.EXIT_ERROR and out["code"] == "marker_not_found", "default-branch mirror is never read as runtime")
marker = {"status": "canonical", "transport": "direct-github-webhook", "ownership": "user-owned",
          "operator_relay": False, "runtime_branch": BRANCH, "repository": REPO, "site_host": "example.com"}
gh.files["wordpress-bridge/RUNTIME_CONNECTION.json"] = json.dumps(marker)
code, out, _ = run(gh, "connection")
check(code == 0 and out["ok"] and out["mismatches"] == {}, "canonical marker accepted")
gh.files["wordpress-bridge/RUNTIME_CONNECTION.json"] = json.dumps(dict(marker, operator_relay=True))
code, out, _ = run(gh, "connection")
check(code == wpab.EXIT_REFUSED and "operator_relay" in out["mismatches"], "relay marker refused")
gh.files["wordpress-bridge/RUNTIME_CONNECTION.json"] = json.dumps(marker)

# --- publish-media -------------------------------------------------------------
with tempfile.TemporaryDirectory() as tmp:
    source = Path(tmp) / "photo.png"
    source.write_bytes(os.urandom(8192 * 25 - 10))  # 25 chunks -> two pushes
    connection = Path(tmp) / "RUNTIME_CONNECTION.json"
    connection.write_text(json.dumps(marker), encoding="utf-8")
    prepared = Path(tmp) / "prepared"
    preparer_copy = Path(tmp) / "prepare-media.py"
    preparer_copy.write_text(PREPARER.read_text(encoding="utf-8"), encoding="utf-8")
    completed = subprocess.run([sys.executable, "-I", str(preparer_copy), str(source), "--connection", str(connection),
                                "--request-id", "media-1", "--output", str(prepared)], capture_output=True, text=True)
    check(completed.returncode == 0, "prepare-media.py failed: " + completed.stdout + completed.stderr)
    manifest = json.loads((prepared / "manifest.json").read_text(encoding="utf-8"))
    check(manifest["chunk_count"] == 25, "expected 25 chunks")

    # Pretend chunk 3 already finished, so it must be skipped.
    gh.files["wordpress-bridge/results/media-1.chunk.0003.json"] = "{\"ok\": true}"
    gh.ref_patch_failures = 1  # first ref update loses a race once; publication must retry and succeed
    code, out, _ = run(gh, "publish-media", str(prepared))
    check(code == 0 and out["published"] == 24 and out["skipped_with_result"] == 1, "24 published, 1 skipped: %s" % out)
    check([c["chunks"] for c in out["commits"]] == [[0, 20], [21, 24]], "batches of <=20 in ascending order: %s" % out["commits"])
    for index in range(25):
        if index == 3:
            check("wordpress-bridge/commands/pending/media-1.chunk.0003.json" not in gh.files, "finished chunk not re-published")
            continue
        path = "wordpress-bridge/commands/pending/media-1.chunk.%04d.json" % index
        check(path in gh.files, "missing " + path)
        check(json.loads(gh.files[path])["body"]["chunk_index"] == index, "payload preserved for " + path)
    exact = {entry["path"]: entry["content"] for entry in json.loads((prepared / "git-tree.json").read_text(encoding="utf-8"))["tree"]}
    for path, content in exact.items():
        if path.endswith("0003.json"):
            continue
        check(gh.files[path] == content, "chunk content must be byte-identical: " + path)

    bad_manifest = Path(tmp) / "bad"
    bad_manifest.mkdir()
    (bad_manifest / "manifest.json").write_text(json.dumps(dict(manifest, repository="other/repo")), encoding="utf-8")
    (bad_manifest / "git-tree.json").write_text((prepared / "git-tree.json").read_text(encoding="utf-8"), encoding="utf-8")
    code, out, _ = run(gh, "publish-media", str(bad_manifest))
    check(code == wpab.EXIT_REFUSED and out["code"] == "binding_mismatch", "foreign binding refused")

# --- access errors -----------------------------------------------------------
class Forbidden:
    def request(self, *args, **kwargs):
        raise wpab.TransportError(403, "gh: Resource not accessible (HTTP 403)")


code, out, _ = run(Forbidden(), "capabilities")
check(code == wpab.EXIT_ERROR and out["code"] == "access_error" and out["http_status"] == 403, "403 reported as access error")

print("helper-wpab-transport-test: ok")
