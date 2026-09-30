"""Run the CDEF HTTP contract against a fresh isolated MariaDB stack.

Run with: mise exec -- python tests/Symfony/cdef_review_http.py
"""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from cdef_http_scenarios import verify_cdefs


def main():
    harness = Harness(SimpleNamespace(project='kadupul-cdef-review', target='cdef-review'))
    try:
        harness.setup()
        session = Session(harness.base)
        login = session.login('behavior-admin')
        if login['login_form']:
            raise AssertionError('The isolated behavior administrator could not authenticate')
        checks = []

        def check(condition, message):
            if not condition:
                raise AssertionError(message)
            checks.append(message)
            print('PASS ' + message, flush=True)

        verify_cdefs(harness, session, check)
        print(f'CDEF HTTP/MariaDB integration passed: {len(checks)} assertions.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
