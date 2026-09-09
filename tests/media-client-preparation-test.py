"""Exercise the actual client preparer and independently reconstruct its output."""
import base64
import contextlib
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("media_preparer", ROOT / "plugin/wp-agent-bridge/client/prepare-media.py")
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
        command = json.loads(entries[-1]["content"])
        self.assertEqual(command, package["command"])
        self.assertEqual(command["id"], command["request_id"])
        self.assertEqual(entries[-1]["path"], package["manifest"]["command_path"])
        if size <= 1_048_576:
            self.assertEqual(len(entries), 1)
            self.assertEqual(command["operation"], "media.upload.inline")
            rebuilt = base64.b64decode(command["params"]["data_b64"], validate=True)
            metadata = command["params"]
        else:
            self.assertLessEqual(len(entries) - 1, 32)
            self.assertEqual(command["route"], "/wp-agent-bridge-runtime/v1/media-upload")
            metadata = command["body"]
            self.assertEqual(metadata["data_paths"], [entry["path"] for entry in entries[:-1]])
            chunks = []
            for entry, integrity in zip(entries[:-1], metadata["chunk_integrity"]):
                chunk = base64.b64decode(entry["content"], validate=True)
                self.assertEqual(set(integrity), {"expected_bytes", "expected_sha256"})
                self.assertEqual(len(chunk), integrity["expected_bytes"])
                self.assertEqual(hashlib.sha256(chunk).hexdigest(), integrity["expected_sha256"])
                chunks.append(chunk)
            rebuilt = b"".join(chunks)
        self.assertEqual(rebuilt, original)
        self.assertEqual(metadata["expected_bytes"], len(original))
        self.assertEqual(metadata["expected_sha256"], hashlib.sha256(original).hexdigest())
        self.assertFalse(package["manifest"]["uploaded"])
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
        # A real PNG is passed through byte-for-byte; no image processing library.
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
            tree = json.loads((root / "prepared/git-tree.json").read_text(encoding="utf-8"))["tree"]
            command = json.loads(tree[0]["content"])
            self.assertEqual(base64.b64decode(command["params"]["data_b64"], validate=True), png)
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
    def test_actual_php_consumers_accept_generated_packages(self):
        for size in (1_048_576, 1_048_577, 6_291_456):
            with self.subTest(size=size):
                package = self.check_roundtrip(size)
                result = subprocess.run([shutil.which("php"), str(ROOT / "tests/media-client-contract-test.php")],
                                        input=media.encoded_json(package), text=True, capture_output=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn("media-client-contract-test: ok", result.stdout)


if __name__ == "__main__":
    unittest.main()
