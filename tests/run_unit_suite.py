#!/usr/bin/env python3
"""Run every unit file in isolation and fail on crashes, timeouts or missing results."""
import argparse
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import time
import xml.etree.ElementTree as ET


def run_file(command, cwd, log, timeout):
    with log.open('w', encoding='utf-8') as output:
        process = subprocess.Popen(command, cwd=cwd, stdout=output, stderr=subprocess.STDOUT,
                                   start_new_session=os.name == 'posix')
        try:
            return process.wait(timeout=timeout), False
        except subprocess.TimeoutExpired:
            if os.name == 'posix':
                os.killpg(process.pid, signal.SIGKILL)
            else:
                process.kill()
            process.wait()
            return process.returncode, True


def junit_suites(tree):
    if tree.tag not in ('testsuites', 'testsuite'):
        raise ValueError('JUnit report root must be testsuites or testsuite')
    for parent in tree.iter():
        for child in parent:
            if child.tag == 'testcase' and parent.tag != 'testsuite':
                raise ValueError('JUnit test cases must belong directly to a test suite')
            if child.tag == 'testsuite' and parent.tag not in ('testsuites', 'testsuite'):
                raise ValueError('JUnit test suites have an invalid parent')
            if child.tag == 'testsuites':
                raise ValueError('JUnit suite collections may appear only at the root')
    if tree.tag == 'testsuites' and any(child.tag != 'testsuite' for child in tree):
        raise ValueError('JUnit suite collection contains a non-suite child')
    return [tree] if tree.tag == 'testsuite' else list(tree)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--timeout', type=float, default=120)
    parser.add_argument('--output', type=Path, default=Path('artifacts/unit-suite'))
    args = parser.parse_args()
    root = Path(__file__).resolve().parent
    output = args.output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    files = sorted((root / 'Unit').rglob('*Test.php'))
    if not files:
        parser.error('No unit test files found')
    results = []
    combined = ET.Element('testsuites')
    for number, path in enumerate(files, 1):
        relative = path.relative_to(root).as_posix()
        stem = relative.replace('/', '_')
        report = output / (stem + '.xml')
        log = output / (stem + '.log')
        report.unlink(missing_ok=True)
        started = time.monotonic()
        command = [args.php, '-d', 'error_reporting=24575', 'vendor/bin/pest',
                   '--configuration=phpunit-unit.xml', '--colors=never',
                   '--fail-on-risky', '--fail-on-warning', '--log-junit=' + str(report), relative]
        status, timed_out = run_file(command, root, log, args.timeout)
        result = dict(file=relative, exit=status, timeout=timed_out,
                      seconds=round(time.monotonic() - started, 2), tests=0, skipped=0,
                      failures=0, errors=0, log=str(log))
        try:
            tree = ET.parse(report).getroot()
            suites = junit_suites(tree)
            cases = list(tree.iter('testcase'))
            result.update(tests=len(cases), skipped=sum(c.find('skipped') is not None for c in cases),
                          failures=sum(c.find('failure') is not None for c in cases),
                          errors=sum(c.find('error') is not None for c in cases))
            if not cases:
                raise ValueError('JUnit report contains no test cases')
            for suite in suites:
                combined.append(suite)
        except (OSError, ET.ParseError, ValueError) as error:
            result['report_error'] = str(error)
        result['passed'] = (status == 0 and not timed_out and not result.get('report_error')
                            and not result['failures'] and not result['errors'])
        results.append(result)
        print(f"[{number}/{len(files)}] {'PASS' if result['passed'] else 'FAIL'} {relative} "
              f"({result['tests']} tests, {result['skipped']} skipped)", flush=True)
    (output / 'results.json').write_text(json.dumps(results, indent=2) + '\n', encoding='utf-8')
    ET.ElementTree(combined).write(output / 'junit.xml', encoding='utf-8', xml_declaration=True)
    failures = [r for r in results if not r['passed']]
    print(f"Files: {len(files)}; failed: {len(failures)}; tests: {sum(r['tests'] for r in results)}; "
          f"skipped: {sum(r['skipped'] for r in results)}")
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
