"""Reject stale, incomplete or malformed measurements before publishing Clover."""
import argparse
import copy
import json
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[2]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default='php')
    parser.add_argument('--unit', type=Path, required=True)
    parser.add_argument('--files', type=Path, required=True)
    parser.add_argument('--database', type=Path, required=True)
    parser.add_argument('--offline', type=Path, required=True)
    args = parser.parse_args()
    manifest = json.loads((args.files / 'observations.json').read_text())
    measured = {'php': '8.2', 'files': {}}
    prefix = '/var/www/html/'
    required = [prefix + path for path in (
        'bin/legacy-device-edit.php', 'src/IdentityAccess/Infrastructure/Legacy/SharedSession.php',
        'src/Inventory/Infrastructure/Symfony/Controller/DeviceEditController.php')]
    for path in (args.files / 'raw').glob('coverage-*.json'):
        report = json.loads(path.read_text())
        for source in required:
            if 1 in (report['files'] or {}).get(source, {}).get('lines', {}).values():
                measured['files'][source] = report['files'][source]
    if set(measured['files']) != set(required):
        raise RuntimeError('Self-test requires real HTTP and worker measurements')
    failures = {
        'source-hash': 'Covered source differs',
        'test-hash': 'Integration test source differs',
        'details-test-hash': 'Integration test source differs',
        'missing-check': 'Incomplete Symfony integration',
        'wrong-handler': 'Wrong integration suite',
        'missing-reports': 'Missing integration coverage',
        'invalid-hit': 'Invalid PCOV',
        'invalid-line': 'Invalid PCOV',
        'unmeasured-worker': 'Missing measured execution',
        'path-traversal': 'Invalid integration source path',
    }
    with tempfile.TemporaryDirectory(prefix='symfony-coverage-negative-') as directory:
        scratch = Path(directory)
        (scratch / 'raw').mkdir()
        raw = scratch / 'raw/coverage-probe.json'
        output = scratch / 'result.xml'
        for case, expected in failures.items():
            data = copy.deepcopy(measured)
            evidence = copy.deepcopy(manifest)
            worker = data['files'][required[0]]
            if case == 'source-hash':
                worker['sha256'] = '0' * 64
            elif case == 'test-hash':
                evidence['source_sha256']['tests/Symfony/session_bridge.py'] = '0' * 64
            elif case == 'details-test-hash':
                evidence['source_sha256']['tests/Symfony/details_scenarios.py'] = '0' * 64
            elif case == 'missing-check':
                evidence['checks'] = []
            elif case == 'wrong-handler':
                evidence['session_handler'] = 'database'
            elif case == 'invalid-hit':
                worker['lines'][next(iter(worker['lines']))] = 2
            elif case == 'invalid-line':
                worker['lines']['0'] = 1
            elif case == 'unmeasured-worker':
                worker['lines'] = {line: -1 for line in worker['lines']}
            elif case == 'path-traversal':
                data['files'][prefix + 'src/../bin/legacy-device-edit.php'] = data['files'].pop(required[0])
            raw.write_text(json.dumps(data))
            if case == 'missing-reports':
                raw.unlink()
            (scratch / 'observations.json').write_text(json.dumps(evidence))
            output.write_text('previous report')
            result = subprocess.run([args.php, str(ROOT / 'tests/Symfony/merge_coverage.php'),
                                     str(args.unit.resolve()), str(scratch),
                                     str(args.database.resolve()), str(args.offline.resolve()), str(output)],
                                    capture_output=True, text=True, timeout=60)
            if result.returncode == 0 or expected not in result.stdout + result.stderr:
                raise RuntimeError(f'{case}: unexpected merge result: {result.stdout} {result.stderr}')
            if output.read_text() != 'previous report':
                raise RuntimeError(f'{case}: invalid measurements replaced the previous report')
            print('PASS ' + case, flush=True)


if __name__ == '__main__':
    main()
