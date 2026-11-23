"""Tests for tools/server_update.sh. Run with: python -m unittest discover -s tools/tests

The full run needs bash and rsync (Linux); it is skipped where they are missing.
"""

import os
import shutil
import stat
import subprocess
import tempfile
import unittest
from pathlib import Path

SCRIPT = Path(__file__).resolve().parent.parent / "server_update.sh"


def write(path: Path, text: str = "x", executable: bool = False) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(text.encode("utf-8"))
    if executable:
        path.chmod(path.stat().st_mode | stat.S_IXUSR)


class ScriptTextTest(unittest.TestCase):
    """Rules that must hold even where the script cannot run."""

    def setUp(self) -> None:
        self.text = SCRIPT.read_text(encoding="utf-8")

    def test_stops_on_errors_and_only_fast_forwards(self) -> None:
        self.assertIn("set -euo pipefail", self.text)
        self.assertIn("pull --ff-only", self.text)

    def test_keeps_the_settings_and_the_virtual_environment(self) -> None:
        for kept in ("--exclude '.env'", "--exclude '.env.*'", "--exclude 'venv/'"):
            self.assertIn(kept, self.text)

    def test_holds_no_secret_or_address(self) -> None:
        for word in ("TOKEN=", "API_KEY=", "SECRET="):
            self.assertNotIn(word, self.text)
        self.assertNotRegex(self.text, r"\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b")

    def test_uses_unix_line_endings(self) -> None:
        self.assertNotIn(b"\r\n", SCRIPT.read_bytes())


@unittest.skipUnless(shutil.which("bash") and shutil.which("rsync") and os.name == "posix", "needs bash and rsync")
class ScriptRunTest(unittest.TestCase):
    def setUp(self) -> None:
        folder = tempfile.TemporaryDirectory()
        self.addCleanup(folder.cleanup)
        self.work = Path(folder.name)
        self.calls = self.work / "calls.log"

        # The source checkout.
        self.src = self.work / "src"
        write(self.src / "bot" / "darimaa_bot" / "__init__.py", '__version__ = "9.9.9"\n')
        write(self.src / "bot" / "requirements.txt", "httpx\n")
        write(self.src / "bot" / ".env.example", "MOODLE_TOKEN=paste-the-token-here\n")
        write(self.src / "plugin" / "version.php", "$plugin->release = '9.9.0';\n")

        # The installed bot, with its settings and virtual environment.
        self.bot = self.work / "bot"
        write(self.bot / ".env", "MOODLE_TOKEN=kept\n")
        write(self.bot / "venv" / "bin" / "pip", f'#!/bin/sh\necho "pip $*" >> "{self.calls}"\n', executable=True)
        write(self.bot / "old_file.py", "removed\n")

        # Moodle 5.1 layout: plugins in public/, command line scripts in admin/cli at the top.
        self.moodle = self.work / "moodle"
        write(self.moodle / "admin" / "cli" / "upgrade.php", "<?php\n")
        (self.moodle / "public" / "local").mkdir(parents=True)

        # Commands that must not touch the real system.
        self.bin = self.work / "bin"
        for name in ("git", "systemctl", "chown", "php"):
            write(self.bin / name, f'#!/bin/sh\necho "{name} $*" >> "{self.calls}"\n', executable=True)
        write(self.bin / "sudo", f'#!/bin/sh\necho "sudo $*" >> "{self.calls}"\n', executable=True)

    def run_script(self) -> subprocess.CompletedProcess:
        env = dict(os.environ, PATH=f"{self.bin}:{os.environ['PATH']}", DARIMAA_TEST="1",
                   DARIMAA_SRC=str(self.src), DARIMAA_BOT_DIR=str(self.bot), MOODLE_DIR=str(self.moodle))
        return subprocess.run(["bash", str(SCRIPT)], env=env, capture_output=True, text=True)

    def test_copies_bot_and_plugin_and_keeps_settings(self) -> None:
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)

        self.assertEqual((self.bot / ".env").read_text(), "MOODLE_TOKEN=kept\n")
        self.assertTrue((self.bot / "venv" / "bin" / "pip").exists())
        self.assertTrue((self.bot / ".env.example").exists())
        self.assertFalse((self.bot / "old_file.py").exists())
        self.assertIn("9.9.9", (self.bot / "darimaa_bot" / "__init__.py").read_text())
        self.assertTrue((self.moodle / "public" / "local" / "darimaa" / "version.php").exists())

        calls = self.calls.read_text()
        self.assertIn("pull --ff-only", calls)
        self.assertIn("pip install --quiet -r", calls)
        self.assertIn("systemctl restart darimaa-bot", calls)
        self.assertIn(f"php {self.moodle}/admin/cli/upgrade.php --non-interactive", calls)
        self.assertIn(f"php {self.moodle}/admin/cli/purge_caches.php", calls)

    def test_moodle_5_0_layout(self) -> None:
        shutil.rmtree(self.moodle)
        write(self.moodle / "admin" / "cli" / "upgrade.php", "<?php\n")
        (self.moodle / "local").mkdir(parents=True)
        result = self.run_script()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue((self.moodle / "local" / "darimaa" / "version.php").exists())
        self.assertIn(f"php {self.moodle}/admin/cli/upgrade.php --non-interactive", self.calls.read_text())

    def test_refuses_without_moodle(self) -> None:
        shutil.rmtree(self.moodle)
        result = self.run_script()
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Moodle not found", result.stderr)


if __name__ == "__main__":
    unittest.main()
