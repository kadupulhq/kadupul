"""Exercise the RRD check list and purge in a dedicated HTTP/MariaDB stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from rrdcheck_scenarios import verify_rrdcheck


def main():
    harness = Harness(SimpleNamespace(project='kadupul-rrdcheck-review', target='rrdcheck-review'))
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)
    try:
        harness.setup()
        session = Session(harness.base)
        session.login('behavior-admin')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        verify_rrdcheck(harness, session, user_id, check)
        print(f'RRD check HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
