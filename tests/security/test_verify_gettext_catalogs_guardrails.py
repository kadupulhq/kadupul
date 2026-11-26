#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Unit tests for deterministic gettext localization guardrails."""

import unittest

from verify_gettext_catalogs import (
    MAX_STATIC_GETTEXT_MESSAGES,
    check_legacy_gettext_ceiling,
    translation_defects,
    validate_manifest,
)


class VerifyGettextCatalogsGuardrailsTest(unittest.TestCase):
    def test_validate_manifest_accepts_valid_metadata(self):
        manifest = {
            "source_fallback": "en-US",
            "translated_locales": ["de-DE", "en-GB", "fa-IR", "fr-FR"],
            "sparse_yaml_overrides": ["en-GB"],
            "scripts": {"fa-IR": ["Arabic"]},
        }
        self.assertEqual(["de-DE", "en-GB", "fa-IR", "fr-FR"], validate_manifest(manifest))

    def test_validate_manifest_rejects_invalid_sparse_and_scripts(self):
        base = {
            "source_fallback": "en-US",
            "translated_locales": ["de-DE", "en-GB"],
        }
        with self.assertRaisesRegex(ValueError, "sparse_yaml_overrides"):
            validate_manifest(base | {"sparse_yaml_overrides": ["fr-CA"]})
        with self.assertRaisesRegex(ValueError, "sparse_yaml_overrides"):
            validate_manifest(base | {"sparse_yaml_overrides": ["en-GB", "en-GB"]})
        with self.assertRaisesRegex(ValueError, "scripts"):
            validate_manifest(base | {"scripts": {"ar-SA": ["Arabic"]}})
        with self.assertRaisesRegex(ValueError, "scripts"):
            validate_manifest(base | {"scripts": {"de-DE": []}})
        with self.assertRaisesRegex(ValueError, "scripts"):
            validate_manifest(base | {"scripts": {"de-DE": ["invalid_script"]}})

    def test_printf_count_type_order_and_prose_percent(self):
        # Valid sequential, positional reordering, escaped %%, and prose %
        ok_cases = [
            {"msgid": "Found %d devices in %s", "msgstr": "%d Geräte in %s gefunden", "flags": "php-format"},
            {"msgid": "Found %d devices in %s", "msgstr": "In %2$s wurden %1$d Geräte gefunden", "flags": "php-format"},
            {"msgid": "CPU usage at %d%% (above 90%)", "msgstr": "CPU-Auslastung bei %d%% (über 90%)", "flags": "php-format"},
            {"msgid": "Usage above 90%", "msgstr": "Utilisation supérieure à 90 %", "flags": ""},
        ]
        for msg in ok_cases:
            with self.subTest(msg=msg["msgstr"]):
                self.assertEqual([], translation_defects(msg, locale="de-DE"))

        bad_cases = [
            ({"msgid": "Found %d devices in %s", "msgstr": "Gefunden: %d Geräte", "flags": "php-format"}, "count mismatch"),
            ({"msgid": "Found %d devices in %s", "msgstr": "In %s wurden %d Geräte gefunden", "flags": "php-format"}, "type/order mismatch"),
            ({"msgid": "Found %d devices in %s", "msgstr": "In %2$d wurden %1$s gefunden", "flags": "php-format"}, "type mismatch"),
            ({"msgid": "Found %d devices in %s", "msgstr": "In %1$d und %s gefunden", "flags": "php-format"}, "mixes positional"),
            ({"msgid": "Found %d devices in %s", "msgstr": "In %3$s und %1$d gefunden", "flags": "php-format"}, "out of range"),
            ({"msgid": "Found %d devices", "msgstr": "%1$d und %1$d", "flags": "php-format"}, "count mismatch"),
        ]
        for msg, expected_err in bad_cases:
            with self.subTest(expected_err=expected_err, msgstr=msg["msgstr"]):
                defects = translation_defects(msg, locale="de-DE")
                self.assertTrue(any(expected_err in d for d in defects), defects)

    def test_html_entities_and_tags_guardrails(self):
        self.assertEqual([], translation_defects({"msgid": "Click <b>Save</b> now", "msgstr": "Klicken Sie auf <b>Speichern</b>"}, locale="de-DE"))
        for bad, pattern in (
            ({"msgid": "A &gt; B", "msgstr": "A &gt ; B"}, "splits an HTML entity"),
            ({"msgid": "A &amp; B", "msgstr": "A &amp ; B"}, "splits an HTML entity"),
            ({"msgid": "Uptime&#160;:", "msgstr": "Uptime& #160;:"}, "splits an HTML entity"),
            ({"msgid": "Click <b>Save</b>", "msgstr": "Click <b Save"}, "unclosed HTML tag"),
            ({"msgid": "Click <i>Save</i>", "msgstr": "Click <i>Save<i>"}, "unbalanced <i>"),
            ({"msgid": "Click <b>Save</b>", "msgstr": "Click <b>Save</b></b>"}, "unbalanced <b>"),
        ):
            with self.subTest(bad=bad["msgstr"]):
                defects = translation_defects(bad, locale="de-DE")
                self.assertTrue(any(pattern in d for d in defects), defects)

    def test_rrdtool_options_and_schema_filename_guardrails(self):
        self.assertEqual([], translation_defects({"msgid": "Right Axis (--right-axis)", "msgstr": "Rechte Achse (--right-axis)"}, locale="de-DE"))
        self.assertEqual([], translation_defects({"msgid": "Dash Offset (dash-offset=offset)", "msgstr": "Strich-Abstand (dash-offset=offset)"}, locale="de-DE"))
        self.assertEqual([], translation_defects({"msgid": "Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])", "msgstr": "Striche (dashes[=on_s[,off_s[,on_s,off_s]...]])"}, locale="de-DE"))
        self.assertEqual([], translation_defects({"msgid": "Import cacti.sql now", "msgstr": "Importer cacti.sql maintenant"}, locale="fr-FR"))

        for bad, pattern in (
            ({"msgid": "Right Axis (--right-axis)", "msgstr": "Rechte Achse (--rechte-achse)"}, "drops the RRDtool option --right-axis"),
            ({"msgid": "Dash Offset (dash-offset=offset)", "msgstr": "Strich-Abstand (dash-offset=Abstand)"}, "alters the RRDtool syntax (dash-offset=offset)"),
            ({"msgid": "Dashes (dashes[=on_s[,off_s[,on_s,off_s]...]])", "msgstr": "Тире (тире [=on_s[,off_s[,on_s,off_s]...]])"}, "alters the RRDtool syntax (dashes["),
            ({"msgid": "Import cacti.sql now", "msgstr": "Importar Kadupul.sql ahora"}, "alters the schema filename cacti.sql"),
        ):
            with self.subTest(bad=bad["msgstr"]):
                defects = translation_defects(bad, locale="de-DE")
                self.assertTrue(any(pattern in d for d in defects), defects)

    def test_brand_guardrail(self):
        # Allowed when source mentions Cacti (case-insensitive)
        self.assertEqual([], translation_defects({"msgid": "Mode which cacti uses", "msgstr": "Modus, den Cacti verwendet"}, locale="de-DE"))
        # Rejected when source says Kadupul or mentions no brand
        for src in ("Kadupul waits for SNMP", "System waits for SNMP"):
            defects = translation_defects({"msgid": src, "msgstr": "Cacti wartet auf SNMP"}, locale="de-DE")
            self.assertTrue(any("unexpected 'Cacti' brand" in d for d in defects), defects)

    def test_invisible_kashida_and_allowed_script_joiners(self):
        # ZWNJ (U+200C), ZWJ (U+200D), RLM (U+200F) are explicitly allowed
        self.assertEqual([], translation_defects({"msgid": "Devices", "msgstr": "دستگاه\u200Cها"}, locale="fa-IR"))
        self.assertEqual([], translation_defects({"msgid": "Devices", "msgstr": "उपकरण\u200Dसमूह"}, locale="hi-IN"))
        self.assertEqual([], translation_defects({"msgid": "Devices", "msgstr": "\u200Fالأجهزة"}, locale="ar-SA"))
        # Forbidden chars: U+200B, U+FEFF, U+0640, U+00AD, U+200E
        for code in ("\u200B", "\uFEFF", "\u0640", "\u00AD", "\u200E"):
            with self.subTest(char=f"U+{ord(code):04X}"):
                defects = translation_defects({"msgid": "Edit Page", "msgstr": f"تعديل{code}الصفحة"}, locale="ar-SA")
                self.assertTrue(any(f"U+{ord(code):04X}" in d for d in defects), defects)
                # Historical compatibility entries are frozen and skipped for invisible/kashida
                self.assertEqual([], translation_defects({"msgid": "Edit Page", "msgstr": f"تعديل{code}الصفحة"}, locale="ar-SA", is_historical=True))

    def test_double_punctuation_empty_plurals_and_script_aware_end_stop(self):
        # Double punctuation
        for bad in ("Continue?.", "Stop!.", "继续？。", "停止！。"):
            defects = translation_defects({"msgid": "Continue?", "msgstr": bad}, locale="de-DE")
            self.assertTrue(any("ends with" in d for d in defects), defects)
        # Empty plural forms
        plural_bad = {"msgid": "%d day", "msgid_plural": "%d days", "msgstr[0]": "%d Tag", "msgstr[1]": "   "}
        self.assertTrue(any("empty plural" in d for d in translation_defects(plural_bad, locale="de-DE")))
        plural_ok = {"msgid": "%d day", "msgid_plural": "%d days", "msgstr[0]": "%d Tag", "msgstr[1]": "%d Tage"}
        self.assertEqual([], translation_defects(plural_ok, locale="de-DE"))
        # Script-aware end_stop
        sentence = "Select a valid device before saving changes."
        self.assertTrue(any("drops sentence-ending punctuation" in d for d in translation_defects({"msgid": sentence, "msgstr": "Wählen Sie ein gültiges Gerät aus"}, locale="de-DE")))
        self.assertEqual([], translation_defects({"msgid": sentence, "msgstr": "Wählen Sie ein gültiges Gerät aus."}, locale="de-DE"))
        # Thai exempt from trailing period
        self.assertEqual([], translation_defects({"msgid": sentence, "msgstr": "เลือกอุปกรณ์ที่ถูกต้องก่อนบันทึกการเปลี่ยนแปลง"}, locale="th-TH"))
        # Historical compatibility entries exempt from end_stop
        self.assertEqual([], translation_defects({"msgid": sentence, "msgstr": "Wählen Sie ein gültiges Gerät aus"}, locale="de-DE", is_historical=True))

    def test_legacy_gettext_ceiling(self):
        check_legacy_gettext_ceiling(MAX_STATIC_GETTEXT_MESSAGES)
        check_legacy_gettext_ceiling(MAX_STATIC_GETTEXT_MESSAGES - 1)
        with self.assertRaisesRegex(ValueError, "Symfony ICU YAML"):
            check_legacy_gettext_ceiling(MAX_STATIC_GETTEXT_MESSAGES + 1)


if __name__ == "__main__":
    unittest.main()
