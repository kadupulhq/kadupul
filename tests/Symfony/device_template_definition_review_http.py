# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run device template management against an isolated real database and HTTP stack."""
from pathlib import Path
from types import SimpleNamespace
import sys
import argparse
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
sys.path.insert(0, str(Path(__file__).resolve().parent))
from harness import Harness, Session
from device_template_definition_scenarios import verify_device_template_definitions


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--coverage-output', type=Path)
    parser.add_argument('--project', default='kadupul-device-template-definition-review')
    args = parser.parse_args()
    harness = Harness(SimpleNamespace(project=args.project, target='device-template-definition-review'))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    checks = []
    def check(condition, label):
        if not condition: raise AssertionError(label)
        checks.append(label)
        print('PASS ' + label, flush=True)
    try:
        harness.setup()
        session = Session(harness.base)
        check(not session.login('behavior-admin')['login_form'], 'administrator authenticated')
        user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        verify_device_template_definitions(harness, session, user, check)
        if args.coverage_output:
            from coverage_support import publish_coverage
            publish_coverage(args.coverage_output, False, checks)
        print(f'{len(checks)} device template HTTP/MariaDB checks passed', flush=True)
    except Exception:
        diagnostics = harness.command('php', '-r', '$lines = @file("log/cacti.log") ?: []; foreach ($lines as $line) { if (str_contains($line, "DEVICE-TEMPLATE-DEFINITION:")) echo $line; }')
        print(diagnostics['stdout'], flush=True)
        raise
    finally:
        if harness.setup_started: harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__': main()
