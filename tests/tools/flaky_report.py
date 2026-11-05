#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Compare JUnit reports from repeated runs of one suite and list flaky tests.

Memon et al., "Taming Google-Scale Continuous Testing" (ICSE-SEIP 2017),
separate tests that fail consistently from tests whose result changes between
runs of the same code. The flaky-tests workflow runs the suite several times,
in file order and in seeded random order, and passes every report here.

A test is flaky when it passes in one run and fails or errors in another. A
test that fails in every run is reported separately: that is a broken test,
not a flaky one. A report that is missing, unreadable or empty, or runs that
disagree on which tests exist, is a tool error rather than a clean result.

Exit status: 0 no flaky or failing tests, 1 flaky or consistently failing
tests found, 2 the reports could not be compared.
"""
from __future__ import annotations

import argparse
from collections import defaultdict
from pathlib import Path
import sys

try:
    from defusedxml.ElementTree import parse
except ImportError:  # pragma: no cover - CI installs defusedxml from tests/tools/requirements.txt
    print('ERROR: defusedxml is required (pip install -r tests/tools/requirements.txt)', file=sys.stderr)
    sys.exit(2)

PASSED, FAILED, SKIPPED = 'passed', 'failed', 'skipped'


def outcomes(report: Path) -> dict[str, str]:
    """Map each test case in a JUnit report to passed, failed or skipped."""
    try:
        root = parse(report).getroot()
    except (OSError, SyntaxError) as error:  # ParseError is a SyntaxError
        raise ValueError(f'{report}: {error}') from error
    results: dict[str, str] = {}
    for case in root.iter('testcase'):
        name = '::'.join(part for part in (case.get('file') or case.get('classname') or '', case.get('name') or '') if part)
        if name in results:
            # A data set can repeat a name; keep both runs distinct but comparable.
            suffix = 2
            while f'{name} #{suffix}' in results:
                suffix += 1
            name = f'{name} #{suffix}'
        if case.find('failure') is not None or case.find('error') is not None:
            results[name] = FAILED
        elif case.find('skipped') is not None:
            results[name] = SKIPPED
        else:
            results[name] = PASSED
    if not results:
        raise ValueError(f'{report}: no test cases')
    return results


def compare(reports: list[Path]) -> tuple[list[tuple[str, list[str]]], list[str], int]:
    """Return flaky tests with their per-run outcomes, consistently failing tests, and the test count."""
    if len(reports) < 2:
        raise ValueError('at least two reports are needed to compare runs')
    runs = [outcomes(report) for report in reports]
    names = set(runs[0])
    for report, run in zip(reports[1:], runs[1:]):
        if set(run) != names:
            missing, extra = sorted(names - set(run))[:3], sorted(set(run) - names)[:3]
            raise ValueError(f'{report} ran a different set of tests (missing {missing}, extra {extra})')
    by_test: dict[str, list[str]] = defaultdict(list)
    for run in runs:
        for name, result in run.items():
            by_test[name].append(result)
    flaky = sorted((name, results) for name, results in by_test.items() if PASSED in results and FAILED in results)
    failing = sorted(name for name, results in by_test.items() if all(result == FAILED for result in results))
    return flaky, failing, len(names)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('reports', nargs='+', type=Path, help='JUnit XML reports, one per run')
    parser.add_argument('--labels', help='comma-separated run labels for the report, such as file-order,seed-41')
    arguments = parser.parse_args(argv)
    labels = arguments.labels.split(',') if arguments.labels else [report.stem for report in arguments.reports]
    if len(labels) != len(arguments.reports):
        parser.error('--labels needs one label per report')
    try:
        flaky, failing, total = compare(arguments.reports)
    except ValueError as error:
        print(f'ERROR: {error}', file=sys.stderr)
        return 2

    print(f'{total} tests compared across {len(arguments.reports)} runs: {len(flaky)} flaky, {len(failing)} failing in every run.\n')
    if flaky:
        print('| Flaky test | ' + ' | '.join(labels) + ' |')
        print('| --- |' + ' --- |' * len(labels))
        for name, results in flaky:
            escaped = name.replace('|', '\\|')
            print(f'| {escaped} | ' + ' | '.join(results) + ' |')
        print()
    if failing:
        print('Failing in every run:')
        for name in failing:
            print(f'- {name}')
    return 1 if flaky or failing else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
