# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Synthetic report fixtures test validation, never supply human acceptance evidence."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / 'tools/installer_usability.py'
SPEC = importlib.util.spec_from_file_location('installer_usability', SCRIPT)
usability = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(usability)


class InstallerUsabilityTest(unittest.TestCase):
    def complete_report(self):
        report = usability.template('1' * 40)
        report['environment'] = {'php': '8.4.25', 'browser': 'chromium'}
        report['sessions'] = [
            {'id': f'{number:032x}', 'consent': True, 'first_time': True,
             'human': True, 'locale': locale, 'input_method': method,
             'tasks': {task: {'outcome': 'unassisted', 'issues': []}
                       for task in usability.TASKS}}
            for number, locale, method in [(1, 'en-US', 'pointer'), (2, 'fr-FR', 'keyboard')]]
        return report

    def test_template_is_pending_and_cannot_accept_a_release(self):
        result = usability.assess(usability.template('1' * 40))
        self.assertEqual('needs_sessions', result['status'])
        self.assertFalse(result['release_accepted'])

    def test_complete_declarations_still_require_independent_review(self):
        result = usability.assess(self.complete_report())
        self.assertEqual('awaiting_independent_review', result['status'])
        self.assertFalse(result['release_accepted'])
        self.assertEqual('declared_by_operator_not_independently_verified', result['participation'])
        self.assertNotIn('id', result)

    def test_blocking_findings_cannot_be_hidden_by_other_successes(self):
        for issue in usability.BLOCKERS:
            report = self.complete_report()
            report['sessions'][0]['tasks']['recover']['issues'] = [issue]
            self.assertEqual('blocked', usability.assess(report)['status'])

    def test_missing_tasks_keyboard_translation_and_assistance_are_not_passing(self):
        mutations = [
            (lambda report: report['sessions'][1].update(input_method='pointer'), 'needs_sessions'),
            (lambda report: report['sessions'][1].update(locale='en'), 'needs_sessions'),
            (lambda report: report['sessions'][0]['tasks']['review'].update(outcome='not_run'), 'needs_sessions'),
            (lambda report: report['sessions'][0]['tasks']['configure'].update(outcome='assisted'), 'needs_review'),
        ]
        for mutate, expected in mutations:
            report = self.complete_report()
            mutate(report)
            self.assertEqual(expected, usability.assess(report)['status'])
        report = self.complete_report()
        del report['sessions'][0]['tasks']['support']
        with self.assertRaises(ValueError):
            usability.assess(report)

    def test_synthetic_unconsented_or_returning_participants_are_rejected(self):
        for flag in ['consent', 'human', 'first_time']:
            report = self.complete_report()
            report['sessions'][0][flag] = False
            with self.assertRaises(ValueError):
                usability.assess(report)

    def test_duplicate_sessions_and_free_text_are_rejected(self):
        report = self.complete_report()
        report['sessions'][1]['id'] = report['sessions'][0]['id']
        with self.assertRaises(ValueError):
            usability.assess(report)
        for mutate in [
            lambda report: report['sessions'][0].update(name='private-person'),
            lambda report: report['sessions'][0]['tasks']['configure'].update(issues=['private-password']),
            lambda report: report['sessions'][0]['tasks']['configure'].update(notes='private-host'),
        ]:
            report = self.complete_report()
            mutate(report)
            with self.assertRaises(ValueError):
                usability.assess(report)

    def test_duplicate_json_fields_are_rejected(self):
        with self.assertRaises(ValueError):
            json.loads('{"sessions":[],"sessions":[]}', object_pairs_hook=usability.unique_object)

    def test_pending_reports_reject_free_text_environment_values(self):
        for key in ['php', 'browser']:
            report = usability.template('1' * 40)
            report['environment'][key] = 'private-value'
            with self.assertRaises(ValueError):
                usability.assess(report)

    def test_actual_cli_returns_bounded_generic_errors_and_never_echoes_private_input(self):
        with tempfile.TemporaryDirectory(prefix='installer-usability-') as directory:
            report = Path(directory) / 'report.json'
            for contents in ['{"private-password":', ' ' * 65537]:
                report.write_text(contents)
                result = subprocess.run([sys.executable, str(SCRIPT), '--report', str(report)],
                                        capture_output=True, text=True, timeout=10)
                self.assertEqual(2, result.returncode)
                self.assertEqual({'error': 'invalid_usability_report'}, json.loads(result.stdout))
                self.assertEqual('', result.stderr)
            report.write_text(json.dumps(self.complete_report()))
            before = report.read_bytes()
            result = subprocess.run([sys.executable, str(SCRIPT), '--report', str(report)],
                                    capture_output=True, text=True, timeout=10)
            self.assertEqual(0, result.returncode)
            self.assertFalse(json.loads(result.stdout)['release_accepted'])
            self.assertEqual(before, report.read_bytes())


if __name__ == '__main__':
    unittest.main()
