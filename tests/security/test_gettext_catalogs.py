#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Exercise exact native catalog admission and reviewed compatibility boundaries."""

import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

from verify_gettext_catalogs import compatibility, entries, RETIRED_MESSAGES, verify

ROOT = Path(__file__).resolve().parents[2]


class GettextCatalogIntegrityTest(unittest.TestCase):
    def setUp(self):
        self.owned = tempfile.TemporaryDirectory(prefix="kadupul-gettext-regression-")
        self.addCleanup(self.owned.cleanup)
        self.directory = Path(self.owned.name)
        self.catalogs = self.directory / "locales"
        shutil.copytree(ROOT / "locales/po", self.catalogs / "po")
        shutil.copytree(ROOT / "locales/LC_MESSAGES", self.catalogs / "LC_MESSAGES")

    def mutate(self, path, key, replacement):
        blocks = re.split(r"\n\s*\n", path.read_text())
        matched = 0
        for index, block in enumerate(blocks):
            if key in entries(block):
                matched += 1
                blocks[index] = replacement(block)
        self.assertEqual(1, matched, "Mutation must reach the actual selected catalog identity")
        path.write_text("\n\n".join(blocks))

    def test_generated_catalogs_are_admitted(self):
        verify(ROOT, self.catalogs)

    def test_missing_static_identity_is_rejected(self):
        self.mutate(self.catalogs / "po/cacti.pot", ("", "Delete", ""), lambda block: "")
        with self.assertRaisesRegex(ValueError, "POT differs"):
            verify(ROOT, self.catalogs)

    def test_each_legacy_legend_omission_is_rejected(self):
        pot = self.catalogs / "po/cacti.pot"
        original = pot.read_text()
        for label in ("Cur:", "Avg:", "Min:", "Max:"):
            with self.subTest(label=label):
                pot.write_text(original)
                self.mutate(pot, ("", label, ""), lambda block: "")
                with self.assertRaisesRegex(ValueError, "POT differs"):
                    verify(ROOT, self.catalogs)

    def test_nonlegend_compatibility_omission_is_rejected(self):
        key = next(key for key in compatibility(ROOT / "locales/historical-compatibility.json") if key[1] not in ("Cur:", "Avg:", "Min:", "Max:"))
        self.mutate(self.catalogs / "po/cacti.pot", key, lambda block: "")
        with self.assertRaisesRegex(ValueError, "POT differs"):
            verify(ROOT, self.catalogs)

    def test_undeclared_extra_identity_is_rejected(self):
        pot = self.catalogs / "po/cacti.pot"
        pot.write_text(pot.read_text() + '\n\nmsgid "Undeclared compatibility regression identity"\nmsgstr ""\n')
        with self.assertRaisesRegex(ValueError, "POT differs"):
            verify(ROOT, self.catalogs)

    def test_every_retired_ldap_identity_is_rejected(self):
        pot = self.catalogs / "po/cacti.pot"
        original = pot.read_text()
        for message in RETIRED_MESSAGES:
            with self.subTest(message=message):
                pot.write_text(original + "\n\nmsgid " + json.dumps(message) + '\nmsgstr ""\n')
                with self.assertRaisesRegex(ValueError, "Retired LDAP"):
                    verify(ROOT, self.catalogs)

    def test_changed_compatibility_identity_is_rejected(self):
        data = json.loads((ROOT / "locales/historical-compatibility.json").read_text())
        data["identities"][0]["identity"][1] += " altered"
        path = self.directory / "changed-manifest.json"
        path.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, "differ from review"):
            compatibility(path)

    def test_changed_compatibility_provenance_is_rejected(self):
        data = json.loads((ROOT / "locales/historical-compatibility.json").read_text())
        data["source_pot_sha256"] = "0" * 64
        path = self.directory / "changed-manifest.json"
        path.write_text(json.dumps(data))
        with self.assertRaisesRegex(ValueError, "provenance"):
            compatibility(path)

    def test_empty_reviewed_translation_even_after_recompile_is_rejected(self):
        po = self.catalogs / "po/fr-FR.po"
        self.mutate(po, ("", "Cur:", ""), lambda block: block.replace('msgstr "Cur :"', 'msgstr ""'))
        self.assertEqual("", entries(po.read_text())[("", "Cur:", "")]["msgstr"])
        subprocess.run(["msgfmt", "--check-format", str(po), "-o", str(self.catalogs / "LC_MESSAGES/fr-FR.mo")], check=True, timeout=30)
        with self.assertRaisesRegex(ValueError, "historical translation differs"):
            verify(ROOT, self.catalogs)

    def test_mismatched_compiled_catalog_is_rejected(self):
        path = self.catalogs / "LC_MESSAGES/fr-FR.mo"
        path.write_bytes(path.read_bytes() + b"changed")
        with self.assertRaisesRegex(ValueError, "MO differs"):
            verify(ROOT, self.catalogs)

    def test_reviewed_translation_cannot_be_hidden_by_fuzzy_flag(self):
        po = self.catalogs / "po/fr-FR.po"
        self.mutate(po, ("", "Cur:", ""), lambda block: "#, fuzzy\n" + block)
        subprocess.run(["msgfmt", "--check-format", str(po), "-o", str(self.catalogs / "LC_MESSAGES/fr-FR.mo")], check=True, timeout=30)
        with self.assertRaisesRegex(ValueError, "translation flags differ"):
            verify(ROOT, self.catalogs)

    def test_missing_supported_pair_is_rejected(self):
        (self.catalogs / "po/fr-FR.po").unlink()
        (self.catalogs / "LC_MESSAGES/fr-FR.mo").unlink()
        with self.assertRaisesRegex(ValueError, "declared shipped locales"):
            verify(ROOT, self.catalogs)

    def test_static_location_drift_is_rejected(self):
        self.mutate(self.catalogs / "po/cacti.pot", ("", "Delete", ""), lambda block: re.sub(r"^#:.*$", "#: missing.php:1", block, flags=re.MULTILINE))
        with self.assertRaisesRegex(ValueError, "source locations"):
            verify(ROOT, self.catalogs)

    def test_build_entry_reports_failed_compatibility_as_incomplete(self):
        build = self.directory / "owned-build"
        (build / "include").mkdir(parents=True)
        (build / "src").mkdir()
        (build / "tests/security").mkdir(parents=True)
        shutil.copytree(ROOT / "locales", build / "locales")
        shutil.copy2(ROOT / "tests/security/verify_gettext_catalogs.py", build / "tests/security/verify_gettext_catalogs.py")
        (build / "include/cacti_version").write_text("1.2.34")
        (build / "fixture.php").write_text("<?php __('Delete');\n")
        evidence = build / "locales/historical-compatibility.json"
        data = json.loads(evidence.read_text())
        data["source_commit"] = "0" * 40
        evidence.write_text(json.dumps(data))
        result = subprocess.run(["sh", "locales/build_gettext.sh"], cwd=build, capture_output=True, text=True, timeout=120)
        self.assertNotEqual(0, result.returncode)
        self.assertIn("compatibility merge failed", result.stderr)
        self.assertIn("artifacts remain incomplete", result.stderr)
        self.assertIn("provenance differs", result.stderr)
        self.assertTrue((build / "locales/po/cacti.pot").is_file())
        self.assertTrue((build / "locales/LC_MESSAGES/fr-FR.mo").is_file())
        self.assertEqual({("", "Delete", "")}, set(entries((build / "locales/po/cacti.pot").read_text())))


if __name__ == "__main__":
    unittest.main()
