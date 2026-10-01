# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Run the actual native CDEF probes against exclusively owned fixtures."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
PREVIOUS_COMMIT = '5a1c81c2dc89508052c7b54bf9db491f32f9beb7'
PREVIOUS_SCHEMA_SHA256 = '6f17f3462837d028c5e67a7b9ae963caa11f716f65036152431c316482284184'
CASES = (
    ('api', 'contract', (), False),
    ('callers', 'callers', (), False),
    ('copy', 'copy', (), False),
    ('delete', 'legacy_delete', (), False),
    ('raw_waiter', 'native', (), False),
    ('production_waiter', 'production_waiter', (), False),
    ('current_cli', 'installer', (), True),
    ('fresh_cli', 'normal_installer', ('fresh',), True),
    ('upgrade_cli', 'normal_installer', ('upgrade',), True),
    ('web', 'web_installer', (), True),
    ('failure_cli', 'installer_failure', (), True),
)


def probe_path(probe):
    suffix = '_probe.php' if probe == 'native' else '_native_probe.php'
    return Path('tests/security') / ('cdef_reference_' + probe + suffix)


def source_manifest(root):
    files = subprocess.check_output(['git', '-C', str(root), 'ls-files', '-z']).split(b'\0')
    return {os.fsdecode(path): hashlib.sha256((root / os.fsdecode(path)).read_bytes()).hexdigest()
            for path in files if path and (root / os.fsdecode(path)).is_file()}


def copy_candidate(root, destination, manifest):
    for relative in manifest:
        target = destination / relative
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(root / relative, target)
    for relative in ('include/vendor', 'include/fa', 'public/assets'):
        if not (root / relative).is_dir():
            raise RuntimeError('Real dependencies and compiled assets are required: ' + relative)
        shutil.copytree(root / relative, destination / relative, dirs_exist_ok=True)
    for relative in ('var', 'log', 'cache', 'rra'):
        (destination / relative).mkdir(parents=True, exist_ok=True)
    shutil.copy2(destination / 'tests/Fixtures/cdef-reference-runtime-config.php', destination / 'include/config.php')
    (destination / '.cdef-reference-task-owned-candidate').touch()


def previous_schema(root):
    data = subprocess.check_output(['git', '-C', str(root), 'show', PREVIOUS_COMMIT + ':cacti.sql'])
    if hashlib.sha256(data).hexdigest() != PREVIOUS_SCHEMA_SHA256:
        raise RuntimeError('The pinned historical source schema does not match its real artifact.')
    return data


def run(output):
    environment = os.environ.copy()
    if not environment.get('KADUPUL_REFERENCE_TEST_DSN', '').startswith('mysql:'):
        raise RuntimeError('An explicitly configured native fixture DSN is required.')
    if not environment.get('KADUPUL_REFERENCE_TEST_USER'):
        raise RuntimeError('An explicitly configured native fixture account is required.')
    if output.exists() and any(output.iterdir()):
        raise RuntimeError('Use an empty output directory; existing evidence is preserved.')
    output.mkdir(parents=True, exist_ok=True)
    manifest = source_manifest(ROOT)
    php = shutil.which('php')
    if php is None:
        raise RuntimeError('The selected PHP runtime is unavailable.')
    version = subprocess.check_output([php, '-r', 'echo PHP_VERSION;'], text=True)
    if not version.startswith('8.4.'):
        raise RuntimeError('Select PHP 8.4 through mise before running the native probes.')
    results = []
    report = {'php': version, 'source_sha256': manifest, 'historical_schema_sha256': PREVIOUS_SCHEMA_SHA256, 'results': results}
    (output / 'source-manifest.json').write_text(json.dumps(report, indent=2) + '\n')
    try:
        with tempfile.TemporaryDirectory(prefix='kadupul-cdef-native-') as temporary:
            candidate = Path(temporary) / 'candidate'
            copy_candidate(ROOT, candidate, manifest)
            historical = Path(temporary) / 'previous.sql'
            historical.write_bytes(previous_schema(ROOT))
            environment['KADUPUL_REFERENCE_PREVIOUS_SCHEMA_FILE'] = str(historical)
            for name, probe, arguments, installed in CASES:
                root = candidate if installed else ROOT
                log = output / (name + '.log')
                started = time.monotonic()
                print('START ' + name, flush=True)
                with log.open('w') as stream:
                    status = subprocess.run([php, '-d', 'auto_prepend_file=', '-d', 'zend.exception_ignore_args=1',
                                             str(root / probe_path(probe)), *arguments],
                                            cwd=root, env=environment, stdout=stream, stderr=subprocess.STDOUT).returncode
                results.append({'case': name, 'exit': status, 'seconds': round(time.monotonic() - started, 3),
                                'log_sha256': hashlib.sha256(log.read_bytes()).hexdigest()})
                print('FINISH ' + name + ' exit=' + str(status), flush=True)
                if status != 0:
                    raise RuntimeError('The actual native probe failed: ' + name + '. See its retained log.')
            if source_manifest(ROOT) != manifest:
                raise RuntimeError('Source changed while actual native evidence was produced.')
    finally:
        (output / 'results.json').write_text(json.dumps(report, indent=2) + '\n')
    print('PASS all eleven actual native probes on unchanged source', flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    run(parser.parse_args().output.resolve())
