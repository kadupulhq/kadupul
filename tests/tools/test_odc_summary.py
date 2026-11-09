# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Check that tools/odc_summary.py reads the bug form's ODC fields and counts them."""
import contextlib
import io
from pathlib import Path
import sys
import tempfile
import re
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import odc_summary  # noqa: E402


def form_blocks():
    """The form's input blocks as (id, label, options, required); CI's Python has no YAML parser."""
    text = (ROOT / '.github/ISSUE_TEMPLATE/bug_report.yml').read_text()
    blocks = []
    for chunk in re.split(r'^  - type: ', text.split('\nbody:\n', 1)[1], flags=re.MULTILINE)[1:]:
        kind = chunk.split('\n', 1)[0].strip()
        if kind == 'markdown':
            continue
        block_id = re.search(r'^    id: (\S+)$', chunk, re.MULTILINE)[1]
        label = re.search(r'^      label: (.+)$', chunk, re.MULTILINE)[1]
        inline = re.search(r'^      options: \[(.*)\]$', chunk, re.MULTILINE)
        if inline:
            options = [option.strip() for option in inline[1].split(',')]
        else:
            options = re.findall(r'^        - (?!label:)(.+)$', chunk, re.MULTILINE)
        blocks.append((block_id, label, options, 'required: true' in chunk))
    return blocks


def form_body(answers):
    """Render an issue body the way GitHub writes an issue form submission."""
    return '\n\n'.join(f'### {label}\n\n{answers.get(block_id, "_No response_")}' for block_id, label, _, _ in form_blocks())


class OdcSummaryTest(unittest.TestCase):
    def test_every_form_option_reads_back_as_its_short_name(self):
        fields = {block_id: (label, options, required) for block_id, label, options, required in form_blocks()
                  if block_id.startswith('odc-')}
        self.assertEqual(set(fields), {'odc-type', 'odc-qualifier', 'odc-trigger', 'odc-origin'})
        labels = dict(odc_summary.FIELDS)
        for block_id, (label, options, required) in fields.items():
            key = block_id.removeprefix('odc-')
            self.assertEqual(label, labels[key])
            self.assertFalse(required, block_id)            # a reporter may not know the classification
            self.assertGreaterEqual(len(options), 3)
            self.assertEqual(odc_summary.OPTIONS[key], tuple(option.split(' (')[0] for option in options))
            for option in options:
                expected = option.split(' (')[0]
                if expected == odc_summary.UNKNOWN.get(key):
                    expected = odc_summary.UNCLASSIFIED
                self.assertEqual(odc_summary.classify(form_body({block_id: option}))[key], expected)

    def test_counts_classified_and_unclassified_issues(self):
        issues = [
            {'number': 1348, 'state': 'OPEN', 'body': form_body({
                'odc-type': 'Timing or serialization (ordering, concurrency, locking, shared state)',
                'odc-qualifier': 'Incorrect', 'odc-trigger': 'Recovery or error handling (an exit, retry or failed dependency)',
                'odc-origin': 'Ported from upstream Cacti'})},
            {'number': 1349, 'state': 'OPEN', 'body': form_body({
                'odc-type': 'Checking (validation of data or conditions before use)', 'odc-qualifier': 'Incorrect',
                'odc-trigger': 'Software configuration (settings, options, an unusual but valid value)',
                'odc-origin': 'Ported from upstream Cacti'})},
            {'number': 7, 'state': 'CLOSED', 'body': 'Filed by hand.\n\n### Defect type\n\nChecking\n\n### Trigger\n\nRare situation'},
            {'number': 8, 'state': 'CLOSED', 'body': form_body({})},
            {'number': 9, 'state': 'CLOSED', 'body': None},
        ]
        report = odc_summary.summarize(issues)
        self.assertIn('5 bug issues, 3 with a defect type.', report)
        self.assertIn('| Checking | 2 | 40.0% |', report)
        self.assertIn('| Timing or serialization | 1 | 20.0% |', report)
        self.assertIn('| Ported from upstream Cacti | 2 | 40.0% |', report)
        self.assertIn('| unclassified | 2 | 40.0% |', report)
        self.assertIn('| Checking | Rare situation | 1 |', report)
        # Unclassified rows sort last in every table.
        type_table = report.split('### Defect type\n')[1].split('###')[0]
        self.assertTrue(type_table.strip().endswith('| unclassified | 2 | 40.0% |'))

    def test_a_value_outside_the_dropdown_counts_as_other(self):
        fields = odc_summary.classify('### Defect type\r\n\r\nChecking | other\r\n\r\n### Trigger\r\n\r\nRare\r\n')
        self.assertEqual(fields['type'], odc_summary.OTHER)
        self.assertEqual(fields['trigger'], odc_summary.OTHER)
        self.assertIn('| other | 1 | 100.0% |', odc_summary.summarize([{'number': 1, 'body': '### Defect type\n\nChecking | other'}]))

    def test_a_later_or_fenced_heading_cannot_override_the_form(self):
        injected = 'Injected <img src=x onerror=alert(1)> [x](http://evil)'
        body = form_body({
            'odc-type': 'Checking (validation of data or conditions before use)',
            'logs': f'```text\n### Defect type\n\n{injected}\n### Origin\n\nSymfony migration\n```',
        }) + f'\n\n### Defect type\n\n{injected}\n\n### Qualifier\n\nMissing'
        fields = odc_summary.classify(body)
        self.assertEqual(fields['type'], 'Checking')
        self.assertEqual(fields['origin'], odc_summary.UNCLASSIFIED)
        report = odc_summary.summarize([{'number': 1, 'body': body}])
        self.assertNotIn('Injected', report)
        self.assertNotIn('<img', report)
        # A fenced heading is not a section even when the form's own section is absent.
        self.assertEqual(odc_summary.classify('~~~~\n### Defect type\n\nInterface\n~~~\n~~~~\n')['type'], odc_summary.UNCLASSIFIED)
        # An empty first section is still the answer; a repeat cannot fill it.
        self.assertEqual(odc_summary.classify('### Defect type\n\n### Defect type\n\nInterface')['type'], odc_summary.UNCLASSIFIED)

    def test_not_sure_origin_is_unclassified(self):
        self.assertEqual(odc_summary.classify('### Origin\n\nNot sure')['origin'], odc_summary.UNCLASSIFIED)

    def test_a_bad_export_is_an_error(self):
        with tempfile.TemporaryDirectory() as directory:
            for content, message in (('[]', 'no issues'), ('{"number": 1}', 'JSON list'), ('[{"title": "x"}]', 'needs a number'),
                                     ('not json', 'Expecting value')):
                path = Path(directory, 'bugs.json')
                path.write_text(content)
                err = io.StringIO()
                with contextlib.redirect_stderr(err), contextlib.redirect_stdout(io.StringIO()):
                    status = odc_summary.main([str(path)])
                self.assertEqual(status, 2, content)
                self.assertIn(message, err.getvalue())


if __name__ == '__main__':
    unittest.main()
