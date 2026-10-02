# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run genuine protected and unprotected About authentication HTTP scenarios."""
import argparse
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from about_authentication_scenarios import verify_about_authentication
from coverage_support import configure_coverage, publish_coverage


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-about-authentication-review')
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    args = parser.parse_args()
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)

    harness = Harness(SimpleNamespace(project=args.project, target='about-authentication-review'))
    if args.coverage_output:
        configure_coverage(harness, args.coverage_output)
    try:
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        session = Session(harness.base)
        check(not session.login('behavior-admin')['login_form'], 'About native authentication fixture login succeeds')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        verify_about_authentication(harness, user_id, args.database_sessions, check)
        if args.coverage_output:
            publish_coverage(args.coverage_output, args.database_sessions, checks)
        print('About native authentication review HTTP passed', flush=True)
    finally:
        harness.compose('down', '-v', '--remove-orphans')


if __name__ == '__main__':
    main()
