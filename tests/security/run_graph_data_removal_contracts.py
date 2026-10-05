# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run native graph/data removal proofs against task-owned database schemas."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[2]


def manifest():
    paths = subprocess.check_output(
        ['git', '-C', str(ROOT), 'ls-files', '--cached', '--others', '--exclude-standard', '-z']
    ).split(b'\0')
    return {os.fsdecode(path): hashlib.sha256((ROOT / os.fsdecode(path)).read_bytes()).hexdigest()
            for path in paths if path and (ROOT / os.fsdecode(path)).is_file()}


def validate_output(text):
    if text.splitlines().count('GRAPH_DATA_REMOVAL_NATIVE_COMPLETE') != 1:
        raise RuntimeError('Missing or duplicate native completion marker.')
    if sum(' expected admission/failure owned=' in line for line in text.splitlines()) != 19:
        raise RuntimeError('Incomplete native removal case discovery.')
    for marker, count in (
        ('PASS aggregate_success regenerates aggregate parent through production code', 2),
        ('PASS shared_selected_sources removes source shared only by selected set', 2),
        ('PASS shared_external_source retains source used by surviving graph', 2),
        ('PASS source_cache_failure reports cache invalidation separately from persistent cleanup', 2),
        ('PASS source_commit_failure reports cache invalidation separately from persistent cleanup', 1),
        ('PASS source_cache_success caller rollback cannot restore MEMORY cache', 1),
    ):
        if text.splitlines().count(marker) != count:
            raise RuntimeError('Missing or duplicate removal invariant marker: ' + marker)
    if 'UNEXPECTED_FAILURE' in text or 'Fatal error:' in text or 'Warning:' in text:
        raise RuntimeError('Native removal proof reported a runtime failure.')


def run(output):
    if not os.environ.get('KADUPUL_REFERENCE_TEST_DSN', '').startswith('mysql:'):
        raise RuntimeError('Explicit native fixture DSN required.')
    if output.exists() and any(output.iterdir()):
        raise RuntimeError('Use an empty output directory; prior evidence is preserved.')
    output.mkdir(parents=True, exist_ok=True)
    php = subprocess.check_output(['mise', 'which', 'php', '--tool=php@8.4.25'], text=True).strip()
    if not Path(php).is_file():
        raise RuntimeError('Selected PHP runtime unavailable.')
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True)
    if not version.startswith('8.4.'):
        raise RuntimeError('Select PHP 8.4 through mise.')
    sources = manifest()
    report = {'php': version, 'sources': sources, 'exit': None, 'verified': False}
    try:
        result = subprocess.run([php, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1',
                                 str(ROOT / 'tests/security/graph_data_removal_native_probe.php')],
                                cwd=ROOT, capture_output=True, text=True, timeout=600)
        (output / 'native.log').write_text(result.stdout)
        (output / 'native.stderr').write_text(result.stderr)
        report['exit'] = result.returncode
        report['log_sha256'] = hashlib.sha256(result.stdout.encode()).hexdigest()
        report['stderr_sha256'] = hashlib.sha256(result.stderr.encode()).hexdigest()
        if result.returncode != 0 or result.stderr:
            raise RuntimeError('Native removal proof failed; see retained logs.')
        validate_output(result.stdout)
        rejected = 0
        lines = result.stdout.splitlines()
        mutations = [
            '\n'.join(line for line in lines if line != 'GRAPH_DATA_REMOVAL_NATIVE_COMPLETE'),
            result.stdout + '\nGRAPH_DATA_REMOVAL_NATIVE_COMPLETE\n',
            '\n'.join(line for line in lines if 'aggregate_success expected admission/failure owned=0' not in line),
            result.stdout + '\nWarning: omitted runtime failure\n',
        ]
        for prefix in ('PASS aggregate_success regenerates', 'PASS shared_selected_sources removes',
                       'PASS shared_external_source retains', 'PASS source_cache_failure reports',
                       'PASS source_commit_failure reports', 'PASS source_cache_success caller rollback cannot'):
            marker = next(line for line in lines if line.startswith(prefix))
            mutations.extend(('\n'.join(line for line in lines if line != marker), result.stdout + '\n' + marker + '\n'))
        for mutation in mutations:
            try:
                validate_output(mutation)
            except RuntimeError:
                rejected += 1
            else:
                raise RuntimeError('Incomplete or duplicate native evidence was admitted.')
        report['rejected_evidence_controls'] = rejected
        if manifest() != sources:
            raise RuntimeError('Source changed during native removal proof.')
        report['verified'] = True
    finally:
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')
    print('PASS 19 native removal cases on unchanged source')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    run(parser.parse_args().output.resolve())
