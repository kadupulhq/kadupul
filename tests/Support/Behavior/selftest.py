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
        for case in ('empty', 'missing', 'unexpected', 'orphan', 'other-runtime-orphan', 'other-runtime-missing', 'other-runtime-empty', 'current-runtime-missing', 'other-runtime-complete', 'complete'):
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
            if case in ('other-runtime-missing', 'other-runtime-empty', 'other-runtime-complete', 'current-runtime-missing'):
                for name in harness.EXPECTED_SCENARIOS:
                    harness.write_json(golden / (name + '.json'), {'value': name})
                other = golden if case == 'current-runtime-missing' else golden.parent / 'php-8.3'
                other.mkdir(parents=True, exist_ok=True)
                names = sorted(harness.EXPECTED_SCENARIOS)
                if case == 'other-runtime-empty': names = []
                elif case != 'other-runtime-complete': names = names[:-1]
                if case == 'current-runtime-missing':
                    (other / (sorted(harness.EXPECTED_SCENARIOS)[-1] + '.json')).unlink()
                else:
                    for name in names: harness.write_json(other / (name + '.json'), {'value': name})
            before = {str(p): p.read_bytes() for p in golden.parent.rglob('*.json')}
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
                status = recorder.finish()
            manifest = json.loads((recorder.destination / 'observations.json').read_text())
            assert (status == 0) == (case in ('complete', 'other-runtime-complete')), case
            assert manifest['complete'] == (case in ('complete', 'other-runtime-complete')), case
            if manifest['complete']:
                assert 'inventory_missing' not in manifest, 'Successful format-1 manifests retain their historical schema'
            if case not in ('complete', 'other-runtime-complete'):
                assert {str(p): p.read_bytes() for p in golden.parent.rglob('*.json')} == before, case
            else:
                assert len(list(golden.rglob('*.json'))) == len(harness.EXPECTED_SCENARIOS)
        recorder.args = types.SimpleNamespace(target='absent-runtime', only=None, update_golden=False)
        recorder.destination = root / 'results/absent-runtime'
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
            assert recorder.finish() == 2
            assert json.loads((recorder.destination / 'observations.json').read_text())['complete'] is False
        recorder.args = types.SimpleNamespace(target='bootstrap', only=None, update_golden=True, bootstrap_goldens=True)
        recorder.destination = root / 'results/bootstrap'
        target = root / 'tests/Golden/bootstrap'
        names = sorted(harness.EXPECTED_SCENARIOS)
        for version in ('8.2', '8.3'):
            for name in names[:-1]:
                harness.write_json(target / ('php-' + version) / (name + '.json'), {'value': name})
        recorder.command = lambda *a, **kw: {'stdout': '8.2', 'stderr': '', 'exit': 0}
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
            assert recorder.finish() == 0
            assert json.loads((recorder.destination / 'observations.json').read_text())['complete'] is False
            assert (target / 'php-8.2' / (names[-1] + '.json')).exists()
            assert not (target / 'php-8.3' / (names[-1] + '.json')).exists()
            recorder.args.update_golden = False
            assert recorder.finish() == 2, 'Verification must reject an incomplete other runtime even with bootstrap set'
            recorder.args.update_golden = True
            recorder.command = lambda *a, **kw: {'stdout': '8.3', 'stderr': '', 'exit': 0}
            assert recorder.finish() == 0
            recorder.args.update_golden = False
            assert recorder.finish() == 0
            assert json.loads((recorder.destination / 'observations.json').read_text())['complete'] is True
            recorder.args.update_golden = True
            (target / 'php-8.2/removed.json').write_text('42')
            before = {str(p): p.read_bytes() for p in target.rglob('*.json')}
            assert recorder.finish() == 2, 'Bootstrap must still reject orphaned contracts'
            assert {str(p): p.read_bytes() for p in target.rglob('*.json')} == before
    print('recording guards reject incomplete inventories; explicit bootstrap captures each runtime without weakening verification')


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
    assert harness.normalize('PHP WARNING: payload SYSTEM STATS: Time:1.23') == 'PHP WARNING: payload SYSTEM STATS: Time:1.23'
    assert harness.normalize('SYSTEM STATS: Time:1.23 DataSources:5') == 'SYSTEM STATS: Time:<T> DataSources:5'
    assert harness.normalize('09/16/2026 01:02:05 - SYSTEM STATS: Time:1.23 DataSources:5') == '<TIMESTAMP> - SYSTEM STATS: Time:<T> DataSources:5'
    assert harness.application_diagnostics('09/16/2026 01:02:06 - ERROR ' + timing_warning) == [
        {'subsystem': 'ERROR', 'message': timing_warning.replace('/harness', '<HARNESS>')}]

    for size in (108, 4356):
        broken = f'09/16/2026 01:02:06 - ERROR PHP NOTICE: fwrite(): Write of {size} bytes failed with errno=32 Broken pipe in file: /var/www/html/lib/rrd.php on line: 334'
        assert harness.application_diagnostics(broken) == [{'subsystem': 'ERROR', 'message': 'PHP NOTICE: fwrite(): Write of <BYTES> bytes failed with errno=32 Broken pipe in file: <APP>/lib/rrd.php on line: <LINE>'}]
        native = broken.replace('PHP NOTICE: fwrite', 'PHP Notice:  fwrite')
        assert 'Write of <BYTES> bytes failed with errno=32' in harness.normalize(native)
        command = harness.normalize({'stdout': broken, 'stderr': broken})
        assert 'Write of <BYTES> bytes failed with errno=32' in command['stdout']
        assert command['stdout'] == command['stderr']
        assert command['stdout'].endswith('on line: <LINE>')
        assert 'errno=13 Permission denied' in harness.normalize_failed_write_size(broken.replace('errno=32 Broken pipe', 'errno=13 Permission denied'))
        diagnostic = broken.split(' - ERROR ', 1)[1]
        for payload in ('quoted ' + diagnostic, 'PHP WARNING: payload quotes ' + diagnostic,
                        '09/16/2026 01:02:06 - ERROR PHP WARNING: payload quotes ' + diagnostic):
            assert harness.normalize_failed_write_size(payload) == payload
            assert harness.normalize(payload) != harness.normalize(payload.replace(str(size), str(size + 1), 1))
        assert harness.normalize_failed_write_size(diagnostic + ' trailing payload') == diagnostic + ' trailing payload'
        assert harness.normalize_failed_write_size(diagnostic + '\n' + broken).count('<BYTES>') == 2
    trace = '09/16/2026 01:02:06 - CMDPHP PHP ERROR Backtrace: (/var/www/html/lib/rrd.php[334]:update(), DS[12])'
    assert harness.application_diagnostics(trace) == harness.application_diagnostics(trace.replace('[334]', '[900]'))
    assert 'DS[12]' in harness.application_diagnostics(trace)[0]['message']
    for severity in ('ERROR', 'WARNING', 'NOTICE', 'DEPRECATED', 'USER_WARNING', 'USER_NOTICE', 'USER_ERROR', 'USER_DEPRECATED', 'STRICT', 'PARSE', 'CORE_ERROR', 'CORE_WARNING', 'COMPILE_ERROR', 'COMPILE_WARNING', 'RECOVERABLE_ERROR', 'ALL', 'Unknown Error'):
        diagnostic = f'PHP {severity}: calibration in file: /harness/probe.php on line: 79'
        assert harness.normalize_php_locations(diagnostic).endswith('on line: <LINE>')
    relative_trace = 'PHP ERROR Backtrace: (/poller.php[764]:main(), /lib/functions.php[4479]:log(), DS[12])'
    assert harness.normalize_php_locations(relative_trace) == 'PHP ERROR Backtrace: (/poller.php[<LINE>]:main(), /lib/functions.php[<LINE>]:log(), DS[12])'
    assert harness.normalize_php_locations('ordinary /poller.php[764]') == 'ordinary /poller.php[764]'
    payload = 'PHP WARNING: payload /some/message.php[123] and /lib/file.php[456]:read()'
    assert harness.normalize_php_locations(payload) == payload
    assert harness.normalize(payload) != harness.normalize(payload.replace('[123]', '[124]'))
    disguised = 'PHP WARNING: payload PHP ERROR Backtrace: (/some/message.php[123]:read())'
    assert harness.normalize_php_locations(disguised) == disguised
    for severity in ('PARSE', 'CORE_WARNING', 'COMPILE_ERROR', 'RECOVERABLE_ERROR', 'Unknown Error'):
        diagnostic = f'09/16/2026 01:02:06 - ERROR PHP {severity}: payload /some/message.php[123] in file: /harness/probe.php on line: 79'
        observed = harness.application_diagnostics(diagnostic)
        assert observed == harness.application_diagnostics(diagnostic.replace('line: 79', 'line: 80'))
        assert observed != harness.application_diagnostics(diagnostic.replace('[123]', '[124]'))
    assert harness.normalize_php_locations(relative_trace.replace('Backtrace:', 'message:')) == relative_trace.replace('Backtrace:', 'message:')


    assert harness.application_diagnostics(broken) == harness.application_diagnostics(broken.replace('line: 334', 'line: 900'))
    for severity in ('WARNING', 'Warning'):
        payload = f'PHP {severity}: payload in /var/www/html/lib/file.php on line: 123'
        assert harness.normalize(payload) != harness.normalize(payload.replace('123', '124'))
    for gap in (' ', '  '):
        for plugin in ('', " in  Plugin 'fixture'"):
            actual = f'PHP USER_WARNING{plugin}: calibration in file: /harness/probe.php{gap}on line: 79'
            assert harness.normalize(actual) == harness.normalize(actual.replace('79', '80'))
    native = 'PHP Warning: actual diagnostic in /var/www/html/lib/file.php on line 123'
    assert harness.normalize(native) == harness.normalize(native.replace('123', '124'))
    cacti = 'PHP WARNING: payload in /var/www/html/lib/file.php on line: 123 in file: /var/www/html/lib/handler.php on line: 45'
    assert harness.normalize(cacti) != harness.normalize(cacti.replace('123', '124'))
    assert harness.normalize(cacti) == harness.normalize(cacti.replace('45', '46'))
    assert harness.normalize('ordinary DS[12] on line: 334') == 'ordinary DS[12] on line: 334'
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
    captured = object.__new__(harness.Harness)
    captured.command = lambda *args, **kwargs: {'stdout': ''}
    captured.php = lambda *args: {'exit': 7, 'stderr': 'fixture failure'}
    try:
        captured.capture_application_diagnostics()
    except RuntimeError as error:
        assert 'Application handler calibration failed' in str(error)
    else:
        raise AssertionError('failed calibration must reject the recording')
    print('diagnostic scopes preserve severity, content, order and duplicate records')


def poller_acknowledgement_contract():
    acknowledgement = 'OK u:0.01 s:0.02 r:0.03\n'
    other = 'statistics\nwarning containing OK u:0.01 s:0.02 r:0.03\n'
    def contract(stdout):
        return harness.poller_command_contract({'exit': 7, 'stdout': stdout, 'stderr': 'error\n'})
    before = contract(acknowledgement * 2 + other)
    after = contract(acknowledgement + other + acknowledgement)
    assert before == after
    assert contract(acknowledgement.replace('\n', '\r\n') + other)['rrd_acknowledgements'] == 1
    assert before == {'exit': 7, 'stdout': other, 'stderr': 'error\n', 'rrd_acknowledgements': 2}
    assert contract(acknowledgement + other) != before
    assert contract('OK u:broken s:0.02 r:0.03\n' + other)['rrd_acknowledgements'] == 0
    assert contract('OK u:0 s:0 r:0\n' + other)['rrd_acknowledgements'] == 1
    assert contract('OK u:1 s:0.2 r:3\n' + other)['rrd_acknowledgements'] == 1
    assert contract('line two\nline one\n')['stdout'] == 'line two\nline one\n'
    probe = object.__new__(harness.Harness)
    probe.observed = {}
    probe.capture('faults/missing-rrd-file', {'command': before})
    assert probe.observed['faults/missing-rrd-file']['command']['rrd_acknowledgements'] == 2
    assert probe.observed['faults/missing-rrd-file']['command']['stdout'] == other
    assert harness.normalize(other) == other
    assert harness.normalize('prefix OK u:1 s:2 r:3') == 'prefix OK u:1 s:2 r:3'
    assert harness.normalize('OK u:1 s:2 r:3 suffix') == 'OK u:1 s:2 r:3 suffix'
    assert harness.normalize('OK u:1 s:2 r:3\r\n') == 'OK u:<T> s:<T> r:<T>\r\n'
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


def provenance_contract():
    import tempfile
    import subprocess
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        fixture = root / 'tests/Support/Behavior/probe.php'
        fixture.parent.mkdir(parents=True)
        fixture.write_text('<?php echo 1;')
        def git_result(arguments, **kwargs):
            return {'stdout': status if 'status' in arguments else 'committed-harness-revision'}
        for status in ('', ' M tests/Support/Behavior/harness.py\n', '?? tests/Support/Behavior/harness.py\n'):
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', side_effect=git_result):
                provenance = harness.source_provenance()
            assert provenance['harness_revision'] == 'committed-harness-revision'
            assert provenance['harness_dirty'] is bool(status)
            assert provenance['application_dirty'] is bool(status)
            assert len(provenance['harness_sha256']) == 64
        fixture.write_text('<?php echo 2;')
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'run', side_effect=git_result):
            changed = harness.source_provenance()
        assert changed['harness_inputs_sha256'] != provenance['harness_inputs_sha256']
    with tempfile.TemporaryDirectory() as directory:
        parent = Path(directory)
        controller, application = parent / 'controller', parent / 'application'
        def git(root, *args):
            return subprocess.check_output(['git', '-C', str(root), '-c', 'user.name=Harness Fixture',
                                            '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                                            '-c', 'core.hooksPath=' + str(parent / 'no-hooks'), *args], text=True).strip()
        source = controller / 'tests/Support/Behavior/harness.py'
        for repo in (controller, application):
            repo.mkdir()
            git(repo, 'init', '-q')
            (repo / 'fixture').write_text(repo.name)
            if repo == controller:
                source.parent.mkdir(parents=True)
                source.write_text('# committed fixture controller')
            git(repo, 'add', '.')
            git(repo, 'commit', '-q', '-s', '-m', 'Create isolated provenance fixture')
        (application / 'untracked-input').write_text('changed application')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(source)):
            provenance = harness.source_provenance()
        assert provenance['harness_revision'] == git(controller, 'rev-parse', 'HEAD')
        assert provenance['harness_revision'] != git(application, 'rev-parse', 'HEAD')
        assert provenance['harness_dirty'] is False and provenance['application_dirty'] is True
        (application / 'untracked-input').unlink()
        source.write_text('# modified fixture controller')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(source)):
            provenance = harness.source_provenance()
        assert provenance['harness_dirty'] is True and provenance['application_dirty'] is False
        git(controller, 'restore', 'tests/Support/Behavior/harness.py')
        untracked = source.with_name('untracked.py')
        untracked.write_text('# untracked controller')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(untracked)):
            provenance = harness.source_provenance()
        assert provenance['harness_dirty'] is True and provenance['application_dirty'] is False
    evidence = ROOT / 'tests/behavior/evidence/historical-baseline'
    for path in (evidence / 'first.json', evidence / 'repeat.json'):
        recorded = json.loads(path.read_text())['provenance']
        assert recorded['harness_sha256'] == recorded['harness_inputs_sha256']['tests/Support/Behavior/harness.py']
    print('provenance records modified and untracked harnesses and hashes the actual fixture inputs')


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
    provenance_contract()
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
