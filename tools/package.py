"""Builds the install packages of the plugin and the bot.

Run from anywhere:  python tools/package.py

It writes two zip files into dist/:
  local_darimaa-<release>.zip   top-level folder darimaa/      (from plugin/)
  darimaa-bot-<version>.zip     top-level folder darimaa-bot/  (from bot/)

The versions come from plugin/version.php and bot/darimaa_bot/__init__.py. Secrets, virtual
environments and caches are left out. Only the Python standard library is used.
"""

from __future__ import annotations

import fnmatch
import re
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Folders and files that never go into a package, wherever they are.
SKIP_NAMES = {
    ".env", ".venv", "venv", "__pycache__", ".pytest_cache", ".git", ".github", ".idea", ".vscode",
    "node_modules", ".DS_Store", "Thumbs.db",
}
SKIP_PATTERNS = ["*.pyc", "*.pyo", "*.sqlite", "*.sqlite3", "*.db", "*.zip", "*.log"]
# Files named .env.<something> hold secrets too. The example file has placeholders only.
KEEP_NAMES = {".env.example"}


class PackageError(Exception):
    """The package could not be built, or it does not look right."""


def is_skipped(name: str) -> bool:
    """Whether a file or folder with this name is left out."""
    if name in KEEP_NAMES:
        return False
    if name in SKIP_NAMES or name.startswith(".env."):
        return True
    return any(fnmatch.fnmatch(name, pattern) for pattern in SKIP_PATTERNS)


def list_files(source: Path) -> list[Path]:
    """Lists the files to package, sorted, as paths relative to the source folder."""
    found: list[Path] = []

    def walk(folder: Path) -> None:
        for entry in sorted(folder.iterdir(), key=lambda item: item.name):
            if is_skipped(entry.name) or entry.is_symlink():
                continue
            if entry.is_dir():
                walk(entry)
            elif entry.is_file():
                found.append(entry.relative_to(source))

    walk(source)
    return found


def read_version(file: Path, pattern: str) -> str:
    """Reads a version number from a source file."""
    match = re.search(pattern, file.read_text(encoding="utf-8"), re.MULTILINE)
    if not match:
        raise PackageError(f"No version found in {file}")
    return match.group(1)


def plugin_release(root: Path = ROOT) -> str:
    return read_version(root / "plugin" / "version.php", r"^\$plugin->release\s*=\s*'([0-9][0-9A-Za-z.+-]*)';")


def bot_version(root: Path = ROOT) -> str:
    return read_version(root / "bot" / "darimaa_bot" / "__init__.py", r'^__version__\s*=\s*"([0-9][0-9A-Za-z.+-]*)"')


def build(source: Path, top: str, target: Path, required: str) -> list[str]:
    """Writes one zip file and checks it. Returns the names inside the zip."""
    if not source.is_dir():
        raise PackageError(f"Folder not found: {source}")
    files = list_files(source)
    target.parent.mkdir(parents=True, exist_ok=True)

    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for relative in files:
            info = zipfile.ZipInfo.from_file(source / relative, f"{top}/{relative.as_posix()}")
            info.compress_type = zipfile.ZIP_DEFLATED
            # The same rights on every system: readable by all, writable by the owner.
            info.external_attr = 0o644 << 16
            archive.writestr(info, (source / relative).read_bytes())

    with zipfile.ZipFile(target) as archive:
        names = archive.namelist()
    check(names, top, required)
    return names


def check(names: list[str], top: str, required: str) -> None:
    """Makes sure a package has what it needs and nothing it must not have."""
    if f"{top}/{required}" not in names:
        raise PackageError(f"{top}/{required} is missing from the package")
    for name in names:
        parts = name.split("/")
        if parts[0] != top:
            raise PackageError(f"{name} is outside the folder {top}/")
        skipped = [part for part in parts if is_skipped(part)]
        if skipped:
            raise PackageError(f"{name} must not be in the package")


def main() -> int:
    dist = ROOT / "dist"
    packages = [
        (ROOT / "plugin", "darimaa", dist / f"local_darimaa-{plugin_release()}.zip", "version.php"),
        (ROOT / "bot", "darimaa-bot", dist / f"darimaa-bot-{bot_version()}.zip", "requirements.txt"),
    ]
    for source, top, target, required in packages:
        names = build(source, top, target, required)
        size = target.stat().st_size / 1024
        print(f"{target.relative_to(ROOT).as_posix()}: {len(names)} files, {size:.0f} KB, top-level folder {top}/")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except PackageError as error:
        print(f"Packaging failed: {error}", file=sys.stderr)
        sys.exit(1)
