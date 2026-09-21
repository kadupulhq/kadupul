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
import subprocess
import sys
import types
import tempfile
import uuid
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
spec = importlib.util.spec_from_file_location('harness', Path(__file__).with_name('harness.py'))
harness = importlib.util.module_from_spec(spec)
spec.loader.exec_module(harness)
CONTROLLER_INPUTS = harness.source_provenance()['harness_inputs_sha256']

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



def comparable_manifest():
    inputs = CONTROLLER_INPUTS
    return {'format': 2, 'target': 'fixture', 'error': None, 'complete': True, 'php': '8.2',
            'base_image': {'ref': 'php@sha256:' + '1' * 64, 'db_ref': 'mariadb@sha256:' + '2' * 64,
                           'packages': 'rrdtool=1.7', 'runtime': 'PHP 8.2; fixture Linux'},
            'application_images': {'web': 'sha256:' + '3' * 64, 'snmp': 'sha256:' + '3' * 64, 'db': 'sha256:' + '4' * 64},
            'revision': 'a' * 40, 'schema_sha256': 'b' * 64,
            'provenance': {'harness_revision': 'c' * 40, 'harness_sha256': inputs['tests/Support/Behavior/harness.py'],
                           'harness_dirty': False, 'application_dirty': False,
                           'harness_inputs_sha256': dict(inputs),
                           'application_inputs_sha256': dict(inputs)},
            'scenarios': {name: 1 for name in harness.EXPECTED_SCENARIOS}}


def application_image_contract():
    recorder = object.__new__(harness.Harness)
    recorder.compose = lambda *args: {'stdout': {'web': 'a', 'snmp': 'b', 'db': 'c'}[args[-1]] * 64}
    calls = []
    def inspect(args):
        calls.append(args)
        assert args[:5] == ['docker', 'container', 'inspect', '--format', '{{.Image}}']
        return {'stdout': 'sha256:' + args[-1]}
    with patch.object(harness, 'run', side_effect=inspect):
        assert recorder.application_image_digests() == {'web': 'sha256:' + 'a' * 64, 'snmp': 'sha256:' + 'b' * 64, 'db': 'sha256:' + 'c' * 64}
    assert len(calls) == 3
    for container, image in (('', 'sha256:' + 'a' * 64), ('a' * 64 + '\n' + 'b' * 64, 'sha256:' + 'a' * 64),
                             ('a' * 64, ''), ('a' * 64, 'php:mutable')):
        recorder.compose = lambda *args: {'stdout': container}
        with patch.object(harness, 'run', return_value={'stdout': image}):
            try:
                recorder.application_image_digests()
            except RuntimeError:
                pass
            else:
                raise AssertionError('Incomplete application image identity was accepted')
    # The same revision, dirty flags, helper hashes and observations cannot
    # certify a repeat when the built application content has changed.
    with tempfile.TemporaryDirectory(prefix='dirty application builds ') as directory:
        root = Path(directory)
        manifest = comparable_manifest()
        manifest['provenance']['application_dirty'] = True
        for role in ('baseline', 'candidate', 'repeat'):
            (root / role).mkdir()
            (root / role / 'observations.json').write_text(json.dumps(manifest))
        repeat = json.loads(json.dumps(manifest))
        repeat['application_images']['db'] = 'sha256:' + 'f' * 64
        (root / 'repeat/observations.json').write_text(json.dumps(repeat))
        args = types.SimpleNamespace(results_root=root, baseline='baseline', candidate='candidate',
                                     repeat=str(root / 'repeat/observations.json'), approvals=None, output=None)
        assert harness.compare(args) == 1
        differences = json.loads((root / 'comparison.json').read_text())['differences']
        assert all(row['status'] == 'NEEDS_REVIEW' for row in differences)
        recorder.args = types.SimpleNamespace(target='fixture', only=None, update_golden=True)
        recorder.observed = manifest['scenarios']
        recorder.destination = root / 'failed-capture'
        recorder.command = lambda *args, **kwargs: {'stdout': '8.2'}
        recorder.base_image_digest = lambda: manifest['base_image']
        recorder.compose = lambda *args: {'stdout': ''}
        (root / 'cacti.sql').write_text('schema')
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'a' * 40}), patch.object(harness, 'source_provenance', return_value=manifest['provenance']):
            assert recorder.finish() == 2
        failed = json.loads((recorder.destination / 'observations.json').read_text())
        assert failed['complete'] is False and failed['application_images'] is None
        assert 'Cannot identify application container' in failed['error']
        assert not (root / 'tests/Golden').exists()
    print('running image identity rejects different dirty application builds and incomplete inspection')


def application_input_boundary_contract():
    from unittest.mock import Mock
    for fault in ('missing-root', 'missing-schema', 'non-git', 'missing-helper', 'different-helper', 'different-build-input', 'extra-helper'):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory) / 'application'
            if fault != 'missing-root':
                root.mkdir()
            if fault not in ('missing-root', 'missing-schema'):
                (root / 'cacti.sql').write_text('schema')
            provenance = comparable_manifest()['provenance']
            if fault == 'extra-helper':
                provenance['application_inputs_sha256']['tests/Support/Behavior/obsolete.php'] = 'e' * 64
            if fault == 'missing-helper':
                provenance['application_inputs_sha256'].pop('tests/Support/Behavior/probe.php')
            if fault in ('different-helper', 'different-build-input'):
                key = 'tests/Support/Behavior/probe.php' if fault == 'different-helper' else '.dockerignore'
                provenance['application_inputs_sha256'][key] = 'f' * 64
            recorder = object.__new__(harness.Harness)
            recorder.setup_started = False
            recorder.compose = Mock(side_effect=AssertionError('Invalid inputs must not touch Docker'))
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'source_provenance', return_value=provenance), patch.object(harness, 'run', side_effect=RuntimeError('not a checkout') if fault == 'non-git' else None, return_value={'stdout': 'a' * 40}):
                try:
                    recorder.setup()
                except RuntimeError:
                    pass
                else:
                    raise AssertionError(('Invalid application inputs accepted', fault))
            assert recorder.setup_started is False
            recorder.compose.assert_not_called()
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        (root / 'cacti.sql').write_text('schema')
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'source_provenance', return_value=comparable_manifest()['provenance']), patch.object(harness, 'run', return_value={'stdout': 'a' * 40}):
            harness.validate_application_inputs()
    print('application input validation rejects missing and mismatched overlays before Docker')


def base_image_failure_contract():
    import subprocess
    valid = comparable_manifest()
    for key in ('ref', 'db_ref', 'packages', 'runtime'):
        for value in (None, '', '   ', 'unresolved') if key in ('ref', 'db_ref') else (None, '', '   '):
            with tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                (root / 'cacti.sql').write_text('schema')
                recorder = object.__new__(harness.Harness)
                recorder.args = types.SimpleNamespace(target='fixture', only=None, update_golden=True)
                recorder.destination = root / 'results'
                recorder.observed = valid['scenarios']
                recorder.command = lambda *a, **kw: {'stdout': '8.2', 'exit': 0}
                recorder.base_image_digest = lambda: {**valid['base_image'], key: value}
                recorder.application_image_digests = lambda: valid['application_images']
                with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'a' * 40}), patch.object(harness, 'source_provenance', return_value=valid['provenance']):
                    assert recorder.finish() == 2, (key, value)
                failed = json.loads((recorder.destination / 'observations.json').read_text())
                assert failed['complete'] is False
                assert 'base image provenance' in failed['error']
                assert not (root / 'tests/Golden').exists()
    # Exercise the real probes: nonzero exits with plausible stdout must fail.
    for failed_probe in range(5):
        recorder = object.__new__(harness.Harness)
        recorder.command = lambda *args, **kw: harness.run(list(args), **kw)
        responses = [subprocess.CompletedProcess([], 0, output, '') for output in
                     ('NAME=Debian', 'PHP 8.2', valid['base_image']['ref'], valid['base_image']['db_ref'], 'rrdtool=1.7')]
        responses[failed_probe].returncode = 1
        with patch.object(harness.subprocess, 'run', side_effect=responses):
            try:
                recorder.base_image_digest()
            except RuntimeError:
                pass
            else:
                raise AssertionError(('Failed probe accepted', failed_probe))
    for os_output, php_output in (('', 'PHP 8.2'), ('NAME=Debian', ''), ('NAME=Debian', 'command unavailable')):
        recorder = object.__new__(harness.Harness)
        recorder.command = lambda *args, **kwargs: {'stdout': os_output if args[0] == 'cat' else php_output}
        try:
            recorder.base_image_digest()
        except RuntimeError as error:
            assert 'runtime provenance' in str(error)
        else:
            raise AssertionError('Missing runtime identification accepted')
    print('invalid base image metadata fails capture without writing goldens')


def bootstrap_repeat_provenance():
    """A clean checkout bootstrap must record the state seen by its repeat."""
    import subprocess
    with tempfile.TemporaryDirectory(prefix='bootstrap provenance ') as directory:
        root = Path(directory)
        for name in harness.REQUIRED_INPUTS:
            path = root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('fixture ' + name)
        (root / 'cacti.sql').write_text('fixture schema')
        def git(*args):
            harness.run(['git', '-C', str(root), '-c', 'user.name=Harness Fixture',
                            '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                            '-c', 'core.hooksPath=' + str(root / 'no-hooks'), *args])
        git('init', '-q')
        git('add', '.')
        git('commit', '-q', '-s', '-m', 'Create bootstrap provenance fixture')
        recorder = object.__new__(harness.Harness)
        recorder.args = types.SimpleNamespace(target='fixture', only=None, update_golden=True, bootstrap_goldens=True)
        recorder.destination = root / 'tests/behavior/results/first'
        recorder.observed = comparable_manifest()['scenarios']
        recorder.command = lambda *a, **kw: {'stdout': '8.2', 'stderr': '', 'exit': 0}
        recorder.base_image_digest = lambda: comparable_manifest()['base_image']
        recorder.application_image_digests = lambda: comparable_manifest()['application_images']
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, '__file__', str(root / 'tests/Support/Behavior/harness.py')):
            assert harness.source_provenance()['application_dirty'] is False
            assert recorder.finish() == 0
            first = json.loads((recorder.destination / 'observations.json').read_text())
            assert first['provenance']['application_dirty'] is True
            assert first['provenance']['harness_dirty'] is True
            recorder.destination = root / 'tests/behavior/results/repeat'
            recorder.args.update_golden = False
            assert recorder.finish() == 0
            repeat = json.loads((recorder.destination / 'observations.json').read_text())
            assert first == repeat
            assert harness.compare(types.SimpleNamespace(results_root=root / 'tests/behavior/results',
                baseline='first', candidate='repeat', repeat=None, approvals=None, output=None)) == 0
            recorder.args.update_golden = True
            before = {p: p.read_bytes() for p in (root / 'tests/Golden').rglob('*.json')}
            recorder.observed = {name: {'changed': True} for name in harness.EXPECTED_SCENARIOS}
            with patch.object(harness, 'source_provenance', side_effect=[first['provenance'], OSError('final probe failed')]):
                assert recorder.finish() == 2
            assert {p: p.read_bytes() for p in (root / 'tests/Golden').rglob('*.json')} == before
            write_json = harness.write_json
            written = []
            def failing_write(path, value):
                if 'tests/Golden' in str(path):
                    written.append(path)
                    if len(written) == 3:
                        path.parent.mkdir(parents=True, exist_ok=True)
                        path.write_text('{"trunc')
                        raise OSError('disk full')
                write_json(path, value)
            with patch.object(harness, 'source_provenance', return_value=first['provenance']), patch.object(harness, 'write_json', failing_write):
                assert recorder.finish() == 2
            assert len(written) == 3
            assert {p: p.read_bytes() for p in (root / 'tests/Golden').rglob('*.json')} == before
            failed = json.loads((recorder.destination / 'observations.json').read_text())
            assert failed['complete'] is False and 'disk full' in failed['error']
            recorder.args.target = 'partial-bootstrap'
            written.clear()
            with patch.object(harness, 'source_provenance', return_value=first['provenance']), patch.object(harness, 'write_json', failing_write):
                assert recorder.finish() == 2
            assert not (root / 'tests/Golden/partial-bootstrap').exists()
            recorder.args.target = 'failed-bootstrap'
            with patch.object(harness, 'source_provenance', side_effect=[first['provenance'], OSError('final probe failed')]):
                assert recorder.finish() == 2
            assert not (root / 'tests/Golden/failed-bootstrap').exists()
            failed = json.loads((recorder.destination / 'observations.json').read_text())
            assert failed['complete'] is False and 'final source provenance' in failed['error']
    print('clean bootstrap and repeat preserve identical final provenance; final probe failures fail closed')


def separate_results_root():
    """Compare real capture files outside the controller, including repeat checks."""
    with tempfile.TemporaryDirectory(prefix='harness separate results ') as directory:
        results = Path(directory)
        manifest = comparable_manifest()
        for label in ('baseline', 'candidate', 'repeat'):
            (results / label).mkdir()
            (results / label / 'observations.json').write_text(json.dumps(manifest))
        command = ['harness', 'compare', '--results-root', str(results), '--baseline', 'baseline',
                   '--candidate', 'candidate', '--repeat', str(results / 'repeat/observations.json')]
        with patch('sys.argv', command):
            assert harness.main() == 0
        report = json.loads((results / 'comparison.json').read_text())
        assert report['differences'][0]['status'] == 'IDENTICAL'
        import hashlib
        assert report['contracts'] == len(harness.EXPECTED_SCENARIOS)
        for role in ('baseline', 'candidate', 'repeat'):
            assert report['manifest_sha256'][role] == hashlib.sha256((results / role / 'observations.json').read_bytes()).hexdigest()
            assert report['captures'][role]['provenance'] == manifest['provenance']
        assert report['controller']['harness_sha256'] == hashlib.sha256(Path(harness.__file__).read_bytes()).hexdigest()
        for key, changed in (('revision', 'f' * 40), ('schema_sha256', 'f' * 64),
                             ('application_images', {'web': 'sha256:' + 'f' * 64, 'snmp': 'sha256:' + '3' * 64, 'db': 'sha256:' + '4' * 64}),
                             ('php', '8.3'), ('base_image', {**manifest['base_image'], 'ref': 'php@sha256:' + 'f' * 64}),
                             ('provenance', {**manifest['provenance'], 'application_dirty': True})):
            broken = {**manifest, key: changed, 'scenarios': {**manifest['scenarios'], sorted(harness.EXPECTED_SCENARIOS)[0]: 2}}
            (results / 'repeat/observations.json').write_text(json.dumps(broken))
            with patch('sys.argv', command):
                assert harness.main() == 1, key
            mismatch = json.loads((results / 'comparison.json').read_text())
            assert any(row['scenario'] == '<repeat-environment>/' + key and row['status'] == 'NEEDS_REVIEW'
                       for row in mismatch['differences']), key
            assert all(row['status'] == 'NEEDS_REVIEW' for row in mismatch['differences']), key
        for key in ('harness_sha256', 'harness_inputs_sha256'):
            different = json.loads(json.dumps(manifest))
            if key == 'harness_sha256':
                different['provenance'][key] = 'f' * 64
                different['provenance']['harness_inputs_sha256']['tests/Support/Behavior/harness.py'] = 'f' * 64
            else:
                different['provenance'][key]['tests/Support/Behavior/probe.php'] = 'f' * 64
            different['provenance']['application_inputs_sha256'] = dict(different['provenance']['harness_inputs_sha256'])
            (results / 'baseline/observations.json').write_text(json.dumps(different))
            (results / 'repeat/observations.json').write_text(json.dumps(manifest))
            with patch('sys.argv', command):
                assert harness.main() == 1
            mismatch = json.loads((results / 'comparison.json').read_text())
            assert any(row['scenario'] == '<environment>/' + key and row['status'] == 'NEEDS_REVIEW' for row in mismatch['differences'])
        # Mutually consistent old captures must not pass under a changed controller.
        for role in ('baseline', 'candidate', 'repeat'):
            (results / role / 'observations.json').write_text(json.dumps(different))
        with patch('sys.argv', command):
            assert harness.main() == 1
        stale = json.loads((results / 'comparison.json').read_text())
        assert all(row['status'] == 'NEEDS_REVIEW' for row in stale['differences'])
        assert any(row['scenario'].startswith('<controller>/') for row in stale['differences'])
        for role in ('baseline', 'candidate', 'repeat'):
            (results / role / 'observations.json').write_text(json.dumps(manifest))
        manifest['scenarios'][sorted(harness.EXPECTED_SCENARIOS)[0]] = 2
        (results / 'candidate/observations.json').write_text(json.dumps(manifest))
        with patch('sys.argv', command):
            assert harness.main() == 1
        assert json.loads((results / 'comparison.json').read_text())['differences'][0]['status'] == 'NONDETERMINISTIC'


def comparison_inventory_failure():
    """A complete flag cannot make partial or malformed evidence comparable."""
    with tempfile.TemporaryDirectory(prefix='harness invalid inventory ') as directory:
        results = Path(directory)
        valid = comparable_manifest()
        paths = {}
        for role in ('baseline', 'candidate', 'repeat'):
            paths[role] = results / role / 'observations.json'
            paths[role].parent.mkdir()
            paths[role].write_text(json.dumps(valid))
        args = types.SimpleNamespace(results_root=results, baseline='baseline', candidate='candidate',
                                     repeat=str(paths['repeat']), approvals=None, output=None)
        for role, path in paths.items():
            for fault in ('missing', 'unexpected', 'empty', 'wrong-type', 'false-complete',
                          'revision', 'schema_sha256', 'provenance', 'harness-hash', 'dirty-type', 'input-hash', 'dockerignore', 'cross-map-mismatch',
                          'format', 'format-boolean', 'format-old', 'application_images', 'image-service-missing', 'image-id-invalid', 'target', 'php', 'php-invalid', 'base_image',
                          'image-unpinned', 'packages', 'runtime', 'error', 'error-present', 'inventory-missing', 'hash-mismatch') + tuple(
                              key + ':' + name for key in ('harness_inputs_sha256', 'application_inputs_sha256')
                              for name in sorted(harness.REQUIRED_INPUTS)):
                broken = json.loads(json.dumps(valid))
                if fault == 'missing':
                    broken['scenarios'].pop(sorted(harness.EXPECTED_SCENARIOS)[0])
                elif fault == 'unexpected':
                    broken['scenarios']['unrecognized/extra'] = 1
                elif fault == 'empty':
                    broken['scenarios'] = {}
                elif fault == 'wrong-type':
                    broken['scenarios'] = list(harness.EXPECTED_SCENARIOS)
                elif fault == 'false-complete':
                    broken['complete'] = 'true'
                elif fault in ('revision', 'schema_sha256', 'provenance'):
                    broken.pop(fault)
                elif fault == 'harness-hash':
                    broken['provenance']['harness_sha256'] = 'not-a-hash'
                elif fault == 'dirty-type':
                    broken['provenance']['application_dirty'] = 'false'
                elif fault == 'input-hash':
                    broken['provenance']['application_inputs_sha256']['.dockerignore'] = 'invalid'
                elif fault == 'dockerignore':
                    broken['provenance']['harness_inputs_sha256'].pop('.dockerignore')
                elif fault == 'cross-map-mismatch':
                    broken['provenance']['application_inputs_sha256']['.dockerignore'] = 'f' * 64
                elif fault in ('format', 'application_images', 'target', 'php', 'base_image', 'error'):
                    broken.pop(fault)
                elif fault == 'format-old':
                    broken['format'] = 1
                elif fault == 'image-service-missing':
                    broken['application_images'].pop('snmp')
                elif fault == 'image-id-invalid':
                    broken['application_images']['web'] = 'mutable:tag'
                elif fault == 'format-boolean':
                    broken['format'] = True
                elif fault == 'php-invalid':
                    broken['php'] = 'unknown'
                elif fault == 'image-unpinned':
                    broken['base_image']['ref'] = 'php:latest'
                elif fault in ('packages', 'runtime'):
                    broken['base_image'].pop(fault)
                elif fault == 'error-present':
                    broken['error'] = 'capture failed'
                elif fault == 'inventory-missing':
                    broken['inventory_missing'] = ['api/missing']
                elif fault == 'hash-mismatch':
                    broken['provenance']['harness_inputs_sha256']['tests/Support/Behavior/harness.py'] = 'f' * 64
                else:
                    key, name = fault.split(':', 1)
                    broken['provenance'][key].pop(name)
                path.write_text(json.dumps(broken))
                try:
                    harness.compare(args)
                except RuntimeError as error:
                    assert role in str(error), str(error)
                else:
                    raise AssertionError(f'Accepted {role} with {fault} evidence')
                assert not (results / 'comparison.json').exists()
                path.write_text(json.dumps(valid))


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
                {**comparable_manifest(), 'complete': complete}))
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



def retained_controller_inputs():
    # Historical evidence belongs to its captured controller, not today's build.
    # Keep exact source bytes so shallow CI checkouts can verify provenance.
    import hashlib
    import zipfile
    evidence = ROOT / 'tests/behavior/evidence/historical-baseline'
    with zipfile.ZipFile(evidence / 'controller-inputs.zip') as archive:
        names = archive.namelist()
        assert len(names) == len(set(names)), 'Duplicate retained controller inputs'
        actual = {name: hashlib.sha256(archive.read(name)).hexdigest() for name in names}
    for label in ('first', 'repeat'):
        path = evidence / (label + '.json')
        recorded = json.loads(path.read_text())['provenance']['harness_inputs_sha256']
        changed = sorted(key for key in set(actual) | set(recorded) if actual.get(key) != recorded.get(key))
        assert not changed, 'Historical controller archive differs from captured inputs: ' + ', '.join(changed)
    print('retained baseline evidence matches its archived controller inputs; current builds require matching-provenance captures')


def separate_application_outputs():
    with tempfile.TemporaryDirectory(prefix='controller artifacts ') as directory:
        controller = Path(directory) / 'controller'
        application = Path(directory) / 'application'
        application.mkdir()
        (application / 'cacti.sql').write_text('schema')
        args = types.SimpleNamespace(target='fixture', only=None, update_golden=True,
                                     project='artifact-routing-' + Path(directory).name.replace(' ', '-'))
        with patch.object(harness, 'ROOT', application), patch.object(harness, 'CONTROLLER_ROOT', controller), patch.object(harness, 'run', return_value={'stdout': 'a' * 40}), patch.object(harness, 'source_provenance', return_value=comparable_manifest()['provenance']):
            recorder = harness.Harness(args)
            try:
                assert recorder.destination == controller / 'tests/behavior/results/fixture'
                assert recorder.dc[-1] == str(application / 'tests/behavior/compose.yml')
                recorder.observed = comparable_manifest()['scenarios']
                recorder.command = lambda *a, **kw: {'stdout': '8.2', 'stderr': '', 'exit': 0}
                recorder.base_image_digest = lambda: comparable_manifest()['base_image']
                recorder.application_image_digests = lambda: comparable_manifest()['application_images']
                golden = controller / 'tests/Golden/fixture/php-8.2'
                harness.write_json(golden / 'removed.json', {})
                assert recorder.finish() == 2, 'Application mode must validate existing controller goldens'
                (golden / 'removed.json').unlink()
                for name, value in recorder.observed.items():
                    harness.write_json(golden / (name + '.json'), value)
                assert recorder.finish() == 0
                assert not (application / 'tests').exists(), 'Artifacts must not modify the application checkout'
                assert harness.compare(types.SimpleNamespace(results_root=None, baseline='fixture',
                    candidate='fixture', repeat=None, approvals=None, output=None)) == 0
            finally:
                recorder.lock.close()
    print('separate applications preserve controller golden checks and default comparison paths')


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
            recorder.base_image_digest = lambda: comparable_manifest()['base_image']
            recorder.application_image_digests = lambda: comparable_manifest()['application_images']
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
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
                status = recorder.finish()
            manifest = json.loads((recorder.destination / 'observations.json').read_text())
            assert (status == 0) == (case in ('complete', 'other-runtime-complete')), case
            assert manifest['complete'] == (case in ('complete', 'other-runtime-complete')), case
            if manifest['complete']:
                assert 'inventory_missing' not in manifest, 'Successful captures have no missing-inventory detail'
            if case not in ('complete', 'other-runtime-complete'):
                assert {str(p): p.read_bytes() for p in golden.parent.rglob('*.json')} == before, case
            else:
                assert len(list(golden.rglob('*.json'))) == len(harness.EXPECTED_SCENARIOS)
        recorder.args = types.SimpleNamespace(target='absent-runtime', only=None, update_golden=False)
        recorder.destination = root / 'results/absent-runtime'
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
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
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', return_value={'stdout': 'revision'}):
            with patch('builtins.print') as output:
                assert recorder.finish() == 2, 'Partial bootstrap must not report successful verification'
            output.assert_called_once_with(
                'Incomplete capture; runtime goldens are missing observations: php-8.3/' + names[-1],
                file=sys.stderr)
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
    tracked = harness.run(['git', '-C', str(ROOT), 'ls-files', 'tests/Golden/*/php-*/diagnostics/application-log.json'])['stdout'].splitlines()
    assert tracked, 'No committed diagnostic goldens'
    for name in tracked:
        golden = ROOT / name
        records = json.loads(golden.read_text())
        assert records == sorted(records, key=lambda row: (row['subsystem'], row['message'])), golden
        fixture = '\n'.join('09/17/2026 01:02:03 - ' + row['subsystem'] + ' ' + row['message']
                            for row in reversed(records))
        recorder = object.__new__(harness.Harness)
        recorder.observed = {}
        recorder.capture('diagnostics/application-log', harness.application_diagnostics(fixture))
        assert recorder.observed['diagnostics/application-log'] == records, golden
    for manifest in (ROOT / 'tests/behavior/evidence/historical-baseline').glob('*json'):
        data = json.loads(manifest.read_text())
        if 'scenarios' not in data:
            continue
        for name, value in data['scenarios'].items():
            golden = ROOT / 'tests/Golden' / data['target'] / ('php-' + data['php']) / (name + '.json')
            assert json.loads(golden.read_text()) == value, (manifest, name)
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
        {'subsystem': 'ERROR', 'message': 'PHP NOTICE: second'},
        {'subsystem': 'ERROR', 'message': 'PHP WARNING: first 2020-01-01 in <APP>/lib/x.php:42'}]
    assert harness.application_diagnostics(log + log) == sorted(harness.application_diagnostics(log) * 2, key=lambda row: (row['subsystem'], row['message']))
    assert harness.application_diagnostics('\n'.join(reversed(log.splitlines()))) == harness.application_diagnostics(log)
    for payload in ('/harnessed', '/var/www/htmlish', '/harnessed/file.php',
                    '/var/www/htmlish/file.php', '/tmp/harness/file.php',
                    'prefix/var/www/html/file.php'):
        warning = 'PHP WARNING: payload ' + payload
        assert harness.normalize(warning) == warning
        assert harness.application_diagnostics('09/16/2026 01:02:06 - ERROR ' + warning) == [
            {'subsystem': 'ERROR', 'message': warning}]
    assert harness.normalize_known_roots('/harness') == '<HARNESS>'
    assert harness.normalize_known_roots('/var/www/html') == '<APP>'
    assert harness.normalize_known_roots('file /harness/probe.php') == 'file <HARNESS>/probe.php'
    assert harness.normalize_known_roots('file /var/www/html/index.php') == 'file <APP>/index.php'
    for newline in ('\n', '\r\n'):
        value = newline.join(('a /var/www/html', '/harness', '/harnessed', 'prefix/var/www/html', 'b'))
        expected = newline.join(('a <APP>', '<HARNESS>', '/harnessed', 'prefix/var/www/html', 'b'))
        assert harness.normalize_known_roots(value) == expected

    recorder = harness.Harness.__new__(harness.Harness)
    paths = ['/var/www/html/rra/a.rrd', '/var/www/htmlish/a.rrd', '/tmp/var/www/html/a.rrd']
    def poller_rows(query):
        assert 'REPLACE(' not in query, 'SQL must preserve raw path boundaries'
        return [{'rrd_path': path} for path in paths] if 'FROM poller_item' in query else []
    recorder.rows = poller_rows
    recorder.sql = lambda query: '0'
    assert [item['rrd_path'] for item in recorder.poller_state()['poller_item']] == [
        '<APP>/rra/a.rrd', paths[1], paths[2]]

    timing_warning = 'PHP WARNING: OK u:1.23 s:2.34 r:3.45 SYSTEM STATS: Time:1.23 in /harness/probe.php:7'
    assert harness.normalize('PHP WARNING: payload SYSTEM STATS: Time:1.23') == 'PHP WARNING: payload SYSTEM STATS: Time:1.23'
    assert harness.normalize('SYSTEM STATS: Time:1.23 DataSources:5') == 'SYSTEM STATS: Time:<T> DataSources:5'
    assert harness.normalize('09/16/2026 01:02:05 - SYSTEM STATS: Time:1.23 DataSources:5') == '<TIMESTAMP> - SYSTEM STATS: Time:<T> DataSources:5'
    assert harness.application_diagnostics('09/16/2026 01:02:06 - ERROR ' + timing_warning) == [
        {'subsystem': 'ERROR', 'message': timing_warning.replace('/harness', '<HARNESS>')}]

    for size in (108, 4356):
        broken = f'09/16/2026 01:02:06 - ERROR PHP NOTICE: fwrite(): Write of {size} bytes failed with errno=32 Broken pipe in file: /var/www/html/lib/rrd.php on line: 334'
        assert harness.application_diagnostics(broken) == [{'subsystem': 'ERROR', 'message': 'PHP NOTICE: fwrite(): Write of <BYTES> bytes failed with errno=32 Broken pipe in file: <APP>/lib/rrd.php on line: <LINE>'}]
        native = broken.replace('PHP NOTICE: fwrite', 'PHP Notice:  fwrite').replace(' in file: ', ' in ').replace('on line: ', 'on line ')
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
        assert harness.normalize_failed_write_size(diagnostic) == diagnostic
        assert harness.normalize_failed_write_size(diagnostic + '\n' + broken).count('<BYTES>') == 1
    trace = '09/16/2026 01:02:06 - CMDPHP PHP ERROR Backtrace: (/var/www/html/lib/rrd.php[334]:update(), DS[12])'
    assert harness.application_diagnostics(trace) == harness.application_diagnostics(trace.replace('[334]', '[900]'))
    assert 'DS[12]' in harness.application_diagnostics(trace)[0]['message']
    multiline = ('09/16/2026 01:02:06 - ERROR PHP WARNING: first part in file: /var/www/html/lib/x.php  on line: 42\n'
                 'continued /var/www/html/lib/y.php\n'
                 '09/16/2026 01:02:07 - SYSTEM STATS: Time:1\n'
                 'not part of a diagnostic\n')
    assert harness.application_diagnostics(multiline) == [
        {'subsystem': 'ERROR', 'message': 'PHP WARNING: first part in file: <APP>/lib/x.php  on line: <LINE>\ncontinued <APP>/lib/y.php'}]
    assert harness.application_diagnostics('orphan continuation\n' + multiline) == harness.application_diagnostics(multiline)
    poller = '09/16/2026 01:02:06 - POLLER: Poller[1] PID[{}] PHP WARNING: late in file: /var/www/html/poller.php  on line: 90'
    assert harness.application_diagnostics(poller.format(123)) == harness.application_diagnostics(poller.format(456)) == [
        {'subsystem': 'POLLER', 'message': 'PHP WARNING: late in file: <APP>/poller.php  on line: <LINE>'}]
    unknown = '09/16/2026 01:02:06 - lower-case prefix PHP WARNING: kept /var/www/html/x.php'
    assert harness.application_diagnostics(unknown) == [
        {'subsystem': '<UNPARSED>', 'message': 'lower-case prefix PHP WARNING: kept <APP>/x.php'}]
    assert harness.application_diagnostics('09/16/2026 01:02:06 - POLLER: Poller[1] PID[7] Time:1') == []
    for severity in ('ERROR', 'WARNING', 'NOTICE', 'DEPRECATED', 'USER_WARNING', 'USER_NOTICE', 'USER_ERROR', 'USER_DEPRECATED', 'STRICT', 'PARSE', 'CORE_ERROR', 'CORE_WARNING', 'COMPILE_ERROR', 'COMPILE_WARNING', 'RECOVERABLE_ERROR', 'ALL', 'Unknown Error'):
        diagnostic = f'PHP {severity}: calibration in file: /harness/probe.php on line: 79'
        assert harness.normalize_php_locations(diagnostic) == diagnostic
        assert harness.normalize_php_locations('09/16/2026 01:02:06 - ERROR ' + diagnostic).endswith('on line: <LINE>')
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
            actual = f'09/16/2026 01:02:06 - ERROR PHP USER_WARNING{plugin}: calibration in file: /harness/probe.php{gap}on line: 79'
            assert harness.normalize(actual) == harness.normalize(actual.replace('79', '80'))
    native = 'PHP Warning: actual diagnostic in /var/www/html/lib/file.php on line 123'
    assert harness.normalize(native) == harness.normalize(native.replace('123', '124'))
    cacti = '09/16/2026 01:02:06 - ERROR PHP WARNING: payload in /var/www/html/lib/file.php on line: 123 in file: /var/www/html/lib/handler.php on line: 45'
    assert harness.normalize(cacti) != harness.normalize(cacti.replace('123', '124'))
    assert harness.normalize(cacti) == harness.normalize(cacti.replace('45', '46'))
    for payload in ('PHP WARNING: payload in file: /harness/probe.php on line: 79',
                    'quoted PHP Warning: payload in /harness/probe.php on line 79'):
        assert harness.normalize_php_locations(payload) == payload
        assert harness.normalize(payload) != harness.normalize(payload.replace('79', '80'))
    wrapped = '09/16/2026 01:02:06 - ERROR PHP WARNING: payload in file: /harness/probe.php on line: 79 in file: /harness/handler.php on line: 90'
    assert harness.normalize(wrapped) != harness.normalize(wrapped.replace('79', '80'))
    assert harness.normalize(wrapped) == harness.normalize(wrapped.replace('90', '91'))
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


def source_provenance_failure_contract():
    import tempfile
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        (root / 'cacti.sql').write_text('schema')
        for failure in ('not-git', 'provenance'):
            recorder = object.__new__(harness.Harness)
            recorder.args = types.SimpleNamespace(target=failure, only=None, update_golden=True)
            recorder.destination = root / 'results' / failure
            recorder.observed = {name: {'value': name} for name in harness.EXPECTED_SCENARIOS}
            recorder.command = lambda *a, **kw: {'stdout': '8.2', 'stderr': '', 'exit': 0}
            recorder.base_image_digest = lambda: comparable_manifest()['base_image']
            recorder.application_image_digests = lambda: comparable_manifest()['application_images']
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root):
                if failure == 'not-git':
                    assert recorder.finish() == 2
                else:
                    with patch.object(harness, 'run', return_value={'stdout': 'revision'}), patch.object(harness, 'source_provenance', side_effect=OSError('unreadable source')):
                        assert recorder.finish() == 2
            manifest = json.loads((recorder.destination / 'observations.json').read_text())
            assert manifest['complete'] is False
            assert 'Cannot record source provenance:' in manifest['error']
            assert manifest['provenance'] is None
            assert not (root / 'tests/Golden' / failure).exists()
    print('source provenance failures retain an incomplete manifest without writing goldens')


def provenance_contract():
    import tempfile
    import subprocess
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        fixture = root / 'tests/Support/Behavior/probe.php'
        fixture.parent.mkdir(parents=True)
        fixture.write_text('<?php echo 1;')
        (root / '.dockerignore').write_text('cache/')
        def git_result(arguments, **kwargs):
            return {'stdout': status if 'status' in arguments else 'committed-harness-revision'}
        for status in ('', ' M tests/Support/Behavior/harness.py\n', '?? tests/Support/Behavior/harness.py\n'):
            with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', side_effect=git_result):
                provenance = harness.source_provenance()
            assert provenance['harness_revision'] == 'committed-harness-revision'
            assert provenance['harness_dirty'] is bool(status)
            assert provenance['application_dirty'] is bool(status)
            assert len(provenance['harness_sha256']) == 64
        (root / '.dockerignore').write_text('cache/\nlog/')
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', side_effect=git_result):
            ignored = harness.source_provenance()
        assert ignored['application_inputs_sha256']['.dockerignore'] != provenance['application_inputs_sha256']['.dockerignore']
        assert ignored['harness_inputs_sha256'] == provenance['harness_inputs_sha256']
        fixture.write_text('<?php echo 2;')
        with patch.object(harness, 'ROOT', root), patch.object(harness, 'CONTROLLER_ROOT', root), patch.object(harness, 'run', side_effect=git_result):
            changed = harness.source_provenance()
        assert changed['application_inputs_sha256'] != provenance['application_inputs_sha256']
        assert changed['harness_inputs_sha256'] == provenance['harness_inputs_sha256']
    with tempfile.TemporaryDirectory() as directory:
        parent = Path(directory)
        controller, application = parent / 'controller', parent / 'application'
        def git(root, *args):
            return harness.run(['git', '-C', str(root), '-c', 'user.name=Harness Fixture',
                                            '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                                            '-c', 'core.hooksPath=' + str(parent / 'no-hooks'), *args])['stdout'].strip()
        source = controller / 'tests/Support/Behavior/harness.py'
        for repo in (controller, application):
            repo.mkdir()
            git(repo, 'init', '-q')
            (repo / 'fixture').write_text(repo.name)
            fixture_source = repo / 'tests/Support/Behavior/harness.py'
            fixture_source.parent.mkdir(parents=True)
            fixture_source.write_text('# committed fixture ' + repo.name)
            git(repo, 'add', '.')
            git(repo, 'commit', '-q', '-s', '-m', 'Create isolated provenance fixture')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(source)):
            clean = harness.source_provenance()
        import hashlib
        key = 'tests/Support/Behavior/harness.py'
        assert clean['harness_inputs_sha256'][key] == hashlib.sha256(source.read_bytes()).hexdigest()
        assert clean['application_inputs_sha256'][key] == hashlib.sha256((application / key).read_bytes()).hexdigest()
        assert clean['harness_inputs_sha256'][key] != clean['application_inputs_sha256'][key]
        for repo in (controller, application):
            output = repo / 'tests/behavior/results/repeat/observations.json'
            output.parent.mkdir(parents=True)
            output.write_text('{}')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(source)):
            assert harness.source_provenance() == clean, 'Generated results changed source provenance'
        # Similar directory names are source inputs, not generated outputs.
        adjacent = application / 'tests/behavior/results-source.php'
        adjacent.write_text('<?php')
        with patch.object(harness, 'ROOT', application), patch.object(harness, '__file__', str(source)):
            assert harness.source_provenance()['application_dirty'] is True
        adjacent.unlink()
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
    separate_application_outputs()
    source_provenance_failure_contract()
    provenance_contract()
    application_input_boundary_contract()
    base_image_failure_contract()
    application_image_contract()
    diagnostic_contracts()
    retained_controller_inputs()
    failed_setup_manifest()
    unavailable_docker_failure()
    print('setup failure records an incomplete manifest without probing containers')

    comparison_inventory_failure()
    print("compare rejects missing, unexpected and malformed inventories for every role")
    separate_results_root()
    bootstrap_repeat_provenance()
    print('compare honors a separate results directory and repeat control')
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
