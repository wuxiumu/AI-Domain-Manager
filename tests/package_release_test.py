"""Check that source releases exclude runtime secrets and remain verifiable."""
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("release_builder", ROOT / "scripts/package-release.py")
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)


class ReleaseTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="workspace-release-test-")
        self.root = Path(self.directory.name) / "source"
        self.root.mkdir()
        for folder in builder.FOLDERS:
            shutil.copytree(ROOT / folder, self.root / folder)
        for name in builder.FILES:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            if not target.exists():
                shutil.copy2(ROOT / name, target)
        for name in ["data/private.json", "config/config.php", ".local/notes.md", ".git/old-secret", "old-archive.zip"]:
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text("PRIVATE-RUNTIME-SENTINEL")
        self.output = Path(self.directory.name) / "release"

    def tearDown(self):
        self.directory.cleanup()

    def test_manifest_and_exclusion(self):
        archive, digest, count = builder.build_release(self.root, self.output)
        self.assertEqual(hashlib.sha256(archive.read_bytes()).hexdigest(), digest)
        self.assertTrue(archive.with_suffix(".zip.sha256").read_text().startswith(digest))
        self.assertEqual(archive.stat().st_mode & 0o777, 0o600)
        with zipfile.ZipFile(archive) as package:
            self.assertTrue(all(name.startswith("asset-workspace/") for name in package.namelist()))
            manifest = json.loads(package.read("asset-workspace/manifest.json"))
            self.assertEqual(len(manifest["files"]), count)
            self.assertEqual(manifest["version"], "1.1.1")
            for name, checksum in manifest["files"].items():
                raw = package.read("asset-workspace/" + name)
                self.assertEqual(hashlib.sha256(raw).hexdigest(), checksum)
                self.assertNotIn(b"PRIVATE-RUNTIME-SENTINEL", raw)
            for forbidden in ["data/", "config/config.php", ".local/", ".git/", "old-archive.zip"]:
                self.assertFalse(any(name.startswith("asset-workspace/" + forbidden) for name in package.namelist()))
            self.assertFalse(any(name.endswith(".DS_Store") for name in package.namelist()))
        with self.assertRaises(FileExistsError):
            builder.build_release(self.root, self.output)

    def test_public_output_rejected(self):
        with self.assertRaises(ValueError):
            builder.build_release(self.root, self.root / "public/downloads")

    def test_symlink_rejected(self):
        (self.root / "app/leaked.php").symlink_to(self.root / "config/config.php")
        with self.assertRaises(ValueError):
            list(builder.release_files(self.root))

    def test_unknown_source_attachment_rejected(self):
        (self.root / "public/private.zip").write_bytes(b"private")
        with self.assertRaises(ValueError):
            list(builder.release_files(self.root))

    def test_document_check_blocks_unsafe_release(self):
        (self.root / "docs/01-产品介绍/99-private.md").write_text("Unreviewed attachment ![private](image.png)")
        import subprocess
        with self.assertRaises(subprocess.CalledProcessError):
            builder.build_release(self.root, self.output)
        self.assertFalse(self.output.exists())


if __name__ == "__main__":
    unittest.main()
