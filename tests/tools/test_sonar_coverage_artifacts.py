#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

import hashlib
import json
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

import sonar_coverage_artifacts as coverage
from defusedxml.common import DefusedXmlException


class CoverageArtifactTest(unittest.TestCase):
    def setUp(self):
        self.owned = tempfile.TemporaryDirectory()
        self.addCleanup(self.owned.cleanup)
        self.base = Path(self.owned.name).resolve()
        self.producer = self.base / "producer"
        self.producer.mkdir()
        for name in ("src/app.php", "tools/build-offline.py", "public/script.js"):
            path = self.producer / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("fixture source\n")
        self.git("init", "-q")
        self.git("add", ".")
        self.git("-c", "commit.gpgsign=false", "-c", "user.name=Fixture", "-c", "user.email=fixture@example.invalid", "commit", "-qm", "fixture")
        self.sha = self.git("rev-parse", "HEAD")
        self.root = self.base / "scanner"
        shutil.copytree(self.producer, self.root)
        self.bundles = self.base / "bundles"
        self.php = f'<coverage><project><file name="{self.producer}/src/app.php"><line num="1" count="1"/></file><metrics coveredstatements="1"/></project></coverage>'
        python = f'<coverage><sources><source>{self.producer}</source></sources><packages><package><classes><class filename="tools/build-offline.py"><lines><line number="1" hits="1"/></lines></class></classes></package></packages></coverage>'
        lcov = f'SF:{self.producer}/public/script.js\nDA:1,2\nend_of_record\n'
        for part, names in coverage.REPORTS.items():
            for name in names:
                path = self.producer / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text(lcov if name.endswith(".lcov") else python if name == "var/offline-coverage.xml" else self.php)
            coverage.seal(self.producer, part, self.bundles / part, self.sha)

    def git(self, *args):
        return subprocess.check_output(["git", "-c", "core.hooksPath=/dev/null", "-C", str(self.producer), *args], text=True).strip()

    def replace_report(self, part, name, data):
        path = self.bundles / part / name
        path.write_bytes(data)
        receipt = self.bundles / part / "receipt.json"
        value = json.loads(receipt.read_text())
        value["reports"][name] = hashlib.sha256(data).hexdigest()
        receipt.write_text(json.dumps(value))

    def consume(self):
        coverage.consume(self.root, self.bundles, self.sha)

    def test_complete_reports_remap_between_runner_workspaces(self):
        self.consume()
        for names in coverage.REPORTS.values():
            for name in names:
                text = (self.root / name).read_text()
                self.assertNotIn(str(self.producer), text)
                self.assertIn(str(self.root), text)
        self.assertIn("DA:1,2", (self.root / "var/browser-coverage.lcov").read_text())
        self.assertIn('count="1"', (self.root / "tests/coverage.xml").read_text())

    def test_stale_revision_is_rejected(self):
        receipt = self.bundles / "application/receipt.json"
        value = json.loads(receipt.read_text())
        value["source_sha"] = "f" * 40
        receipt.write_text(json.dumps(value))
        with self.assertRaisesRegex(ValueError, "source and phase"):
            self.consume()
        self.assertFalse((self.root / "tests/coverage.xml").exists())

    def test_wrong_source_tree_is_rejected(self):
        receipt = self.bundles / "legacy/receipt.json"
        value = json.loads(receipt.read_text())
        value["source_tree"] = "f" * 40
        receipt.write_text(json.dumps(value))
        with self.assertRaises(ValueError):
            self.consume()

    def test_report_tampering_is_rejected(self):
        (self.bundles / "legacy/tests/coverage.xml").write_text(self.php + " ")
        with self.assertRaisesRegex(ValueError, "checksum"):
            self.consume()

    def test_missing_phase_or_report_is_rejected(self):
        (self.bundles / "application/var/browser-coverage.lcov").unlink()
        with self.assertRaisesRegex(ValueError, "unexpected files"):
            self.consume()

    def test_unexpected_files_are_rejected(self):
        (self.bundles / "legacy/extra.xml").write_text(self.php)
        with self.assertRaises(ValueError):
            self.consume()

    def test_empty_report_cannot_be_sealed(self):
        (self.producer / "tests/coverage.xml").write_text("")
        with self.assertRaises(ValueError):
            coverage.seal(self.producer, "legacy", self.base / "empty", self.sha)
        self.assertFalse((self.base / "empty").exists())

    def test_linked_report_is_rejected(self):
        path = self.bundles / "legacy/tests/coverage.xml"
        path.unlink()
        path.symlink_to(self.producer / "tests/coverage.xml")
        with self.assertRaises(ValueError):
            self.consume()

    def test_foreign_absolute_source_is_rejected(self):
        self.replace_report("legacy", "tests/coverage.xml", self.php.replace(str(self.producer), "/foreign").encode())
        with self.assertRaisesRegex(ValueError, "outside"):
            self.consume()

    def test_relative_source_escape_is_rejected(self):
        self.replace_report("application", "var/browser-coverage.lcov", b"SF:../../outside.js\nDA:1,1\nend_of_record\n")
        with self.assertRaisesRegex(ValueError, "escapes"):
            self.consume()
        self.assertFalse((self.root / "tests/coverage.xml").exists())

    def test_source_symlink_escape_is_rejected(self):
        path = self.root / "src/app.php"
        path.unlink()
        path.symlink_to(self.base / "outside.php")
        with self.assertRaisesRegex(ValueError, "link outside"):
            self.consume()

    def test_zero_coverage_is_rejected(self):
        self.replace_report("legacy", "tests/coverage.xml", self.php.replace('coveredstatements="1"', 'coveredstatements="0"').encode())
        with self.assertRaisesRegex(ValueError, "nonempty"):
            self.consume()

    def test_xml_entities_are_rejected(self):
        data = b'<!DOCTYPE coverage [<!ENTITY unsafe SYSTEM "file:///etc/passwd">]><coverage>&unsafe;</coverage>'
        self.replace_report("legacy", "tests/coverage.xml", data)
        with self.assertRaises(DefusedXmlException):
            self.consume()

    def test_cobertura_external_dtd_is_not_fetched(self):
        name = "var/offline-coverage.xml"
        original = (self.bundles / "application" / name).read_bytes()
        self.replace_report("application", name, b'<!DOCTYPE coverage SYSTEM "https://invalid.example/coverage.dtd">' + original)
        self.consume()

    def test_relative_and_nested_python_sources_remain_valid(self):
        self.replace_report("legacy", "tests/coverage.xml", self.php.replace(str(self.producer) + "/", "").encode())
        self.replace_report("application", "tests/vendor-sync.lcov", b"SF:public/script.js\nDA:1,1\nend_of_record\n")
        name = "var/offline-coverage.xml"
        data = (self.bundles / "application" / name).read_text().replace(f"<source>{self.producer}</source>", f"<source>{self.producer}/tools</source>").replace('filename="tools/build-offline.py"', 'filename="build-offline.py"')
        self.replace_report("application", name, data.encode())
        self.consume()
        self.assertIn(str(self.root / "tools"), (self.root / name).read_text())

    def test_file_uri_sources_are_remapped(self):
        uri = (self.producer / "public/script.js").as_uri()
        self.replace_report("application", "tests/vendor-sync.lcov", f"SF:{uri}\nDA:1,1\nend_of_record\n".encode())
        self.consume()
        self.assertIn(str(self.root / "public/script.js"), (self.root / "tests/vendor-sync.lcov").read_text())

    def test_invalid_receipt_shape_is_rejected(self):
        (self.bundles / "legacy/receipt.json").write_text("[]")
        with self.assertRaisesRegex(ValueError, "receipt format"):
            self.consume()

    def test_destination_link_cannot_write_outside_checkout(self):
        (self.root / "tests").symlink_to(self.base / "outside")
        with self.assertRaisesRegex(ValueError, "destination"):
            self.consume()
        self.assertFalse((self.base / "outside").exists())


if __name__ == "__main__":
    unittest.main()
