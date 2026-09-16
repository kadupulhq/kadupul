"""Self-tests for the harness's own normalization.

The goldens are only as trustworthy as what normalize() leaves alone. A pattern
that is too greedy erases a real contract, and nothing downstream notices,
because the golden and the observation are normalized the same way.

Run with: python tests/Support/Behavior/selftest.py
"""
import importlib.util
import json
from pathlib import Path
import shutil
import sys
import types
import uuid

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('harness', Path(__file__).with_name('harness.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)

# (label, input, expected). Anything not listed as changing must survive intact.
CASES = [
    # Wall clock must go.
    ('cacti log line', '09/12/2026 02:39:50 - SYSTEM STATS: DataSources:5',
     '<TIMESTAMP> - SYSTEM STATS: DataSources:5'),
    ('contract datetime', 'completed at 2026-09-11 20:50:21', 'completed at 2026-09-11 20:50:21'),
    ('contract ISO', '2026-09-12T02:39:50Z ready', '2026-09-12T02:39:50Z ready'),
    ('installer timing', '[20:47:59] [ global always ] Installation was started at 2026-09-11 20:50:21, completed at 2026-09-11 20:55:21',
     '[<TIME>] [ global always ] Installation was started at <TIMESTAMP>, completed at <TIMESTAMP>'),
    ('database date', {'created': '2026-09-12 02:39:50'}, {'created': '2026-09-12 02:39:50'}),
    ('bracketed clock', '[20:47:59] [ global ] Finished', '[<TIME>] [ global ] Finished'),
    ('clock after newline', 'start\n[20:47:59] [ global ] Finished', 'start\n[<TIME>] [ global ] Finished'),
    ('poller timing', 'OK u:0.12 s:0.03 r:0.20', 'OK u:<T> s:<T> r:<T>'),

    # Schema and behaviour must survive.
    ('zero DDL default', "status_fail_date\ttimestamp\tNO\t\t0000-00-00 00:00:00\t",
     "status_fail_date\ttimestamp\tNO\t\t0000-00-00 00:00:00\t"),
    ('version', 'Cacti Install Utility, Version 1.2.31', 'Cacti Install Utility, Version 1.2.31'),
    ('oid', '.1.3.6.1.4.1.8072.9999.1 = 42', '.1.3.6.1.4.1.8072.9999.1 = 42'),
    ('ids and ports', 'host id 7, rows 1234, port 161, timeout 500',
     'host id 7, rows 1234, port 161, timeout 500'),
    ('rra definition', 'RRA:AVERAGE:0.5:1:600', 'RRA:AVERAGE:0.5:1:600'),
    ('clock inside a message', 'maintenance window [12:34:56] kept', 'maintenance window [12:34:56] kept'),
    ('ds definition', 'DS:proc:GAUGE:600:0:U', 'DS:proc:GAUGE:600:0:U'),
]

# Exercise every date_time_format() option, preserving dates outside the
# diagnostic prefix and meaningful values later in the same diagnostic line.
for separator in ('-', '/', '.'):
    for month in ('09', 'Sep'):
        for parts in (('2026', month, '12'), (month, '12', '2026'), ('12', month, '2026')):
            stamp = separator.join(parts) + ' 02:39:50'
            suffix = ' - SYSTEM STATS: DataSources:5 cutoff=' + stamp
            CASES.append(('configured poller date ' + stamp, stamp + suffix, '<TIMESTAMP>' + suffix))
            CASES.append(('preserved message date ' + stamp, stamp + ' - plugin event', stamp + ' - plugin event'))



def incomplete_repeat_failure():
    """compare must refuse a partial repeat run instead of labelling differences
    NONDETERMINISTIC against it."""
    results = ROOT / 'tests/behavior/results'
    tag = 'selftest-' + uuid.uuid4().hex[:8]
    dirs = {}
    try:
        for role, complete in (('baseline', True), ('candidate', True), ('repeat', False)):
            dirs[role] = results / f'{tag}-{role}'
            dirs[role].mkdir(parents=True)
            (dirs[role] / 'observations.json').write_text(json.dumps(
                {'complete': complete, 'php': '8.2', 'base_image': {}, 'scenarios': {'x': 1}}))
        args = types.SimpleNamespace(baseline=dirs['baseline'].name, candidate=dirs['candidate'].name,
                                     approvals=None, repeat=str(dirs['repeat'] / 'observations.json'),
                                     output=str(dirs['baseline'] / 'comparison'))
        try:
            harness.compare(args)
        except RuntimeError:
            return None
        return 'incomplete repeat: compare accepted a partial control run'
    finally:
        for path in dirs.values():
            shutil.rmtree(path, ignore_errors=True)


def failed_setup_manifest():
    directory = ROOT / 'tests/behavior/results' / ('selftest-failure-' + uuid.uuid4().hex)
    recorder = object.__new__(harness.Harness)
    recorder.args = types.SimpleNamespace(target='selftest')
    recorder.observed = {}
    recorder.destination = directory
    def unavailable(*args, **kwargs):
        raise AssertionError('Failure cleanup must not probe unavailable containers')
    recorder.command = unavailable
    recorder.base_image_digest = unavailable
    try:
        assert recorder.finish(error='fixture setup failed') == 2
        result = json.loads((directory / 'observations.json').read_text())
        assert result['complete'] is False
        assert result['error'] == 'fixture setup failed'
        assert result['php'] is None and result['base_image'] is None
    finally:
        shutil.rmtree(directory, ignore_errors=True)


def unavailable_docker_failure():
    from unittest.mock import patch
    tag = 'selftest-docker-' + uuid.uuid4().hex[:8]
    def unavailable(*args, **kwargs):
        raise FileNotFoundError('docker unavailable')
    try:
        with patch('sys.argv', ['harness', 'run', '--target', tag]), patch.object(harness.Harness, 'compose', unavailable):
            assert harness.main() == 2
        manifest = json.loads((ROOT / 'tests/behavior/results' / tag / 'observations.json').read_text())
        assert manifest['complete'] is False
        assert manifest['error'] == 'docker unavailable'
    finally:
        shutil.rmtree(ROOT / 'tests/behavior/results' / tag, ignore_errors=True)
    print('unavailable Docker preserves the setup failure through teardown')
    with patch('sys.argv', ['harness', 'run', '--target', tag]), patch.object(harness.Harness, 'compose', unavailable), patch.object(harness.Harness, 'finish', side_effect=RuntimeError('manifest write failed')):
        try:
            harness.main()
        except RuntimeError as error:
            assert str(error) == 'manifest write failed'
        else:
            raise AssertionError('manifest failure was swallowed')
    print('cleanup preserves a manifest-write exception')



def native_worker_boundary():
    """Wait for delayed shells and grandchildren, including non-poller names."""
    if not sys.platform.startswith('linux'):
        print('native process-group boundary requires Linux /proc; covered in Linux validation')
        return
    if shutil.which('php') is None:
        raise RuntimeError('Linux worker-boundary validation requires PHP')
    import subprocess
    import tempfile
    with tempfile.TemporaryDirectory(prefix='behavior-worker-') as directory:
        marker = Path(directory) / 'complete'
        worker = Path(directory) / 'late-worker.php'
        worker.write_text('<?php usleep(200000); file_put_contents(' + json.dumps(str(marker)) + ', "done");')
        parent = Path(directory) / 'parent.php'
        parent.write_text('<?php $command = "sleep 0.3; " . escapeshellarg(PHP_BINARY) . " -d auto_prepend_file= " . escapeshellarg(' +
                          json.dumps(str(worker)) + '); exec("( " . $command . " ) >/dev/null 2>&1 &"); exit(7);')
        result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')), str(Path(directory) / 'status'), '-d', 'auto_prepend_file=', str(parent)],
                                capture_output=True, text=True, timeout=40)
        assert result.returncode == 7, result
        assert result.stderr == '', result.stderr
        assert marker.read_text() == 'done'
        assert (Path(directory) / 'status').read_text() == 'complete\n'
        # A real application exit of 70 must still have a completed boundary.
        parent.write_text('<?php exit(70);')
        (Path(directory) / 'status').unlink()
        result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                 str(Path(directory) / 'status'), '-d', 'auto_prepend_file=', str(parent)],
                                capture_output=True, text=True, timeout=40)
        assert result.returncode == 70 and result.stderr == '', result
        assert (Path(directory) / 'status').read_text() == 'complete\n'
        # An existing file must never be overwritten or treated as success.
        (Path(directory) / 'status').write_text('sentinel')
        result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                 str(Path(directory) / 'status'), str(parent)], capture_output=True, text=True, timeout=40)
        assert result.returncode == 70 and 'Cannot create' in result.stderr, result
        assert (Path(directory) / 'status').read_text() == 'sentinel'
        # The deadline covers both a hanging application and an orphan worker.
        import os
        import time
        for descendant in (False, True):
            late = Path(directory) / 'late-timeout'
            pidfile = Path(directory) / 'timeout-pid'
            late.unlink(missing_ok=True)
            pidfile.unlink(missing_ok=True)
            (Path(directory) / 'status').unlink()
            worker.write_text('<?php file_put_contents(' + json.dumps(str(pidfile)) + ', getmypid()); sleep(3); file_put_contents(' + json.dumps(str(late)) + ', "unexpected");')
            if descendant:
                parent.write_text('<?php exec(escapeshellarg(PHP_BINARY) . " " . escapeshellarg(' + json.dumps(str(worker)) + ') . " >/dev/null 2>&1 &");')
            else:
                parent.write_text(worker.read_text())
            result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                     str(Path(directory) / 'status'), str(parent)], capture_output=True, text=True, timeout=10,
                                    env={**os.environ, 'HARNESS_OBSERVATION_TIMEOUT': '1'})
            assert result.returncode == 70 and 'workers terminated' in result.stderr, result
            assert (Path(directory) / 'status').read_text() == ''
            pid = int(pidfile.read_text())
            stat = Path('/proc') / str(pid) / 'stat'
            assert not stat.exists() or stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z'
            time.sleep(2.2)
            assert not late.exists(), 'timed-out worker continued mutating artifacts'
    print('process-group boundary waits for delayed grandchildren and distinguishes application exit 70')


def main():
    failures = []

    for label, given, expected in CASES:
        actual = harness.normalize(given)
        if actual != expected:
            failures.append(f'{label}:\n  expected {expected!r}\n  got      {actual!r}')

    print(f'{len(CASES) - len(failures)}/{len(CASES)} normalization cases pass')

    native_worker_boundary()
    failed_setup_manifest()
    unavailable_docker_failure()
    print('setup failure records an incomplete manifest without probing containers')

    repeat_failure = incomplete_repeat_failure()
    if repeat_failure:
        failures.append(repeat_failure)
    else:
        print('compare rejects an incomplete repeat control')

    for failure in failures:
        print('FAIL ' + failure, file=sys.stderr)

    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
