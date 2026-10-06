# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Prove forward schema repair through actual CLI and HTTP installer entrypoints."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

from run_cdef_reference_contracts import copy_candidate, source_manifest

ROOT = Path(__file__).resolve().parents[2]
PROBE = 'tests/security/schema_repair_native_probe.php'


def run(output, previous_registration=False):
    environment = os.environ.copy()
    if not environment.get('KADUPUL_REFERENCE_TEST_DSN', '').startswith('mysql:'):
        raise RuntimeError('An explicit native task fixture DSN is required.')
    if not environment.get('KADUPUL_REFERENCE_TEST_USER'):
        raise RuntimeError('An explicit native task fixture account is required.')
    if output.exists() and any(output.iterdir()):
        raise RuntimeError('Use an empty output directory to preserve prior evidence.')
    output.mkdir(parents=True, exist_ok=True)
    manifest = source_manifest(ROOT)
    # New probe files may be unstaged during independent review; bind them too.
    for relative in (PROBE, 'tests/security/run_schema_repair_contracts.py'):
        manifest[relative] = hashlib.sha256((ROOT / relative).read_bytes()).hexdigest()
    php = shutil.which('php')
    if php is None:
        raise RuntimeError('Select PHP 8.4 through mise.')
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True)
    if not version.startswith('8.4.'):
        raise RuntimeError('Select PHP 8.4 through mise.')
    results = []
    report = {'php': version, 'source_sha256': manifest, 'results': results,
              'fixture': 'Current cacti.sql plus stored release and explicit missing-index/ON UPDATE drift; not a historical schema reconstruction.',
              'previous_registration': previous_registration}
    (output / 'source-manifest.json').write_text(json.dumps(report, indent=2) + '\n')
    try:
        with tempfile.TemporaryDirectory(prefix='kadupul-forward-schema-') as temporary:
            candidate = Path(temporary) / 'candidate'
            copy_candidate(ROOT, candidate, manifest)
            if previous_registration:
                for relative in ('include/cacti_version', 'include/global_arrays.php'):
                    data = subprocess.check_output(['git', '-C', str(ROOT), 'show', previous_registration + ':' + relative])
                    (candidate / relative).write_bytes(data)
                    report.setdefault('previous_registration_sha256', {})[relative] = hashlib.sha256(data).hexdigest()
            for mode in (('cli',) if previous_registration else ('cli', 'web')):
                for release in ('1.2.31', '1.2.32', '1.2.33', '1.2.34'):
                    for failure in ((False,) if previous_registration else (False, True, 'late-ddl') if release == '1.2.34' else (False, True)):
                        name = mode + '-' + release + ('-late-ddl-retry' if failure == 'late-ddl' else '-failure-retry' if failure else '-success')
                        log = output / (name + '.log')
                        arguments = [mode, release] + (['late-ddl'] if failure == 'late-ddl' else ['failure'] if failure else [])
                        started = time.monotonic()
                        print('START ' + name, flush=True)
                        with log.open('w') as stream:
                            status = subprocess.run([php, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1',
                                                     str(candidate / PROBE), *arguments], cwd=candidate,
                                                    env=environment, stdout=stream, stderr=subprocess.STDOUT,
                                                    timeout=650).returncode
                        marker = 'PASS actual forward schema ' + mode + ' admission and cleanup complete'
                        valid = status == 0 and log.read_text().splitlines().count(marker) == 1
                        results.append({'case': name, 'exit': status, 'marker': valid,
                                        'seconds': round(time.monotonic() - started, 3),
                                        'log_sha256': hashlib.sha256(log.read_bytes()).hexdigest()})
                        print('FINISH ' + name + ' exit=' + str(status), flush=True)
                        if previous_registration:
                            if status == 0 or 'RuntimeException: actual entrypoint persists the exact nonunique full-column ascending BTREE index' not in log.read_text():
                                raise RuntimeError('Omission control did not reach the intended missing-index invariant: ' + name)
                        elif not valid:
                            raise RuntimeError('Actual forward repair admission failed: ' + name + '; retained log.')
            current = source_manifest(ROOT)
            for relative in (PROBE, 'tests/security/run_schema_repair_contracts.py'):
                current[relative] = hashlib.sha256((ROOT / relative).read_bytes()).hexdigest()
            if current != manifest:
                raise RuntimeError('Source changed during actual entrypoint proof.')
    finally:
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')
    print(('PASS all four previous-registration controls reach unrepaired index failure' if previous_registration
           else 'PASS all 18 actual forward repair admission probes on unchanged source'), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--previous-registration', metavar='COMMIT', help='Use the exact prior release registry/version as an omission control.')
    options = parser.parse_args()
    run(options.output.resolve(), options.previous_registration)
