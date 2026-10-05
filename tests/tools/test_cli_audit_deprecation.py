# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Check audit parity probes keep quiet and default warning contracts separate."""
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Symfony'))
import cli_audit_scenarios as audit


class AuditDeprecationHarness:
    def __init__(self, quiet=None, explained=None):
        self.quiet = quiet or {'exit': 0, 'stdout': 'usage: audit_database.php\n', 'stderr': ''}
        self.explained = explained or {**self.quiet, 'stderr': audit.UPGRADE_DEPRECATION}
        self.commands = []

    def command(self, *arguments):
        self.commands.append(arguments)
        return self.explained if 'KADUPUL_CLI_QUIET_DEPRECATION=0 php' in arguments[-1] else self.quiet


def check(condition, message):
    if not condition:
        raise AssertionError(message)


class AuditDeprecationProbeTest(unittest.TestCase):
    def test_actual_parity_runner_selects_both_warning_modes(self):
        harness = AuditDeprecationHarness()
        audit.verify_upgrade_deprecation(harness, check)
        self.assertEqual(harness.commands, [
            ('sh', '-c', 'cd /var/www/html && KADUPUL_CLI_QUIET_DEPRECATION=1 php tests/Fixtures/native-cli/audit_database.php --upgrade'),
            ('sh', '-c', 'cd /var/www/html && KADUPUL_CLI_QUIET_DEPRECATION=0 php tests/Fixtures/native-cli/audit_database.php --upgrade'),
        ])

    def test_missing_or_repeated_default_warning_is_a_failure(self):
        for warning in ('', audit.UPGRADE_DEPRECATION * 2):
            with self.subTest(warning=warning):
                harness = AuditDeprecationHarness(explained={'exit': 0, 'stdout': 'usage: audit_database.php\n', 'stderr': warning})
                with self.assertRaisesRegex(AssertionError, 'exactly once'):
                    audit.verify_upgrade_deprecation(harness, check)

    def test_quiet_warning_or_changed_command_outcome_is_a_failure(self):
        for quiet in ({'exit': 0, 'stdout': 'usage: audit_database.php\n', 'stderr': audit.UPGRADE_DEPRECATION},
                      {'exit': 1, 'stdout': 'usage: audit_database.php\n', 'stderr': ''},
                      {'exit': 0, 'stdout': 'different help\n', 'stderr': ''}):
            with self.subTest(quiet=quiet):
                harness = AuditDeprecationHarness(quiet=quiet, explained={'exit': 0, 'stdout': 'usage: audit_database.php\n', 'stderr': audit.UPGRADE_DEPRECATION})
                with self.assertRaises(AssertionError):
                    audit.verify_upgrade_deprecation(harness, check)


if __name__ == '__main__':
    unittest.main()
