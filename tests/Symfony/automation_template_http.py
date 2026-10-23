# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Device automation templates, match order, authorization and legacy routes on isolated MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from automation_template_scenarios import verify_automation_templates


def main():
    harness = Harness(SimpleNamespace(project='kadupul-automation-template-review', target='automation-template-review'))
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
        verify_automation_templates(harness, session, user_id, check)
        print(f'Automation template HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
