# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Run the GPRINT HTTP scenarios in a dedicated isolated Docker project."""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session


def main():
    harness = Harness(SimpleNamespace(project='kadupul-gprint-review', target='gprint-review'))
    try:
        harness.setup()
        session = Session(harness.base)
        result = session.login('behavior-admin')
        if result['login_form']:
            raise RuntimeError('Existing behavior-admin login failed')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        from gprint_preset_scenarios import verify_gprint_presets

        verify_gprint_presets(harness, session, user_id, lambda passed, message: print(
            ('PASS ' if passed else 'FAIL ') + message, flush=True) if passed else (_ for _ in ()).throw(AssertionError(message)))
        print('Dedicated GPRINT HTTP checks passed.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
