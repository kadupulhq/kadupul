# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Keep the HTTP fixture's successful controls faithful to rendered forms."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Symfony'))
from cdef_legacy_page_scenarios import REQUIRED_CHECKS, RenderedForm


class RenderedFormTest(unittest.TestCase):
    def parse(self, source):
        parser = RenderedForm()
        parser.feed(source)
        return parser.forms

    def test_checkbox_defaults_and_unsuccessful_controls(self):
        forms = self.parse('<form><input name="explicit" type="checkbox" checked value="2">'
                           '<input name="default" type="checkbox" checked>'
                           '<input name="radio" type="radio" checked>'
                           '<input name="unchecked" type="checkbox">'
                           '<input name="disabled" disabled value="x">'
                           '<input name="submit" type="submit" value="x"></form>')
        self.assertEqual([{'explicit': '2', 'default': 'on', 'radio': 'on'}], forms)

    def test_distinct_forms_do_not_overwrite_each_other(self):
        self.assertEqual([{'id': '1'}, {'id': '2'}], self.parse(
            '<form><input name="id" value="1"></form><form><input name="id" value="2"></form>'))

    def test_select_ignores_disabled_options_and_groups(self):
        self.assertEqual([{'choice': '2'}], self.parse(
            '<form><select name="choice"><option value="0" disabled selected>Zero</option>'
            '<optgroup disabled><option value="1" selected>One</option></optgroup>'
            '<option value="2">Two</option><option value="3">Three</option></select></form>'))

    def test_textarea_retains_decoded_unicode(self):
        self.assertEqual([{'text': 'é & two\nlines'}], self.parse(
            '<form><textarea name="text">é &amp; two\nlines</textarea></form>'))

    def test_repeated_controls_fail_instead_of_dropping_a_value(self):
        with self.assertRaisesRegex(RuntimeError, 'Repeated successful'):
            self.parse('<form><input name="id" value="1"><input name="id" value="2"></form>')

    def test_unsupported_select_shapes_fail_closed(self):
        for source in ('<form><select name="ids" multiple>',
                       '<form><select name="ids"><option>Implicit</option>'):
            with self.subTest(source=source), self.assertRaises(RuntimeError):
                self.parse(source)

    def test_disabled_list_selection_is_observed_without_submitting_it(self):
        parser = RenderedForm()
        parser.feed('<form><input type="checkbox" name="chk_1" disabled>'
                    '<input type="checkbox" name="chk_2"></form>')
        self.assertEqual([('chk_1', True), ('chk_2', False)], parser.checkboxes)
        self.assertEqual([{}], parser.forms)

    def test_every_behavior_marker_is_required_by_the_actual_consumer(self):
        consumer = (Path(__file__).resolve().parents[1] / 'Symfony/merge_coverage.php').read_text()
        self.assertEqual(len(REQUIRED_CHECKS), len(set(REQUIRED_CHECKS)))
        for marker in REQUIRED_CHECKS:
            with self.subTest(marker=marker):
                self.assertIn(repr(marker) + ',', consumer)
        self.assertIn("$sourcePaths[] = 'tests/Symfony/cdef_legacy_page_scenarios.py';", consumer)
        self.assertIn('$requiredPaths = array_merge($requiredPaths, $legacyCallerPaths);', consumer)


if __name__ == '__main__':
    unittest.main()
