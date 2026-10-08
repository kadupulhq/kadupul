# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""CDEF forms, authorization, references and legacy routes on isolated MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from cdef_scenarios import verify_cdefs


def main():
    harness = Harness(SimpleNamespace(project='kadupul-cdef-review', target='cdef-review'))
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
        verify_cdefs(harness, session, user_id, check)
        print(f'CDEF HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
