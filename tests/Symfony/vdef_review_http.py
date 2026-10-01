"""Measure VDEF forms, ordering, duplicate/delete and rollback through HTTP."""
from pathlib import Path
from types import SimpleNamespace
import argparse
import sys
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from coverage_support import configure_coverage, publish_coverage
from vdef_scenarios import verify_vdefs

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-vdef-review')
    parser.add_argument('--coverage-output', type=Path)
    args = parser.parse_args()
    h = Harness(SimpleNamespace(project=args.project, target='vdef-review'))
    checks = []
    try:
        if args.coverage_output:
            configure_coverage(h, args.coverage_output)
        h.setup()
        session = Session(h.base)
        session.login('behavior-admin')
        user_id = int(h.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        def check(condition, message):
            if not condition:
                raise AssertionError(message)
            checks.append(message)
            print('PASS ' + message, flush=True)
        verify_vdefs(h, session, user_id, check)
        if args.coverage_output:
            publish_coverage(args.coverage_output, False, checks)
        print(f'VDEF HTTP/MariaDB checks passed: {len(checks)}', flush=True)
    finally:
        if h.setup_started:
            h.compose('down', '--volumes', '--remove-orphans', timeout=120)
        h.lock.close()

if __name__ == '__main__':
    main()
