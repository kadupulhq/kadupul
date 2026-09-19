#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Check that malformed integration evidence cannot replace unit coverage."""
import argparse
import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[3]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--unit', type=Path, required=True)
    parser.add_argument('--integration', type=Path, required=True)
    args = parser.parse_args()
    manifest = json.loads((args.integration / 'observations.json').read_text())
    poller = '/var/www/html/poller.php'
    measured = None
    for path in (args.integration / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        if 1 in report['files'].get(poller, {}).get('lines', {}).values():
            measured = {'php': report['php'], 'files': {poller: report['files'][poller]}}
            break
    if measured is None:
        raise RuntimeError('Self-test requires actual measured poller coverage')
    cases = ['stale-source', 'invalid-hit', 'invalid-line', 'missing-scenario',
             'missing-reports', 'unexecuted-poller', 'path-traversal']
    with tempfile.TemporaryDirectory(prefix='coverage-failure-') as directory:
        root = Path(directory)
        (root / 'raw').mkdir()
        raw = root / 'raw/coverage-probe.json'
        output = root / 'combined.xml'
        for case in cases:
            data = copy.deepcopy(measured)
            evidence = copy.deepcopy(manifest)
            lines = data['files'][poller]['lines']
            if case == 'stale-source':
                data['files'][poller]['sha256'] = '0' * 64
            elif case == 'invalid-hit':
                lines[next(iter(lines))] = 2
            elif case == 'invalid-line':
                lines['0'] = 1
            elif case == 'missing-scenario':
                evidence['scenarios'].pop('poller/run-reachable')
            elif case == 'unexecuted-poller':
                data['files'][poller]['lines'] = {key: -1 for key in lines}
            elif case == 'path-traversal':
                data['files']['/var/www/html/../html/poller.php'] = data['files'].pop(poller)
            raw.write_text(json.dumps(data))
            if case == 'missing-reports':
                raw.unlink()
            (root / 'observations.json').write_text(json.dumps(evidence))
            output.write_text('original unit coverage')
            result = subprocess.run([args.php, str(ROOT / 'tests/Support/Behavior/merge_poller_coverage.php'),
                                     str(args.unit.resolve()), str(root), str(output)],
                                    capture_output=True, text=True, timeout=60)
            if result.returncode == 0 or output.read_text() != 'original unit coverage':
                raise RuntimeError(f'{case}: invalid evidence replaced unit coverage')
            expected = {'stale-source': 'Covered source differs', 'invalid-hit': 'Invalid PCOV',
                        'invalid-line': 'Invalid PCOV', 'missing-scenario': 'Incomplete integration',
                        'missing-reports': 'Missing integration', 'unexecuted-poller': 'No real poller',
                        'path-traversal': 'Invalid coverage source'}[case]
            if expected not in result.stdout + result.stderr:
                raise RuntimeError(f'{case}: failed for an unexpected reason: {result.stderr}')
            print(f'PASS {case}', flush=True)


if __name__ == '__main__':
    main()
