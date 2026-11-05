"""Contracts for Make output boundaries; runs without application dependencies."""
import importlib.util
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("make_support", Path(__file__).with_name("make_support.py"))
SUPPORT = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(SUPPORT)
ROOT = SUPPORT.ROOT


class OutputContracts(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix=".make-contract-", dir=ROOT)
        self.area = Path(self.temporary.name)

    def tearDown(self):
        self.temporary.cleanup()

    def test_root_parent_and_metadata_are_refused(self):
        for value in [ROOT, ROOT.parent, ROOT / "../outside", ROOT / ".git", ROOT / ".github"]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                SUPPORT.checked(str(value))

    def test_tracked_sources_are_refused(self):
        tracked = subprocess.check_output(["git", "-C", str(ROOT), "ls-files", "-z"], text=True).split("\0")
        source = next(name for name in tracked if name and (ROOT / name).is_file())
        with self.assertRaises(ValueError):
            SUPPORT.checked(source)
        with self.assertRaises(ValueError):
            SUPPORT.checked(str((ROOT / source).parent))

    def test_clean_preserves_spaces_and_checks_all_arguments_before_deleting(self):
        output = self.area / "output with spaces.txt"
        output.write_text("fixture", encoding="utf-8")
        command = [sys.executable, str(Path(SUPPORT.__file__)), "clean", str(output)]
        refused = subprocess.run(command + [str(ROOT)], capture_output=True)
        self.assertNotEqual(0, refused.returncode)
        self.assertTrue(output.exists())
        subprocess.run(command, check=True, capture_output=True)
        self.assertFalse(output.exists())

    def test_symlink_escape_is_refused(self):
        link = self.area / "escape"
        try:
            link.symlink_to(ROOT.parent, target_is_directory=True)
        except OSError:
            self.skipTest("creating symlinks is unavailable on this platform")
        with self.assertRaises(ValueError):
            SUPPORT.checked(str(link))
        with self.assertRaises(ValueError):
            SUPPORT.checked(str(self.area))

    def test_untracked_configuration_and_data_are_protected(self):
        for name in [".env.local", "config", "data", "storage", "uploads", "secrets"]:
            with self.subTest(name=name), self.assertRaises(ValueError):
                SUPPORT.checked(str(self.area / name))

    def test_source_archive_with_spaces_has_valid_crc(self):
        import zipfile
        output = self.area / "source archive.zip"
        subprocess.run([sys.executable, str(Path(SUPPORT.__file__)), "source-package", str(output)], check=True, capture_output=True)
        with zipfile.ZipFile(output) as archive:
            self.assertIsNone(archive.testzip())
            self.assertIn("Makefile", archive.namelist())


if __name__ == "__main__":
    unittest.main(verbosity=2)
