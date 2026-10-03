# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Confirm shipped CDEF refusal catalogues and compiled French translations."""
import gettext
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[2]
MESSAGES = (
    'CDEF deletion could not be confirmed. Reload the selection before retrying.',
    'CDEF duplication could not be confirmed. A partial copy may remain; reload before retrying.',
    'The primary CDEF reference contract could not be installed. Review the schema and installer privileges before retrying.',
    'The installed database version could not be confirmed. Review the installer errors before retrying.',
    'Aggregate items could not be saved. Other graph settings may already have been saved; review them before retrying.',
    'Aggregate template settings could not be confirmed. Review the graph settings before retrying.',
    'Aggregate graph settings could not be confirmed. Other settings may already have been saved; review them before retrying.',
    'Aggregate graph regeneration failed. Template settings may already have been saved; review them before retrying.',
    'Aggregate graph regeneration failed. Other graph settings may already have been saved; review them before retrying.',
    'Aggregate graph regeneration failed. Graph regeneration could not be confirmed; review the settings before retrying.',
    'Aggregate graph creation could not be confirmed. Review the graph settings before retrying.',
    'Color Template synchronization failed. Some aggregates may already have been updated; retry after reviewing the settings.',
)


class CdefReferenceGettextTest(unittest.TestCase):
    def test_dynamic_legacy_legend_labels_keep_historical_translations(self):
        # graph_item_editor_legend_items calls __($label), so static extraction
        # alone must not remove these still-reachable catalogue identities.
        labels = ('Cur:', 'Avg:', 'Min:', 'Max:')
        for path in [ROOT / 'locales/po/cacti.pot', *sorted((ROOT / 'locales/po').glob('*.po'))]:
            for label in labels:
                with self.subTest(catalogue=path.name, label=label):
                    self.assertIn('msgid ' + json.dumps(label), path.read_text())
        with (ROOT / 'locales/LC_MESSAGES/fr-FR.mo').open('rb') as stream:
            translations = gettext.GNUTranslations(stream)
        self.assertEqual('Cur :', translations.gettext('Cur:'))
        self.assertEqual('Moy.:', translations.gettext('Avg:'))

    def test_pot_and_every_merged_catalogue_include_current_refusals(self):
        catalogues = [ROOT / 'locales/po/cacti.pot', *sorted((ROOT / 'locales/po').glob('*.po'))]
        self.assertGreater(len(catalogues), 1)
        for path in catalogues:
            source = path.read_text()
            for message in MESSAGES:
                with self.subTest(catalogue=path.name, message=message):
                    self.assertIn('msgid ' + json.dumps(message), source)

    def test_every_shipped_compiled_catalogue_is_readable(self):
        sources = sorted((ROOT / 'locales/po').glob('*.po'))
        self.assertTrue(sources)
        for path in sources:
            with self.subTest(catalogue=path.name):
                with (ROOT / 'locales/LC_MESSAGES' / (path.stem + '.mo')).open('rb') as stream:
                    gettext.GNUTranslations(stream)

    def test_compiled_french_refusals_translate_without_changing_cdef_identity(self):
        with (ROOT / 'locales/LC_MESSAGES/fr-FR.mo').open('rb') as stream:
            translations = gettext.GNUTranslations(stream)
        for message in MESSAGES:
            with self.subTest(message=message):
                translated = translations.gettext(message)
                self.assertNotEqual(message, translated)
                self.assertTrue(translated)
                if 'CDEF' in message:
                    self.assertIn('CDEF', translated)


if __name__ == '__main__':
    unittest.main()
