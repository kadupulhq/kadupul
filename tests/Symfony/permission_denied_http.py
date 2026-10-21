# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Run the permission denied page checks in an isolated stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
import argparse
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness
from permission_denied_scenarios import verify_permission_denied, verify_permission_denied_prefix


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    args = parser.parse_args()
    suffix = '-database' if args.database_sessions else ''
    harness = Harness(SimpleNamespace(project='kadupul-permission-denied' + suffix, target='permission-denied' + suffix))
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)

    try:
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        verify_permission_denied(harness, check)
        verify_permission_denied_prefix(harness, check)
        print(f'Permission denied HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
