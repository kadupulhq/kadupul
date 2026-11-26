#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Unit and regression tests for tests/security/verify_gettext_catalogs.py."""

from __future__ import annotations

from pathlib import Path
import subprocess
import tempfile
import unittest

import verify_gettext_catalogs as vgc


PO_HEADER = (
    'msgid ""\n'
    'msgstr ""\n'
    '"Project-Id-Version: Cacti 1.2.33\\n"\n'
    '"Report-Msgid-Bugs-To: developers@cacti.net\\n"\n'
    '"POT-Creation-Date: 2026-11-24 00:00+0000\\n"\n'
    '"PO-Revision-Date: 2026-11-24 00:00+0000\\n"\n'
    '"Last-Translator: Test <test@example.com>\\n"\n'
    '"Language-Team: French\\n"\n'
    '"Language: fr_FR\\n"\n'
    '"MIME-Version: 1.0\\n"\n'
    '"Content-Type: text/plain; charset=UTF-8\\n"\n'
    '"Content-Transfer-Encoding: 8bit\\n"\n'
    '"Plural-Forms: nplurals=2; plural=(n > 1);\\n"\n'
)

POT_HEADER = (
    '#, fuzzy\n'
    'msgid ""\n'
    'msgstr ""\n'
    '"Project-Id-Version: Cacti 1.2.33\\n"\n'
    '"MIME-Version: 1.0\\n"\n'
    '"Content-Type: text/plain; charset=CHARSET\\n"\n'
    '"Content-Transfer-Encoding: 8bit\\n"\n'
    '"Plural-Forms: nplurals=INTEGER; plural=EXPRESSION;\\n"\n'
)


def make_fixture(root: Path, po_body: str, pot_body: str | None = None, php_code: str | None = None) -> tuple[Path, Path]:
    source = root / "src"
    catalogs = root / "locales"
    (catalogs / "po").mkdir(parents=True)
    (catalogs / "LC_MESSAGES").mkdir(parents=True)
    source.mkdir(parents=True)

    (source / "sample.php").write_text(
        php_code
        or (
            "<?php\n"
            "__('Delete');\n"
            "__('Found %d items');\n"
            "__n('%d host', '%d hosts', $n);\n"
            "__('SI Units (--units=si)');\n"
            "__('Reduce memory to 80% of RAM.');\n"
        ),
        encoding="utf-8",
    )

    if pot_body is None:
        subprocess.run(
            [
                "xgettext",
                "--from-code=UTF-8",
                "--no-wrap",
                "-F",
                *[f"-k{kw}" for kw in vgc.GETTEXT_KEYWORDS],
                "-o",
                str(catalogs / "po/cacti.pot"),
                "sample.php",
            ],
            cwd=source,
            check=True,
            capture_output=True,
        )
    else:
        (catalogs / "po/cacti.pot").write_text(POT_HEADER + "\n" + pot_body, encoding="utf-8")

    po_path = catalogs / "po/fr-FR.po"
    po_path.write_text(PO_HEADER + "\n" + po_body, encoding="utf-8")
    mo_path = catalogs / "LC_MESSAGES/fr-FR.mo"
    subprocess.run(
        ["msgfmt", "--check-format", str(po_path), "-o", str(mo_path)],
        check=True,
        capture_output=True,
    )
    return source, catalogs


VALID_PO_BODY = (
    'msgid "Delete"\n'
    'msgstr "Supprimer"\n\n'
    '#, php-format\n'
    'msgid "Found %d items"\n'
    'msgstr "%d éléments trouvés"\n\n'
    '#, php-format\n'
    'msgid "%d host"\n'
    'msgid_plural "%d hosts"\n'
    'msgstr[0] "%d hôte"\n'
    'msgstr[1] "%d hôtes"\n\n'
    'msgid "SI Units (--units=si)"\n'
    'msgstr "Unités SI (--units=si)"\n\n'
    'msgid "Reduce memory to 80% of RAM."\n'
    'msgstr "Réduisez la mémoire à 80% de la RAM."\n'
)


class EntryDefectsUnitTest(unittest.TestCase):
    def check(self, msgid: str, msgstr: str, flags: set[str] | None = None, plural: str = "", extra: dict | None = None) -> list[str]:
        msg = {"msgid": msgid, "_flags": flags or set()}
        if plural:
            msg["msgid_plural"] = plural
            msg["msgstr[0]"] = msgstr
            if extra:
                msg.update(extra)
        else:
            msg["msgstr"] = msgstr
        return vgc.entry_defects(msg, nplurals=2)

    def test_clean_entries_and_benign_edge_cases_pass(self):
        # Benign prose %, Afrikaans/Xhosa/Hebrew hyphenated CLI flags, script ZWNJ, placeholder <not set>
        self.assertEqual([], self.check("Reduce to 80% of RAM.", "Réduire à 80% de la RAM."))
        self.assertEqual([], self.check("Use --audit and --update.", "Gebruik die --audit- en --update-opsies."))
        self.assertEqual([], self.check("Use --alt-autoscale", "Sebenzisa i---alt-autoscale"))
        self.assertEqual([], self.check("<not set>", "غير محدد"))
        self.assertEqual([], self.check("System Memory", "حافظه\u200cی سیستم"))
        self.assertEqual([], self.check("Mode<blockquote><i>No</i>", "โหมด<blockquote><i>ไม่</i></blockquote>"))
        self.assertEqual([], self.check("Recommended 10%% of memory", "Khuyến nghị 10%% của bộ nhớ", flags={"php-format"}))

    def test_html_entity_and_tag_balance_defects(self):
        defects = self.check("Uptime:", "Temps d&apos;activation & #160;:")
        self.assertTrue(any("splits an HTML entity" in d for d in defects), defects)

        defects = self.check("Mode<blockquote><i>No</i>", "Tryb<blockquote><i><i>Brak</i>")
        self.assertTrue(any("unbalanced HTML tags" in d for d in defects), defects)

    def test_cli_flag_and_option_label_defects(self):
        defects = self.check("SI Units (--units=si)", "Unités SI (--units = si)")
        self.assertTrue(any("drops RRDtool option --units=si" in d for d in defects), defects)

        defects = self.check("Use --alt-autoscale", "--Alt-autoscale kullanın")
        self.assertTrue(any("drops CLI flag --alt-autoscale" in d for d in defects), defects)

    def test_printf_specifier_and_spaced_specifier_defects(self):
        defects = self.check("Found %d items in %s", "Trouvé %s éléments", flags={"php-format"})
        self.assertTrue(any("printf specifiers differ" in d for d in defects), defects)

        defects = self.check("Found %d items", "Trouvé %d (% s)", flags={"php-format"})
        self.assertTrue(any("spaced printf specifier" in d for d in defects), defects)

    def test_invisible_chars_kashida_brand_leak_and_punctuation(self):
        for ch in ("\u200b", "\ufeff", "\u0640", "\u00ad", "\u200e"):
            with self.subTest(char=hex(ord(ch))):
                defects = self.check("Delete", f"Supprimer{ch}")
                self.assertTrue(any("forbidden invisible character" in d for d in defects), defects)

        defects = self.check("Contact your Administrator.", "Contactez votre administrateur Kadupul.")
        self.assertTrue(any("brand name" in d for d in defects), defects)

        defects = self.check("Delete", "Löschen!.")
        self.assertTrue(any("ends with '!.'" in d for d in defects), defects)

    def test_fuzzy_untranslated_and_empty_plural_forms(self):
        defects = self.check("Delete", "Supprimer", flags={"fuzzy"})
        self.assertTrue(any("fuzzy" in d for d in defects), defects)

        defects = self.check("Delete", "")
        self.assertTrue(any("untranslated" in d for d in defects), defects)

        defects = self.check("%d host", "%d hôte", flags={"php-format"}, plural="%d hosts", extra={"msgstr[1]": ""})
        self.assertTrue(any("empty plural form msgstr[1]" in d for d in defects), defects)


class VerifyEndToEndTest(unittest.TestCase):
    def test_valid_fixture_passes(self):
        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY)
            summary = vgc.verify(source, catalogs, expected_locale_count=1)
            self.assertEqual(summary, {"php_sources": 1, "messages": 5, "locales": 1})

    def test_pot_missing_and_stale_entries_fail(self):
        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY)
            pot = catalogs / "po/cacti.pot"
            pot.write_text(pot.read_text(encoding="utf-8") + '\nmsgid "Stale key"\nmsgstr ""\n', encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "cacti.pot drift.*stale=1"):
                vgc.verify(source, catalogs, expected_locale_count=1)

        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY)
            (source / "sample.php").write_text(
                (source / "sample.php").read_text(encoding="utf-8") + "__('Brand new string');\n",
                encoding="utf-8",
            )
            with self.assertRaisesRegex(ValueError, "cacti.pot drift.*missing=1"):
                vgc.verify(source, catalogs, expected_locale_count=1)

    def test_obsolete_po_entry_and_mo_mismatch_fail(self):
        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY + '\n#~ msgid "Old"\n#~ msgstr "Ancien"\n')
            with self.assertRaisesRegex(ValueError, "obsolete=1"):
                vgc.verify(source, catalogs, expected_locale_count=1)

        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY)
            mo = catalogs / "LC_MESSAGES/fr-FR.mo"
            mo.write_bytes(mo.read_bytes() + b"\x00")
            with self.assertRaisesRegex(ValueError, "MO binary differs"):
                vgc.verify(source, catalogs, expected_locale_count=1)

    def test_missing_mo_pair_fails(self):
        with tempfile.TemporaryDirectory() as td:
            source, catalogs = make_fixture(Path(td), VALID_PO_BODY)
            (catalogs / "LC_MESSAGES/fr-FR.mo").unlink()
            with self.assertRaisesRegex(ValueError, "PO/MO locale mismatch"):
                vgc.verify(source, catalogs, expected_locale_count=1)


if __name__ == "__main__":
    unittest.main()
