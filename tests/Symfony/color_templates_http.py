"""Run the color-template feature against an isolated HTTP and MariaDB stack."""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session


def main():
    harness = Harness(SimpleNamespace(project='kadupul-color-review', target='color-templates'))
    try:
        harness.setup()
        session = Session(harness.base)
        result = session.login('behavior-admin')
        if result['login_form'] or not result['admin_layout']:
            raise RuntimeError('Admin authentication did not succeed')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        from color_templates_scenarios import verify_color_templates
        verify_color_templates(harness, session, user_id,
                               lambda condition, message: (_ for _ in ()).throw(AssertionError(message)) if not condition else print('PASS ' + message, flush=True))
        print('Color template HTTP integration passed.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
