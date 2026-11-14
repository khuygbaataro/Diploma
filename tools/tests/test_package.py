"""Tests for tools/package.py. Run with: python -m unittest discover -s tools/tests"""

import sys
import tempfile
import unittest
import zipfile
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import package  # noqa: E402


def write(path: Path, text: str = "x") -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    # Bytes, so that line endings stay the same on Windows.
    path.write_bytes(text.encode("utf-8"))


class PackageTest(unittest.TestCase):
    def setUp(self) -> None:
        self.folder = tempfile.TemporaryDirectory()
        self.work = Path(self.folder.name)
        self.addCleanup(self.folder.cleanup)

    def test_secrets_virtual_environments_and_caches_are_left_out(self) -> None:
        source = self.work / "bot"
        write(source / "requirements.txt", "httpx==0.28.1\n")
        write(source / ".env.example", "MOODLE_TOKEN=paste-the-token-here\n")
        write(source / "darimaa_bot" / "app.py")
        write(source / "tests" / "test_app.py")
        # None of these may be packaged.
        write(source / ".env", "MOODLE_TOKEN=not-a-real-token\n")
        write(source / ".env.production", "MOODLE_TOKEN=not-a-real-token\n")
        write(source / ".venv" / "Scripts" / "python.exe")
        write(source / "venv" / "bin" / "python")
        write(source / "darimaa_bot" / "__pycache__" / "app.cpython-312.pyc")
        write(source / ".pytest_cache" / "README.md")
        write(source / "old.zip")
        write(source / "bot.sqlite3")

        target = self.work / "dist" / "darimaa-bot-9.9.9.zip"
        names = package.build(source, "darimaa-bot", target, "requirements.txt")

        self.assertEqual(sorted(names), [
            "darimaa-bot/.env.example",
            "darimaa-bot/darimaa_bot/app.py",
            "darimaa-bot/requirements.txt",
            "darimaa-bot/tests/test_app.py",
        ])
        with zipfile.ZipFile(target) as archive:
            self.assertEqual(archive.read("darimaa-bot/requirements.txt"), b"httpx==0.28.1\n")
            self.assertIsNone(archive.testzip())

    def test_a_package_without_its_main_file_is_refused(self) -> None:
        source = self.work / "plugin"
        write(source / "lib.php")
        with self.assertRaises(package.PackageError):
            package.build(source, "darimaa", self.work / "dist" / "plugin.zip", "version.php")
        with self.assertRaises(package.PackageError):
            package.build(self.work / "missing", "darimaa", self.work / "dist" / "plugin.zip", "version.php")

    def test_check_refuses_forbidden_or_misplaced_files(self) -> None:
        package.check(["darimaa/version.php", "darimaa/db/access.php"], "darimaa", "version.php")
        for bad in ("darimaa/.env", "darimaa/x/__pycache__/a.pyc", "darimaa/.venv/x", "other/version.php"):
            with self.assertRaises(package.PackageError, msg=bad):
                package.check(["darimaa/version.php", bad], "darimaa", "version.php")

    def test_versions_are_read_from_the_source_files(self) -> None:
        write(self.work / "plugin" / "version.php", "<?php\n$plugin->version = 2026111302;\n$plugin->release = '1.2.3';\n")
        write(self.work / "bot" / "darimaa_bot" / "__init__.py", '"""Bot."""\n\n__version__ = "4.5.6"\n')
        self.assertEqual(package.plugin_release(self.work), "1.2.3")
        self.assertEqual(package.bot_version(self.work), "4.5.6")

        write(self.work / "plugin" / "version.php", "<?php\n")
        with self.assertRaises(package.PackageError):
            package.plugin_release(self.work)

    def test_the_real_packages_have_the_expected_layout(self) -> None:
        dist = self.work / "dist"
        plugin = package.build(package.ROOT / "plugin", "darimaa", dist / "plugin.zip", "version.php")
        bot = package.build(package.ROOT / "bot", "darimaa-bot", dist / "bot.zip", "requirements.txt")

        self.assertIn("darimaa/version.php", plugin)
        self.assertIn("darimaa/db/services.php", plugin)
        self.assertIn("darimaa-bot/requirements.txt", bot)
        self.assertIn("darimaa-bot/darimaa_bot/app.py", bot)
        self.assertIn("darimaa-bot/.env.example", bot)
        for name in plugin + bot:
            for forbidden in (".env", ".venv", "__pycache__", ".pytest_cache"):
                self.assertNotIn(forbidden, name.split("/"), name)
        # The demo scripts and this tool are not part of any package.
        self.assertFalse([name for name in plugin + bot if "demo" in name.split("/") or name.endswith("package.py")])


if __name__ == "__main__":
    unittest.main()
