# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Composer's root-only require-dev semantics for the Snyk scan input."""
import copy
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

import prepare_snyk_composer_locks as scan


class SnykComposerLocksTest(unittest.TestCase):
    def lock(self):
        return {
            "content-hash": "owned-manifest-hash",
            "packages": [{"name": "owned/runtime", "version": "1.0.0",
                          "require": {"owned/shared": "^2"},
                          "require-dev": {"owned/uninstalled": "^1"}}],
            "packages-dev": [{"name": "owned/test", "version": "2.0.0",
                              "require": {"owned/shared": "^2"},
                              "require-dev": {"laravel/framework": "8.x-dev"}}],
        }

    def test_only_dependency_development_metadata_is_removed(self):
        lock = self.lock()
        expected = copy.deepcopy(lock)
        for section in ("packages", "packages-dev"):
            for package in expected[section]:
                del package["require-dev"]
        self.assertEqual(json.loads(scan.prepare(json.dumps(lock))), expected)

    def test_real_locked_development_packages_are_kept_even_when_vulnerable(self):
        lock = self.lock()
        lock["packages-dev"].append({"name": "laravel/framework", "version": "8.0.0"})
        prepared = json.loads(scan.prepare(json.dumps(lock)))
        self.assertIn({"name": "laravel/framework", "version": "8.0.0"},
                      prepared["packages-dev"])

    def test_invalid_and_empty_inventories_fail_closed(self):
        for lock in (None, [], {}, {"packages": [], "packages-dev": []},
                     {"packages": [{}], "packages-dev": []}):
            with self.subTest(lock=lock), self.assertRaises(ValueError):
                scan.prepare(json.dumps(lock))

    def test_invalid_later_input_preserves_all_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            first, second = root / "first.lock", root / "second.lock"
            original = json.dumps(self.lock())
            first.write_text(original)
            second.write_text("invalid")
            result = subprocess.run([sys.executable, scan.__file__, str(first), str(second)],
                                    capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)
            self.assertEqual(first.read_text(), original)
            self.assertEqual(second.read_text(), "invalid")


if __name__ == "__main__":
    unittest.main()
