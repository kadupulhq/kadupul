"""Run the aggregate-template HTTP contract against a fresh MariaDB stack."""

from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
sys.path.insert(0, str(Path(__file__).resolve().parent))

from harness import Harness, Session
import aggregate_template_http as scenario_module


def main():
    harness = Harness(SimpleNamespace(project='kadupul-aggregate-review', target='aggregate-template-review'))
    try:
        harness.setup()
        session = Session(harness.base)
        login = session.login('behavior-admin')
        if login['login_form']:
            raise AssertionError('The isolated behavior administrator could not authenticate')

        scenario_module.BASE_URL = harness.base
        scenario_module.sql = lambda statement: harness.sql(statement).strip()
        scenario_module.main(authenticated_session=session, harness=harness)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
