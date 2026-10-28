#!/usr/bin/env python3
"""Create a source release from explicit public folders; never copy a working tree wholesale."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import tempfile
import zipfile

ROOT = Path(__file__).resolve().parents[1]
FOLDERS = {"app": {".php"}, "public": {".php", ".js", ".css", ".svg", ".png", ".ico", ".woff2"},
           "docs": {".md"}, "deploy": {".example"}}
FILES = ["README.md", "CHANGELOG.md", "start.sh", "config/config.example.php",
         "scripts/workspace-api.py", "scripts/cache-dns.php", "scripts/reset-admin-password.php", "scripts/check-public-docs.py", "scripts/package-release.py"]
REVIEWED_SCREENSHOTS = {"public/assets/screenshots/login.png", "public/assets/screenshots/dashboard.png", "public/assets/screenshots/domains.png"}


def release_files(root):
    selected = set(FILES)
    for folder, extensions in FOLDERS.items():
        for path in (root / folder).rglob("*"):
            if path.is_symlink():
                raise ValueError("Release folders must not contain symbolic links")
            if not path.is_file():
                continue
            if path.name in {".DS_Store", "Thumbs.db"}:  # OS metadata is never an application source.
                continue
            relative = path.relative_to(root)
            if any(part.startswith(".") for part in relative.parts) or path.suffix not in extensions:
                raise ValueError("Unexpected file in a public release folder: " + str(relative))
            if path.suffix == ".png" and relative.as_posix() not in REVIEWED_SCREENSHOTS:
                raise ValueError("Unreviewed screenshot in a public release folder: " + str(relative))
            selected.add(relative.as_posix())
    for name in sorted(selected):
        path = root / name
        if not path.is_file() or path.is_symlink() or root.resolve() not in path.resolve().parents:
            raise ValueError("Missing or unsafe release source: " + name)
        yield name, path


def build_release(root, output_dir):
    root, output_dir = Path(root), Path(output_dir)
    public = (root / "public").resolve()
    output = output_dir.resolve()
    if output == public or public in output.parents:
        raise ValueError("Release archives must be stored outside public/")
    subprocess.run([sys.executable, str(root / "scripts/check-public-docs.py")], cwd=root, check=True, capture_output=True)
    version_source = (root / "app/services/AssetSnapshot.php").read_text()
    match = re.search(r"public const VERSION = '([0-9]+\.[0-9]+\.[0-9]+)'", version_source)
    if not match:
        raise ValueError("Release version is missing")
    version = match.group(1)
    sources = list(release_files(root))
    manifest = {"product": "Asset Workspace", "version": version, "created_at": datetime.now(timezone.utc).isoformat(),
                "scope": "source-only; no runtime configuration, assets, credentials or Git history",
                "files": {name: hashlib.sha256(path.read_bytes()).hexdigest() for name, path in sources}}
    output.mkdir(parents=True, exist_ok=True)
    archive = output / ("asset-workspace-" + version + ".zip")
    checksum = archive.with_suffix(".zip.sha256")
    if archive.exists() or checksum.exists():
        raise FileExistsError("Release already exists; use a new output directory")
    descriptor, temporary = tempfile.mkstemp(prefix=".release-", dir=output)
    os.close(descriptor)
    try:
        with zipfile.ZipFile(temporary, "w", zipfile.ZIP_DEFLATED) as package:
            for name, path in sources:
                package.write(path, "asset-workspace/" + name)
            package.writestr("asset-workspace/manifest.json", json.dumps(manifest, ensure_ascii=False, indent=2))
        os.chmod(temporary, 0o600)
        # Exclusive link avoids overwriting another build that completed concurrently.
        os.link(temporary, archive)
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        with checksum.open("x") as file:
            file.write(digest + "  " + archive.name + "\n")
        os.chmod(checksum, 0o600)
    finally:
        os.unlink(temporary)
    return archive, digest, len(sources)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=ROOT / "dist")
    args = parser.parse_args()
    try:
        archive, digest, count = build_release(ROOT, args.output_dir)
        print(f"Created {archive}\nSHA-256 {digest}\n{count} public source files; no config/config.php, data/, .local/ or .git/")
    except (OSError, ValueError, subprocess.CalledProcessError) as error:
        print("Release failed: " + str(error), file=sys.stderr)
        sys.exit(1)
