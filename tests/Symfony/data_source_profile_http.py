# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Data source profile forms, archives, actions and legacy routes on isolated MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import argparse
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from data_source_profile_heartbeat_scenarios import verify_data_source_profile_heartbeat
from data_source_profile_scenarios import verify_data_source_profile_deletion, verify_data_source_profiles


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-data-source-profile-review')
    args = parser.parse_args()
    harness = Harness(SimpleNamespace(project=args.project, target='data-source-profile-review'))
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
        verify_data_source_profiles(harness, session, user_id, check)
        verify_data_source_profile_deletion(harness, session, check)
        verify_data_source_profile_heartbeat(harness, session, check)
        print(f'Data source profile HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
