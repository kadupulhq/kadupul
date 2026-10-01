# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Run the CDEF HTTP contract against a fresh isolated MariaDB stack.

Run with: mise exec -- python tests/Symfony/cdef_review_http.py
"""
from pathlib import Path
from types import SimpleNamespace
import argparse
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from cdef_http_scenarios import verify_cdefs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-cdef-review')
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    args = parser.parse_args()
    harness = Harness(SimpleNamespace(project=args.project, target=args.project))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    try:
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        checks = []

        def check(condition, message):
            if not condition:
                raise AssertionError(message)
            checks.append(message)
            print('PASS ' + message, flush=True)

        unauthenticated = Session(harness.base)
        response = unauthenticated.request('/app.php/graph-definitions/cdefs?page[]=invalid')
        check(response['status'] == 401,
              'unauthenticated CDEF HTTP request is rejected before malformed query parsing')

        session = Session(harness.base)
        login = session.login('behavior-admin')
        if login['login_form']:
            raise AssertionError('The isolated behavior administrator could not authenticate')

        verify_cdefs(harness, session, check)
        if args.coverage_output:
            from coverage_support import publish_coverage
            publish_coverage(args.coverage_output, args.database_sessions, checks)
        print(f'CDEF HTTP/MariaDB integration passed: {len(checks)} assertions.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
