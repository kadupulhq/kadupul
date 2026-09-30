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
    args = parser.parse_args()
    harness = Harness(SimpleNamespace(project='kadupul-device-template-definition-review', target='device-template-definition-review'))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    assertions = 0
    def check(condition, label):
        nonlocal assertions
        if not condition: raise AssertionError(label)
        assertions += 1
        print('PASS ' + label, flush=True)
    try:
        harness.setup()
        session = Session(harness.base)
        check(not session.login('behavior-admin')['login_form'], 'administrator authenticated')
        user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        verify_device_template_definitions(harness, session, user, check)
        print(f'{assertions} device template HTTP/MariaDB checks passed', flush=True)
    except Exception:
        diagnostics = harness.command('php', '-r', '$lines = @file("log/cacti.log") ?: []; foreach ($lines as $line) { if (str_contains($line, "DEVICE-TEMPLATE-DEFINITION:")) echo $line; }')
        print(diagnostics['stdout'], flush=True)
        raise
    finally:
        if harness.setup_started: harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__': main()
