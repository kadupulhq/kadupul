"""Exercise External Links in a dedicated HTTP/MariaDB stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
import argparse
import json
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session
from link_scenarios import verify_links
from link_collector_scenarios import verify_collector_links
from coverage_support import configure_coverage, publish_coverage


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    args = parser.parse_args()
    suffix = '-database' if args.database_sessions else ''
    harness = Harness(SimpleNamespace(project='kadupul-links-review' + suffix, target='links-review' + suffix))
    checks = []
    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)
    try:
        if args.coverage_output:
            configure_coverage(harness, args.coverage_output)
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        session = Session(harness.base)
        session.login('behavior-admin')
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        verify_links(harness, session, user_id, check)
        cookies = next(handler.cookiejar for handler in session.opener.handlers if hasattr(handler, 'cookiejar'))
        credential = next(cookie.value for cookie in cookies if cookie.name == 'Cacti')
        probe_source = Path(__file__).with_name('link_authorization_probe.php').read_text().removeprefix('<?php')
        probe = harness.php('-r', probe_source, json.dumps({'user': user_id, 'cookie': credential}))
        evidence = json.loads(probe['stdout']) if probe['exit'] == 0 else {}
        if probe['exit'] != 0 or len(evidence) != 36 or not all(evidence.values()):
            raise AssertionError('Navigation authorization concurrency probe failed: ' + repr(probe))
        check(all(value for name, value in evidence.items() if name.endswith('_concurrent')),
              'two actual shared-session Navigation transactions authorize without lock upgrades')
        check(all(value for name, value in evidence.items() if not name.endswith('_concurrent')),
              'direct and enabled-group policy revocation waits for both Navigation commits')
        verify_collector_links(harness, session, user_id, check)
        if args.coverage_output:
            publish_coverage(args.coverage_output, args.database_sessions, checks)
        print(f'Links HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
