"""Palette CRUD, CSV, authorization and transaction handoff on isolated MariaDB."""
from pathlib import Path
from types import SimpleNamespace
import argparse
import csv
import io
import sys
from urllib.parse import urlencode
from urllib.request import Request
from urllib.error import HTTPError

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from device_edit_scenarios import Inputs


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--coverage-output', type=Path)
    parser.add_argument('--project', default='kadupul-palette-review')
    args = parser.parse_args()
    h = Harness(SimpleNamespace(project=args.project, target='palette-review'))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(h, args.coverage_output)
    checks = []
    try:
        h.setup()
        s = Session(h.base)
        if s.login('behavior-admin')['login_form']:
            raise AssertionError('login failed')
        uid = int(h.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        h.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({uid},5)')
        def check(value, message):
            nonlocal checks
            if not value:
                raise AssertionError(message)
            checks.append(message)
            print('PASS ' + message, flush=True)
        from palette_color_scenarios import verify_palette_colors
        verify_palette_colors(h, s, uid, check)
        print(f'{len(checks)} Palette HTTP checks passed.', flush=True)
    finally:
        if h.setup_started:
            h.compose('down', '--volumes', '--remove-orphans', timeout=120)
        h.lock.close()

    if args.coverage_output:
        from coverage_support import publish_coverage
        publish_coverage(args.coverage_output, False, checks)


if __name__ == '__main__':
    main()
