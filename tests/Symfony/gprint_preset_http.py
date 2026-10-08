# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""GPRINT preset forms, authorization and legacy routes on isolated MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import argparse
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--coverage-output', type=Path)
    parser.add_argument('--project', default='kadupul-gprint-review')
    args = parser.parse_args()
    harness = Harness(SimpleNamespace(project=args.project, target='gprint-review'))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)

    try:
        harness.setup()
        session = Session(harness.base)
        if session.login('behavior-admin')['login_form']:
            raise AssertionError('login failed')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        from gprint_preset_scenarios import verify_gprint_presets
        verify_gprint_presets(harness, session, user_id, check)
        print(f'{len(checks)} GPRINT HTTP checks passed.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()

    if args.coverage_output:
        from coverage_support import publish_coverage
        publish_coverage(args.coverage_output, False, checks)


if __name__ == '__main__':
    main()
