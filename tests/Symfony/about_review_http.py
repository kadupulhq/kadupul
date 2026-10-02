# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Run About and current-account eligibility checks in an isolated stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
import argparse
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from about_scenarios import verify_about


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    args = parser.parse_args()
    suffix = '-database' if args.database_sessions else ''
    harness = Harness(SimpleNamespace(project='kadupul-about-review' + suffix, target='about-review' + suffix))
    checks = []
    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)
    def login():
        session = Session(harness.base)
        session.login('behavior-admin')
        return session
    try:
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        session = login()
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin' LIMIT 1").strip())
        verify_about(harness, session, user_id, check)
        for column, value, restore in [('enabled', '', 'on'), ('locked', 'on', '')]:
            harness.sql(f"UPDATE user_auth SET {column}='{value}' WHERE id={user_id}")
            check(session.request('/app.php/about')['status'] == 401, 'About rejects current ' + column + ' account')
            harness.sql(f"UPDATE user_auth SET {column}='{restore}' WHERE id={user_id}")
            check(session.request('/app.php/about')['status'] == 401, 'About does not resurrect revoked session')
            session = login()
        session.request('/logout.php')
        check(session.request('/app.php/about')['status'] == 401, 'About rejects ended session')
        harness.sql(f"UPDATE user_auth SET must_change_password='on', password_change='on' WHERE id={user_id}")
        session = login()
        check(session.request('/app.php/about')['status'] == 401, 'About rejects mandatory password change session')
        harness.sql(f"UPDATE user_auth SET must_change_password='', password_change='' WHERE id={user_id}")
        session = login()
        harness.sql(f'DELETE FROM user_auth WHERE id={user_id}')
        check(session.request('/app.php/about')['status'] == 401, 'About rejects deleted account')
        print(f'About HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
