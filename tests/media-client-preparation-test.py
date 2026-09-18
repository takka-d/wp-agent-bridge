"""Exercise the connector-safe client media preparer."""
import base64
import contextlib
import hashlib
import importlib.util
from importlib.machinery import SourceFileLoader
import io
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
loader = SourceFileLoader("media_preparer", str(ROOT / "plugin/wp-agent-bridge/client/prepare-media.py.txt"))
spec = importlib.util.spec_from_loader(loader.name, loader)
media = importlib.util.module_from_spec(spec)
spec.loader.exec_module(media)
CONNECTION = {"status": "canonical", "transport": "direct-github-webhook", "ownership": "user-owned",
              "operator_relay": False, "repository": "example/site-runtime", "site_host": "example.test",
              "runtime_branch": "wp-agent-bridge-runtime"}


class MediaPreparationTest(unittest.TestCase):
    def check_roundtrip(self, size):
        original = bytes(range(256)) * (size // 256) + bytes(range(size % 256))
        package = media.prepare_bytes(original, "original.png", "test-media", CONNECTION, {"post_id": 123, "alt_text": "元の画像"})
        entries = package["tree"]
        commands = package["commands"]
        self.assertEqual(len(entries), len(commands))
        self.assertEqual(len(commands), (size + media.SAFE_CHUNK_BYTES - 1) // media.SAFE_CHUNK_BYTES)
        self.assertLessEqual(len(commands), media.MAX_CHUNKS)
        self.assertEqual(package["command"], commands[-1])
        self.assertEqual(package["manifest"]["final_result_path"],
                         "wordpress-bridge/results/{}.json".format(commands[-1]["id"]))
        self.assertEqual(package["manifest"]["publication_order"], "ascending_chunk_index")
        self.assertEqual(package["manifest"]["max_commands_per_git_push"], 20)

        chunks = []
        ids = set()
        for index, (entry, command) in enumerate(zip(entries, commands)):
            self.assertEqual(json.loads(entry["content"]), command)
            self.assertEqual(command["id"], command["request_id"])
            self.assertNotIn(command["id"], ids)
            ids.add(command["id"])
            self.assertEqual(command["route"], "/wp-agent-bridge-media/v1/upload-chunk")
            self.assertEqual(command["method"], "POST")
            self.assertEqual(command["type"], "rest")
            body = command["body"]
            self.assertEqual(body["upload_id"], "test-media")
            self.assertEqual(body["chunk_index"], index)
            self.assertEqual(body["chunk_count"], len(commands))
            self.assertEqual(body["expected_bytes"], len(original))
            self.assertEqual(body["expected_sha256"], hashlib.sha256(original).hexdigest())
            chunk = base64.b64decode(body["data_b64"], validate=True)
            self.assertLessEqual(len(chunk), media.SAFE_CHUNK_BYTES)
            self.assertEqual(len(chunk), body["chunk_bytes"])
            self.assertEqual(hashlib.sha256(chunk).hexdigest(), body["chunk_sha256"])
            self.assertLessEqual(len(entry["content"].encode("utf-8")), media.MAX_COMMAND_JSON_BYTES)
            chunks.append(chunk)

        rebuilt = b"".join(chunks)
        self.assertEqual(rebuilt, original)
        self.assertFalse(package["manifest"]["uploaded"])
        self.assertEqual(package["manifest"]["expected_bytes"], len(original))
        self.assertEqual(package["manifest"]["expected_sha256"], hashlib.sha256(original).hexdigest())
        self.assertLessEqual(package["manifest"]["max_command_json_bytes"], media.MAX_COMMAND_JSON_BYTES)
        for entry, expected in zip(entries, package["manifest"]["git_blobs"]):
            raw = entry["content"].encode("utf-8")
            self.assertEqual(expected["path"], entry["path"])
            self.assertEqual(expected["bytes"], len(raw))
            self.assertEqual(expected["sha"], hashlib.sha1(b"blob " + str(len(raw)).encode() + b"\0" + raw).hexdigest())
        return package

    def test_boundaries_and_original_byte_preservation(self):
        for size in (1, 13_232, 101_999, 1_048_576, 1_048_577, 4_194_305, 6_291_456):
            with self.subTest(size=size):
                self.check_roundtrip(size)

    def test_stable_retry_payload(self):
        first = self.check_roundtrip(101_999)
        self.assertEqual(first, self.check_roundtrip(101_999))

    def test_no_metadata_override_or_path_injection(self):
        for options in ({"data_b64": "invalid"}, {"expected_sha256": "0" * 64}, {"post_id": True}):
            with self.assertRaises(media.PreparationError):
                media.prepare_bytes(b"data", "image.png", "id", CONNECTION, options)
        for request_id in ("../escape", "id/command", "a" * 81):
            with self.assertRaises(media.PreparationError):
                media.prepare_bytes(b"data", "image.png", request_id, CONNECTION)

    def test_connection_checks(self):
        for override in ({"status": "retired"}, {"operator_relay": True}, {"operator_relay": 0}, {"runtime_branch": "main"}):
            with self.assertRaises(media.PreparationError):
                media.prepare_bytes(b"data", "image.png", "id", dict(CONNECTION, **override))

    def test_empty_and_oversized_are_not_compressed(self):
        for data in (b"", b"x" * 6_291_457):
            with self.assertRaises(media.PreparationError) as caught:
                media.prepare_bytes(data, "image.png", "id", CONNECTION)
            self.assertEqual(caught.exception.code, "asset_size")

    def test_cli_reads_real_file_and_does_not_print_payload(self):
        png = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=")
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "source.png").write_bytes(png)
            (root / "connection.json").write_text(json.dumps(CONNECTION), encoding="utf-8")
            args = [str(root / "source.png"), "--connection", str(root / "connection.json"), "--request-id", "png", "--output", str(root / "prepared")]
            stdout = io.StringIO()
            with contextlib.redirect_stdout(stdout):
                self.assertEqual(media.main(args), 0)
            self.assertNotIn("data_b64", stdout.getvalue())
            commands = json.loads((root / "prepared/commands.json").read_text(encoding="utf-8"))
            self.assertEqual(base64.b64decode(commands[0]["body"]["data_b64"], validate=True), png)
            before = (root / "prepared/git-tree.json").read_bytes()
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(media.main(args), 1)
            self.assertEqual((root / "prepared/git-tree.json").read_bytes(), before)

    def test_missing_asset_is_not_a_github_error(self):
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(media.PreparationError) as caught:
                media.prepare_file(Path(directory) / "missing.png", None, "id", CONNECTION)
            self.assertEqual(caught.exception.code, "asset_unavailable")

    @unittest.skipUnless(shutil.which("php"), "PHP consumer is checked on the CI runner")
    def test_actual_php_consumer_accepts_generated_packages(self):
        for size in (1, 13_232, 1_048_576, 6_291_456):
            with self.subTest(size=size):
                package = self.check_roundtrip(size)
                result = subprocess.run([shutil.which("php"), str(ROOT / "tests/media-client-contract-test.php")],
                                        input=media.encoded_json(package), text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn("media-client-contract-test: ok", result.stdout)


if __name__ == "__main__":
    unittest.main()
