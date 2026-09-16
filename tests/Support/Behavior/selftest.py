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
from unittest.mock import patch

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



def recording_guards():
    from unittest.mock import patch
    import tempfile
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        (root / 'cacti.sql').write_text('schema')
        for case in ('empty', 'missing', 'unexpected', 'orphan', 'other-runtime-orphan', 'complete'):
            recorder = object.__new__(harness.Harness)
            recorder.args = types.SimpleNamespace(target=case, only=None, update_golden=True)
            recorder.destination = root / 'results' / case
            recorder.observed = {name: {'value': name} for name in harness.EXPECTED_SCENARIOS}
            if case == 'empty': recorder.observed = {}
            if case == 'missing': recorder.observed.pop(next(iter(recorder.observed)))
            if case == 'unexpected': recorder.observed['unknown/capture'] = 1
            recorder.command = lambda *a, **kw: {'stdout': '8.2', 'stderr': '', 'exit': 0}
            recorder.base_image_digest = lambda: {'ref': 'fixture'}
            golden = root / 'tests/Golden' / case / 'php-8.2'
            if case == 'other-runtime-orphan':
                other = golden.parent / 'php-8.3'
                other.mkdir(parents=True)
                (other / 'removed.json').write_text('42')
            if case == 'orphan':
                golden.mkdir(parents=True)
                (golden / 'removed.json').write_text('42')
            before = {str(p): p.read_bytes() for p in golden.rglob('*.json')}
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
                status = recorder.finish()
            manifest = json.loads((recorder.destination / 'observations.json').read_text())
            assert (status == 0) == (case == 'complete'), case
            assert manifest['complete'] == (case == 'complete'), case
            if case != 'complete':
                assert {str(p): p.read_bytes() for p in golden.rglob('*.json')} == before, case
            else:
                assert len(list(golden.rglob('*.json'))) == len(harness.EXPECTED_SCENARIOS)
    print('recording rejects empty, missing, unexpected and orphaned scenarios before writing goldens')


def diagnostic_contracts():
    events = [dict(severity=8192, suppressed=True, suppressed_here=True),
              dict(severity=512, suppressed=False, suppressed_here=True),
              dict(severity=512, suppressed=False, suppressed_here=False),
              dict(severity='FATAL')]
    assert harness.visible_diagnostics(events) == events[2:]
    log = ('09/16/2026 01:02:03 - ERROR PHP WARNING: first 2020-01-01 in /var/www/html/lib/x.php:42\n'
           '09/16/2026 01:02:04 - ERROR PHP NOTICE: second\n'
           '09/16/2026 01:02:05 - SYSTEM STATS: Time:1\n'
           'ordinary message - ERROR PHP WARNING: not a log record\n')
    assert harness.application_diagnostics(log) == [
        {'subsystem': 'ERROR', 'message': 'PHP WARNING: first 2020-01-01 in <APP>/lib/x.php:42'},
        {'subsystem': 'ERROR', 'message': 'PHP NOTICE: second'}]
    assert harness.application_diagnostics(log + log) == harness.application_diagnostics(log) * 2
    timing_warning = 'PHP WARNING: OK u:1.23 s:2.34 r:3.45 SYSTEM STATS: Time:1.23 in /harness/probe.php:7'
    assert harness.application_diagnostics('09/16/2026 01:02:06 - ERROR ' + timing_warning) == [
        {'subsystem': 'ERROR', 'message': timing_warning.replace('/harness', '<HARNESS>')}]

    for size in (108, 4356):
        broken = f'09/16/2026 01:02:06 - ERROR PHP NOTICE: fwrite(): Write of {size} bytes failed with errno=32 Broken pipe in file: /var/www/html/lib/rrd.php on line: 334'
        assert harness.application_diagnostics(broken) == [{'subsystem': 'ERROR', 'message': 'PHP NOTICE: fwrite(): Write of <BYTES> bytes failed with errno=32 Broken pipe in file: <APP>/lib/rrd.php on line: 334'}]
    unrelated = '09/16/2026 01:02:06 - ERROR PHP WARNING: payload has 108 bytes'
    assert harness.application_diagnostics(unrelated)[0]['message'].endswith('108 bytes')

    captured = object.__new__(harness.Harness)
    captured.observed = {}
    captured.capture('diagnostics/application-log', harness.application_diagnostics(
        '09/16/2026 01:02:06 - ERROR ' + timing_warning))
    assert captured.observed['diagnostics/application-log'][0]['message'] == timing_warning.replace('/harness', '<HARNESS>')
    stale = '09/16/2026 01:02:01 - ERROR PHP WARNING: behavior application-handler calibration\n'
    fresh = '09/16/2026 01:02:07 - ERROR PHP WARNING: behavior application-handler calibration\n'
    for after, expected_failure in ((stale, 'missed'), (fresh, 'rotated'), (stale + fresh, None)):
        captured = object.__new__(harness.Harness)
        captured.observed = {}
        reads = iter((stale, after))
        captured.command = lambda *args, **kwargs: {'stdout': next(reads)}
        captured.php = lambda *args: {'exit': 0}
        try:
            captured.capture_application_diagnostics()
        except RuntimeError as error:
            assert expected_failure and expected_failure in str(error), (after, error)
        else:
            assert expected_failure is None, after
            assert len(captured.observed['diagnostics/application-log']) == 2
    print('diagnostic scopes preserve severity, content, order and duplicate records')


def poller_acknowledgement_contract():
    acknowledgement = 'OK u:0.01 s:0.02 r:0.03\n'
    other = 'statistics\nwarning containing OK u:0.01 s:0.02 r:0.03\n'
    def contract(stdout):
        return harness.poller_command_contract({'exit': 7, 'stdout': stdout, 'stderr': 'error\n'})
    before = contract(acknowledgement * 2 + other)
    after = contract(acknowledgement + other + acknowledgement)
    assert before == after
    assert before == {'exit': 7, 'stdout': other, 'stderr': 'error\n', 'rrd_acknowledgements': 2}
    assert contract(acknowledgement + other) != before
    assert contract('OK u:broken s:0.02 r:0.03\n' + other)['rrd_acknowledgements'] == 0
    assert contract('line two\nline one\n')['stdout'] == 'line two\nline one\n'
    probe = object.__new__(harness.Harness)
    probe.observed = {}
    probe.capture('faults/missing-rrd-file', {'command': before})
    assert probe.observed['faults/missing-rrd-file']['command']['rrd_acknowledgements'] == 2
    assert probe.observed['faults/missing-rrd-file']['command']['stdout'] == harness.normalize(other)
    for golden in (harness.ROOT / 'tests/Golden/cacti-1.2.31').glob('php-*/*/*.json'):
        command = json.loads(golden.read_text()).get('command', {}) if isinstance(json.loads(golden.read_text()), dict) else {}
        if 'rrd_acknowledgements' in command:
            assert not any(line.startswith('OK u:') for line in command['stdout'].splitlines()), str(golden)
    print('poller acknowledgement counts remain stable without erasing errors or other output order')


def boundary_status_channel():
    for completed in (True, False):
        captured = object.__new__(harness.Harness)
        calls = []
        def command(*args, **kwargs):
            calls.append(args)
            if args[0] == 'cat':
                return {'exit': 0, 'stdout': 'complete\n' if completed else '', 'stderr': ''}
            return {'exit': 70, 'stdout': '', 'stderr': 'application output'}
        captured.command = command
        try:
            result = captured.php('poller.php')
        except RuntimeError as error:
            assert not completed and 'boundary failed' in str(error)
        else:
            assert completed and result['exit'] == 70 and result['stderr'] == 'application output'
        assert calls[-1][0:2] == ('rm', '-f')
        assert calls[0][4] == calls[-1][2]
    print('separate completion channel preserves application status and rejects incomplete observations')


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
    print('process-group boundary waits for delayed grandchildren and distinguishes application exit 70')


def main():
    failures = []

    for label, given, expected in CASES:
        actual = harness.normalize(given)
        if actual != expected:
            failures.append(f'{label}:\n  expected {expected!r}\n  got      {actual!r}')

    print(f'{len(CASES) - len(failures)}/{len(CASES)} normalization cases pass')

    poller_acknowledgement_contract()
    boundary_status_channel()
    with patch('sys.platform', 'linux'), patch('shutil.which', return_value=None):
        try:
            native_worker_boundary()
        except RuntimeError as error:
            assert 'requires PHP' in str(error)
        else:
            raise AssertionError('Linux validation silently skipped PHP')
    native_worker_boundary()
    recording_guards()
    diagnostic_contracts()
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
