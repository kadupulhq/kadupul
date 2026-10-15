"""Self-tests for the harness's own normalization.

The goldens are only as trustworthy as what normalize() leaves alone. A pattern
that is too greedy erases a real contract, and nothing downstream notices,
because the golden and the observation are normalized the same way.

Run with: python tests/Support/Behavior/selftest.py
"""
import hashlib
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
    ('poller warning clock', 'Total[0.0218] WARNING: Poller Output Table not Empty.  Issues: 5, DS[1, 2]',
     'Total[<T>] WARNING: Poller Output Table not Empty.  Issues: 5, DS[1, 2]'),
    ('unrelated total', 'Total[0.0218] user value', 'Total[0.0218] user value'),
    ('embedded warning clock', 'PHP WARNING: Total[0.0218] WARNING: Poller Output Table not Empty.  Issues: 5, DS[1]',
     'PHP WARNING: Total[0.0218] WARNING: Poller Output Table not Empty.  Issues: 5, DS[1]'),

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
        recorder.compose = lambda *args: {'stdout': 'a' * 64}
        recorder.command = lambda *args, **kw: harness.run(list(args), **kw)
        responses = [subprocess.CompletedProcess([], 0, output, '') for output in
                     ('NAME=Debian', 'PHP 8.2', 'php:8.2-apache@sha256:' + '1' * 64, valid['base_image']['db_ref'], 'rrdtool=1.7')]
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


def pinned_base_image_contract():
    recorder = object.__new__(harness.Harness)
    recorder.compose = lambda *args: {'stdout': 'a' * 64}
    recorder.command = lambda *args, **kwargs: {
        'stdout': 'NAME=Debian' if args[0] == 'cat' else 'PHP 8.2' if args[0] == 'php' else 'rrdtool=1.7'}
    for version in ('8.1', '8.2', '8.3', '8.4'):
        reference = 'php:' + version + '-apache@sha256:' + '1' * 64
        def inspect(args, **kwargs):
            if args[1] == 'inspect':
                assert args[-1] == 'a' * 64
                if args[3] == '{{.Image}}':
                    return {'stdout': 'sha256:' + '3' * 64}
                assert args[3] == '{{index .Config.Labels "org.kadupul.behavior.php-base"}}'
                return {'stdout': reference}
            assert args[-1] == 'sha256:' + '3' * 64
            return {'stdout': 'mariadb@sha256:' + '2' * 64}
        with patch.object(harness, 'run', side_effect=inspect):
            assert recorder.base_image_digest()['ref'] == reference
    for missing in ('container', 'image', 'digest'):
        recorder.compose = lambda *args: {'stdout': '' if args[-1] == 'db' and missing == 'container' else 'a' * 64}
        def incomplete_database(args, **kwargs):
            if args[1] == 'inspect':
                if args[3] == '{{.Image}}':
                    return {'stdout': '' if missing == 'image' else 'sha256:' + '3' * 64}
                return {'stdout': 'php:8.4-apache@sha256:' + '1' * 64}
            return {'stdout': '' if missing == 'digest' else 'mariadb@sha256:' + '2' * 64}
        with patch.object(harness, 'run', side_effect=incomplete_database):
            try:
                recorder.base_image_digest()
            except RuntimeError as error:
                assert 'database' in str(error)
            else:
                raise AssertionError('Incomplete running database provenance accepted')
    recorder.compose = lambda *args: {'stdout': 'a' * 64}
    for reference in ('', '<no value>', 'php:8.2-apache', 'php:8.2-apache@sha256:short'):
        with patch.object(harness, 'run', return_value={'stdout': reference}):
            try:
                recorder.base_image_digest()
            except RuntimeError as error:
                assert 'pinned PHP base provenance' in str(error)
            else:
                raise AssertionError('Missing or mutable PHP base provenance accepted')
    print('PHP base provenance uses the pinned label on the running container')


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
    probe = subprocess.run(
        ['php', '-d', 'auto_prepend_file=', '-r',
         'exit(function_exists("posix_setsid") && function_exists("posix_getpgrp") && '
         'function_exists("posix_kill") && function_exists("proc_open") && '
         'is_readable("/proc/self/stat") ? 0 : 70);'],
        capture_output=True, text=True, timeout=10)
    if probe.returncode != 0:
        raise RuntimeError('Linux worker-boundary validation requires PHP POSIX/process functions and readable /proc')
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
        for descendant, known_script in ((False, False), (True, False), (False, True), (True, True)):
            worker = Path(directory) / ('poller_boost.php' if known_script else 'late-worker.php')
            parent = Path(directory) / ('poller.php' if known_script else 'parent.php')
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
                                     str(Path(directory) / 'status'), str(parent), 'poller.php', 'private-argument'],
                                    capture_output=True, text=True, timeout=10,
                                    env={**os.environ, 'HARNESS_OBSERVATION_TIMEOUT': '1',
                                         'PRIVATE_OBSERVATION_VALUE': 'private-environment'})
            assert result.returncode == 70 and 'workers terminated' in result.stderr, result
            diagnostic_lines = [line.removeprefix('POLLER_OBSERVATION_DIAGNOSTIC ')
                                for line in result.stderr.splitlines()
                                if line.startswith('POLLER_OBSERVATION_DIAGNOSTIC ')]
            assert len(diagnostic_lines) == 1, result.stderr
            diagnostic = json.loads(diagnostic_lines[0])
            assert diagnostic['parent_running'] is not descendant, diagnostic
            assert diagnostic['parent_exit'] == (0 if descendant else None), diagnostic
            assert diagnostic['processes'], diagnostic
            assert str(directory) not in result.stderr
            assert 'private-argument' not in result.stderr and 'private-environment' not in result.stderr
            # Both unknown fixture basenames and later script-shaped arguments
            # stay private; only a known first PHP script can be classified.
            if known_script:
                expected_script = 'poller_boost.php' if descendant else 'poller.php'
                assert any(process['script'] == expected_script for process in diagnostic['processes']), diagnostic
            else:
                assert all(process['script'] is None for process in diagnostic['processes']), diagnostic
            for process in diagnostic['processes']:
                assert set(process) == {'pid', 'parent', 'group', 'state', 'role', 'script'}, process
                assert process['group'] == diagnostic['group'] and process['state'] != 'Z', process
                assert process['role'] == ('observed-parent' if process['pid'] == diagnostic['observed_pid']
                                           else 'descendant'), process
            assert (Path(directory) / 'status').read_text() == ''
            pid = int(pidfile.read_text())
            assert any(process['pid'] == pid for process in diagnostic['processes']), diagnostic
            stat = Path('/proc') / str(pid) / 'stat'
            assert not stat.exists() or stat.read_text().rsplit(')', 1)[1].split()[0] == 'Z'
            time.sleep(2.2)
            assert not late.exists(), 'timed-out worker continued mutating artifacts'
        # Real PHP option parsing must not turn configuration, inline code,
        # or a later application argument into a script identity.
        known = Path(directory) / 'poller.php'
        unknown = Path(directory) / 'parent.php'
        body = '<?php file_put_contents(' + json.dumps(str(pidfile)) + ', getmypid()); sleep(3);'
        known.write_text(body)
        unknown.write_text(body)
        config_directory = Path(directory) / 'configuration'
        config_directory.mkdir()
        config = config_directory / 'poller.php'
        config.write_text('display_errors=0\n')
        inline = 'file_put_contents(' + json.dumps(str(pidfile)) + ', getmypid()); sleep(3);'
        option_cases = (
            (['--file=' + str(unknown), 'poller.php'], None),
            (['--file', str(unknown), 'poller.php'], None),
            (['-f', str(unknown), 'poller.php'], None),
            (['--php-ini', str(config), str(unknown), 'poller.php'], None),
            (['--php-ini=' + str(config), str(unknown), 'poller.php'], None),
            (['--define', 'display_errors=0', str(known)], 'poller.php'),
            (['--define=display_errors=0', str(known)], 'poller.php'),
            (['--run', inline, 'poller.php'], None),
            (['--run=' + inline, 'poller.php'], None),
            (['-r' + inline, 'poller.php'], None),
            (['-e', str(known)], None),
            (['-f', str(known), 'private-argument'], 'poller.php'),
            (['--file=' + str(known), 'private-argument'], 'poller.php'),
        )
        for options, expected_script in option_cases:
            (Path(directory) / 'status').unlink()
            pidfile.unlink(missing_ok=True)
            result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                     str(Path(directory) / 'status'), '-d', 'auto_prepend_file=', *options],
                                    capture_output=True, text=True, timeout=10,
                                    env={**os.environ, 'HARNESS_OBSERVATION_TIMEOUT': '1'})
            assert result.returncode == 70 and 'workers terminated' in result.stderr, result
            lines = [line.removeprefix('POLLER_OBSERVATION_DIAGNOSTIC ')
                     for line in result.stderr.splitlines() if line.startswith('POLLER_OBSERVATION_DIAGNOSTIC ')]
            assert len(lines) == 1, result.stderr
            diagnostic = json.loads(lines[0])
            pid = int(pidfile.read_text())
            matching = [process for process in diagnostic['processes'] if process['pid'] == pid]
            assert len(matching) == 1 and matching[0]['script'] == expected_script, diagnostic
            assert diagnostic['parent_running'] is True and diagnostic['parent_exit'] is None, diagnostic
            assert str(directory) not in result.stderr and 'private-argument' not in result.stderr
            assert (Path(directory) / 'status').read_text() == ''
        # PHP's bare -- reads code from stdin; its following argv is not a
        # script filename even when it names a real allowlisted fixture file.
        (Path(directory) / 'status').unlink()
        pidfile.unlink()
        result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                 str(Path(directory) / 'status'), '-d', 'auto_prepend_file=', '--', str(known)],
                                input=body, capture_output=True, text=True, timeout=10,
                                env={**os.environ, 'HARNESS_OBSERVATION_TIMEOUT': '1'})
        assert result.returncode == 70 and 'workers terminated' in result.stderr, result
        lines = [line.removeprefix('POLLER_OBSERVATION_DIAGNOSTIC ')
                 for line in result.stderr.splitlines() if line.startswith('POLLER_OBSERVATION_DIAGNOSTIC ')]
        assert len(lines) == 1, result.stderr
        diagnostic = json.loads(lines[0])
        pid = int(pidfile.read_text())
        matching = [process for process in diagnostic['processes'] if process['pid'] == pid]
        assert len(matching) == 1 and matching[0]['script'] is None, diagnostic
        assert (Path(directory) / 'status').read_text() == ''
        # PHP itself refuses unsupported options normally: that real exit is
        # complete, and no timeout classification is emitted.
        (Path(directory) / 'status').unlink()
        result = subprocess.run(['php', '-d', 'auto_prepend_file=', str(Path(__file__).with_name('wait-php.php')),
                                 str(Path(directory) / 'status'), '--unknown-observation-option', str(known)],
                                capture_output=True, text=True, timeout=10)
        assert result.returncode != 0 and 'POLLER_OBSERVATION_DIAGNOSTIC ' not in result.stderr, result
        assert (Path(directory) / 'status').read_text() == 'complete\n'
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


def local_login_form_contract():
    # The fresh-install local login form has no realm selector. Do not invent
    # a string realm that the production integer boundary must reject.
    session = harness.Session('http://fixture.invalid')
    calls = []
    def request(path, fields=None):
        calls.append((path, fields))
        session.token = 'fixture-csrf'
        return {'login_form': fields is None, 'admin_layout': fields is not None}
    with patch.object(session, 'request', side_effect=request):
        result = session.login('fixture-password')
    assert calls == [('/index.php', None), ('/index.php', {
        'action': 'login', 'login_username': 'admin',
        'login_password': 'fixture-password', '__csrf_magic': 'fixture-csrf'})]
    assert result == {'login_form': False, 'admin_layout': True}


FIXTURE_FUNCTIONS = """<?php
function fixture_echo($value, &$seen = null) {
    $seen = [$value, gettype($value)];
    return $value;
}
function fixture_noisy($value) {
    echo 'printed';
    trigger_error('careful', E_USER_WARNING);
    return @$undefined_variable;
}
function fixture_repeat() {
    for ($i = 0; $i < 3; $i++) {
        trigger_error('again', E_USER_NOTICE);
    }
}
function fixture_counter() {
    static $calls = 0;
    return ++$calls;
}
function fixture_throws() {
    throw new RuntimeException('boom');
}
function fixture_spins() {
    while (true) {
    }
}
function fixture_setting($name) {
    global $config;
    // Same shape as read_config_option(): a cached value answers before the table does.
    if (!isset($config['config_options_array'][$name])) {
        $config['config_options_array'][$name] = $GLOBALS['fixture_settings'][$name] ?? null;
    }
    return $config['config_options_array'][$name];
}
function fixture_ambient() {
    global $config;
    return [$config['fixture_flag'] ?? null, $_SESSION['fixture_user'] ?? null];
}
function fixture_hash($fixed) {
    return $fixed ? str_repeat('a', 32) : bin2hex(random_bytes(16));
}
function fixture_sql_write($name, $value) {
    $pdo = $GLOBALS['database_sessions']['fixture'];
    $insert = 'INSERT INTO settings (name, value) VALUES (?, ?)';
    $pdo->prepare($insert)->execute([$name, $value]);
    $pdo->prepare($insert)->execute([$name . '2', $value]);
    try {
        $pdo->prepare($insert)->execute([$name, $value]);
    } catch (PDOException $e) {
    }
    $pdo->prepare('SELECT 1')->execute();
    $pdo->prepare('SELECT 1')->execute();
    $pdo->prepare('SELECT 1')->execute();
    return true;
}
function fixture_state() {
    $_SESSION['kept']['inner'] = 'new';
    $_SESSION['plain'] = 'x';
    unset($_SESSION['gone']);
    $GLOBALS['config']['fixture_state'] = 'on';
    $GLOBALS['settings_user']['tab']['value'] = 'v';
}
function fixture_request($name) {
    return $GLOBALS['fixture_request'][$name] ?? null;
}
function fixture_mark($value) {
    $GLOBALS['fixture_marker'] = $value;
}
function fixture_marker() {
    return $GLOBALS['fixture_marker'] ?? null;
}
function fixture_logs() {
    file_put_contents(dirname(__DIR__) . '/log/cacti.log', "09/12/2026 02:39:50 - SYSTEM note\\n", FILE_APPEND);
}
"""


def fixture_application(directory):
    """A stand-in application root: just enough for probe.php to bootstrap.

    It tests the recorder, not any Cacti function, so the functions in it are
    deliberately small. Real captures never use this.
    """
    root = Path(directory) / 'app'
    for sub in ('include', 'lib', 'log'):
        (root / sub).mkdir(parents=True)
    (root / 'include/cli_check.php').write_text(
        "<?php\n$config = ['base_path' => dirname(__DIR__)];\n"
        "$GLOBALS['fixture_settings'] = [];\n"
        "function db_begin_transaction() { return getenv('FIXTURE_FAIL') !== 'begin'; }\n"
        "function db_rollback_transaction() { return true; }\n"
        # The settings table and its savepoint are modelled so that ambient
        # state is observable and a rollback is visible; FIXTURE_FAIL makes
        # one statement report failure.
        "function db_execute($sql) {\n"
        "    $fail = getenv('FIXTURE_FAIL');\n"
        "    if ($sql === 'SAVEPOINT fnwt_case') {\n"
        "        $GLOBALS['fixture_saved'] = $GLOBALS['fixture_settings'];\n"
        "        return $fail !== 'savepoint';\n"
        "    }\n"
        "    if ($sql === 'ROLLBACK TO SAVEPOINT fnwt_case') {\n"
        "        $GLOBALS['fixture_settings'] = $GLOBALS['fixture_saved'];\n"
        "        return $fail !== 'rollback';\n"
        "    }\n"
        "    return true;\n"
        "}\n"
        "function db_execute_prepared($sql, $params = []) {\n"
        "    if (str_contains($sql, 'FAILSTEP')) {\n"
        "        return false;\n"
        "    }\n"
        "    if (str_starts_with($sql, 'DELETE FROM settings')) {\n"
        "        unset($GLOBALS['fixture_settings'][$params[0]]);\n"
        "    } elseif (str_starts_with($sql, 'INSERT INTO settings') && $params) {\n"
        "        $GLOBALS['fixture_settings'][$params[0]] = $params[1];\n"
        "    }\n"
        "    return true;\n"
        "}\n"
        # A real PDO connection, so the statement class has something to attach to.
        "$pdo = new PDO('sqlite::memory:');\n"
        "$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);\n"
        "$pdo->exec('CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT)');\n"
        "$pdo->exec('CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT)');\n"
        "$GLOBALS['database_sessions'] = ['fixture' => $pdo];\n"
        "function db_fetch_assoc($sql) {\n"
        "    return $GLOBALS['database_sessions']['fixture']->query($sql)->fetchAll(PDO::FETCH_ASSOC);\n"
        "}\n"
        "function set_request_var($name, $value) {\n"
        "    $GLOBALS['fixture_request'][$name] = $value;\n"
        "}\n"
        "require __DIR__ . '/../lib/fixture_functions.php';\n")
    (root / 'lib/fixture_functions.php').write_text(FIXTURE_FUNCTIONS)
    for name in ('snmp.php', 'rrd.php'):
        (root / 'lib' / name).write_text('<?php\n')
    return root


def run_fixture_probe(root, cases_dir, name, extra=(), fail=None):
    php = shutil.which('php')
    if php is None:
        raise RuntimeError('Function capture self-tests need a host PHP; run through mise exec php@8.4.25')
    environment = {'PATH': '/usr/bin:/bin', 'BEHAVIOR_APP_ROOT': str(root), 'BEHAVIOR_CASE_DIR': str(cases_dir)}
    if fail:
        environment['FIXTURE_FAIL'] = fail
    process = subprocess.run([php, str(Path(__file__).with_name('probe.php')), 'function', name, *extra],
                             capture_output=True, text=True, env=environment, timeout=60)
    return {'exit': process.returncode, 'stdout': process.stdout, 'stderr': process.stderr}


def write_cases(directory, name, cases):
    Path(directory).mkdir(parents=True, exist_ok=True)
    (Path(directory) / (name + '.json')).write_text(json.dumps({'function': name, 'cases': cases}))


def codec_roundtrip(document):
    php = shutil.which('php')
    process = subprocess.run([php, str(Path(__file__).with_name('probe.php')), 'codec'], input=json.dumps(document),
                             capture_output=True, text=True, timeout=60)
    return process


def function_codec_contract():
    # Everything JSON cannot say by itself, in one document. Decoding and
    # re-encoding must give the same document back: the record of a value can
    # then be fed in as an input and mean the same thing.
    document = [None, '', '0', 0, 1, -1, 0.0, 1.0, 0.5, True, False, [], ['a', None],
                {'$': 'float', 'v': 'nan'}, {'$': 'float', 'v': 'inf'}, {'$': 'float', 'v': '-inf'}, {'$': 'float', 'v': '-0.0'},
                {'$': 'bytes', 'hex': 'c328'}, {'$': 'bytes', 'hex': '0a000001'},
                {'$': 'array', 'pairs': [[1, 'a'], ['1x', None], [0, []]]},
                'é', 'tab\there', 9223372036854775807]
    process = codec_roundtrip(document)
    assert process.returncode == 0, process.stderr
    assert json.loads(process.stdout) == document, process.stdout
    # == hides int against float and True against 1; the text does not.
    assert harness.canonical(json.loads(process.stdout)) == harness.canonical(document)
    assert '"0"' in process.stdout and '[null,"","0",0,1,-1,0.0,1.0,0.5,true,false' in process.stdout
    # A control byte written as a plain string comes back in the explicit form.
    again = codec_roundtrip(['a\u0000b'])
    assert json.loads(again.stdout) == [{'$': 'bytes', 'hex': '610062'}], again.stdout
    # Authoring mistakes must stop the run, not be guessed at.
    for bad in ([{'x': 1}], [{'$': 'nope'}], [{'$': 'bytes', 'hex': 'zz'}], [{'$': 'bytes', 'hex': 'abc'}],
                [{'$': 'float', 'v': '1.5'}], [{'$': 'array', 'pairs': [[1.5, 'x']]}],
                [{'$': 'repeat', 'unit': '', 'count': 3}], [{'$': 'repeat', 'unit': 'a', 'count': 10 ** 9}]):
        failed = codec_roundtrip(bad)
        assert failed.returncode != 0 and 'BEHAVIOR' not in failed.stdout, bad
    long_value = codec_roundtrip([{'$': 'repeat', 'unit': 'ab', 'count': 5000}])
    summary = json.loads(long_value.stdout)[0]
    assert summary['$'] == 'long' and summary['bytes'] == 10000 and len(summary['sha256']) == 64, summary
    print('value encoding keeps null, empty, "0", int, float, bytes and key order apart')


def function_case_file_contract():
    with tempfile.TemporaryDirectory(prefix='function cases ') as directory:
        base = Path(directory)

        def rejects(label, files, only=None):
            folder = base / label
            folder.mkdir()
            for name, text in files.items():
                (folder / (name + '.json')).write_text(text if isinstance(text, str) else json.dumps(text))
            try:
                harness.load_function_cases(folder, only)
            except RuntimeError:
                return
            raise AssertionError(label + ' was accepted')

        good = {'function': 'a', 'cases': [{'id': 'x', 'args': []}]}
        rejects('no_files', {})
        rejects('empty_cases', {'a': {'function': 'a', 'cases': []}})
        rejects('cases_missing', {'a': {'function': 'a'}})
        rejects('wrong_name', {'a': dict(good, function='b')})
        rejects('not_json', {'a': '{'})
        rejects('duplicate_id', {'a': {'function': 'a', 'cases': [{'id': 'x', 'args': []}, {'id': 'x', 'args': []}]}})
        rejects('no_id', {'a': {'function': 'a', 'cases': [{'args': []}]}})
        rejects('args_not_list', {'a': {'function': 'a', 'cases': [{'id': 'x', 'args': 'y'}]}})
        # A skip flag would turn a case into a silent pass.
        rejects('skip_flag', {'a': {'function': 'a', 'cases': [{'id': 'x', 'args': [], 'skip': True}]}})
        rejects('unknown_ambient', {'a': {'function': 'a', 'cases': [{'id': 'x', 'args': [], 'ambient': {'env': 1}}]}})
        rejects('unknown_function_selected', {'a': good}, only=['missing'])

        def with_case(**extra):
            return {'a': {'function': 'a', 'cases': [dict({'id': 'x', 'args': []}, **extra)]}}

        # Malformed steps would otherwise fail inside PHP after the stack is up.
        rejects('prelude_not_list', with_case(prelude={'fn': 'f'}))
        rejects('step_not_object', with_case(prelude=['f']))
        rejects('step_empty', with_case(between=[{}]))
        rejects('step_unknown_key', with_case(prelude=[{'fn': 'f', 'arg': []}]))
        rejects('step_fn_not_string', with_case(prelude=[{'fn': 3}]))
        rejects('step_args_not_list', with_case(between=[{'fn': 'f', 'args': 'y'}]))
        rejects('setting_step_extra_key', with_case(prelude=[{'setting': {'k': 'v'}, 'fn': 'f'}]))
        rejects('setting_step_not_object', with_case(prelude=[{'setting': 'k'}]))
        rejects('setting_step_int_value', with_case(prelude=[{'setting': {'k': 1}}]))
        rejects('ambient_setting_int_value', with_case(ambient={'settings': {'k': 1}}))
        rejects('timeout_string', with_case(timeout='2'))
        rejects('timeout_zero', with_case(timeout=0))
        rejects('timeout_negative', with_case(timeout=-1))
        rejects('timeout_bool', with_case(timeout=True))
        rejects('timeout_float', with_case(timeout=1.5))
        rejects('isolated_string', with_case(isolated='yes'))
        rejects('isolated_int', with_case(isolated=1))
        rejects('record_sql_string', with_case(record_sql='yes'))
        rejects('record_tables_int', with_case(record_tables=1))
        rejects('mask_generated_string', with_case(mask_generated='yes'))
        rejects('record_state_string', with_case(record_state='session'))
        rejects('record_state_empty_list', with_case(record_state=[]))
        rejects('record_state_unknown_area', with_case(record_state=['session', 'globals']))
        rejects('sql_step_not_text', with_case(prelude=[{'sql': 5}]))
        rejects('sql_step_blank', with_case(prelude=[{'sql': '  '}]))
        rejects('sql_step_params_not_list', with_case(prelude=[{'sql': 'SELECT 1', 'params': 'x'}]))
        rejects('sql_step_unknown_key', with_case(prelude=[{'sql': 'SELECT 1', 'fn': 'f'}]))
        rejects('request_step_empty', with_case(prelude=[{'request': {}}]))
        rejects('request_step_not_object', with_case(prelude=[{'request': 'a'}]))
        rejects('request_step_extra_key', with_case(prelude=[{'request': {'a': 1}, 'fn': 'f'}]))
        # A non-object ambient is a RuntimeError, not a TypeError from set().
        rejects('ambient_int', with_case(ambient=5))
        rejects('ambient_list', with_case(ambient=['settings']))
        rejects('ambient_section_not_object', with_case(ambient={'config': ['x']}))
        folder = base / 'fine'
        folder.mkdir()
        (folder / 'a.json').write_text(json.dumps(good))
        assert list(harness.load_function_cases(folder)) == ['a']
        folder = base / 'fine_steps'
        folder.mkdir()
        (folder / 'a.json').write_text(json.dumps(with_case(
            prelude=[{'fn': 'f', 'args': [1]}, {'setting': {'k': None, 'j': 'v'}}], between=[{'fn': 'f'}],
            ambient={'settings': {'k': None}, 'config': {}, 'session': {}}, isolated=True, timeout=2,
            record_sql=True, record_tables=True, record_state=['session', 'settings_user'], mask_generated=True)['a']))
        (folder / 'a.json').write_text(json.dumps({'function': 'a', 'cases': [dict(json.loads((folder / 'a.json').read_text())['cases'][0],
            prelude=[{'sql': 'INSERT INTO t VALUES (?)', 'params': [1]}, {'request': {'a': 1, 'b': [1]}}], record_state=True)]}))
        assert list(harness.load_function_cases(folder)) == ['a']
        # Every shipped case file must load, and none may be empty.
        shipped = harness.load_function_cases(harness.FUNCTION_CASES)
        assert shipped and all(spec['cases'] for spec in shipped.values())
    print('case files with no cases, skip flags, duplicate ids or unknown keys are rejected')


def function_capture_contract():
    with tempfile.TemporaryDirectory(prefix='function capture ') as directory:
        root = fixture_application(directory)
        cases = Path(directory) / 'cases'
        specs = {
            'fixture_echo': [
                {'id': 'null', 'args': [None, None]}, {'id': 'empty', 'args': ['']}, {'id': 'zero_string', 'args': ['0']},
                {'id': 'zero_int', 'args': [0]}, {'id': 'zero_float', 'args': [0.0]}, {'id': 'false', 'args': [False]},
                {'id': 'invalid_utf8', 'args': [{'$': 'bytes', 'hex': 'c328ff'}]},
                {'id': 'ordered_array', 'args': [{'$': 'array', 'pairs': [['b', 1], ['a', 2], [5, 3]]}]},
                {'id': 'negative_zero', 'args': [{'$': 'float', 'v': '-0.0'}]},
            ],
            'fixture_noisy': [{'id': 'only', 'args': ['x']}],
            'fixture_repeat': [{'id': 'only', 'args': []}],
            'fixture_counter': [{'id': 'first', 'args': []}],
            'fixture_throws': [{'id': 'only', 'args': []}],
            'fixture_logs': [{'id': 'only', 'args': []}],
            'fixture_spins': [{'id': 'only', 'args': [], 'timeout': 1, 'isolated': True}],
        }
        for name, cs in specs.items():
            write_cases(cases, name, cs)

        def capture(name):
            spec = harness.load_function_cases(cases, [name])[name]
            return harness.capture_function(name, spec, lambda n, extra: harness.parse_function_probe(
                n, run_fixture_probe(root, cases, n, extra)))

        echo = {c['id']: c for c in capture('fixture_echo')['cases']}
        types = {k: (v['result']['returned']['type'], v['result']['returned']['value']) for k, v in echo.items()}
        assert types['null'] == ('null', None) and types['empty'] == ('string', '') and types['zero_string'] == ('string', '0')
        assert types['zero_int'] == ('int', 0) and types['zero_float'] == ('float', 0.0) and types['false'] == ('bool', False)
        assert harness.canonical(echo['zero_float']['result']['returned']) != harness.canonical(echo['zero_int']['result']['returned'])
        assert types['invalid_utf8'] == ('string', {'$': 'bytes', 'hex': 'c328ff'})
        assert types['negative_zero'][1] == {'$': 'float', 'v': '-0.0'}
        assert types['ordered_array'][1]['pairs'] == [['b', 1], ['a', 2], [5, 3]], 'array order is behavior'
        # The by-reference parameter comes back with the type it ended up with.
        assert echo['null']['result']['by_ref']['seen']['value'] == [None, 'NULL']
        assert echo['empty']['warm'] == {'same': True}

        noisy = capture('fixture_noisy')['cases'][0]['result']
        assert noisy['output'] == 'printed'
        assert noisy['diagnostics'][0]['message'] == 'careful' and noisy['diagnostics'][0]['severity'] == 'E_USER_WARNING'
        assert noisy['diagnostics'][0]['count'] == 1 and noisy['diagnostics'][0]['suppressed'] is False
        assert noisy['returned'] == {'type': 'null', 'value': None}
        repeated = capture('fixture_repeat')['cases'][0]['result']['diagnostics']
        assert len(repeated) == 1 and repeated[0]['count'] == 3, repeated
        # The first call and the repeat in the same process differ for a static cache.
        counter = capture('fixture_counter')['cases'][0]
        assert counter['result']['returned']['value'] == 1
        assert counter['warm']['same'] is False and counter['warm']['result']['returned']['value'] == 2
        thrown = capture('fixture_throws')['cases'][0]['result']
        assert thrown['exception'] == {'class': 'RuntimeException', 'code': 0, 'message': 'boom'} and 'returned' not in thrown
        logged = capture('fixture_logs')['cases'][0]['result']
        assert logged['log'] == ['<TIMESTAMP> - SYSTEM note'], logged['log']
        spun = capture('fixture_spins')['cases'][0]['result']
        assert spun['exception']['class'] == 'BehaviorTimeout', spun

        # Two runs give the same bytes. Anything varying here (pids, clocks,
        # absolute paths) would show up as a regression in every golden.
        first = [harness.canonical(harness.normalize_function_record(capture(n))) for n in sorted(specs)]
        second = [harness.canonical(harness.normalize_function_record(capture(n))) for n in sorted(specs)]
        assert first == second, 'capture is not deterministic'
        assert str(root) not in ''.join(first), 'absolute path leaked into a record'

        # A function the application does not define is a failed run, not an empty pass.
        write_cases(cases, 'fixture_absent', [{'id': 'only', 'args': []}])
        spec = harness.load_function_cases(cases, ['fixture_absent'])['fixture_absent']
        try:
            harness.capture_function('fixture_absent', spec, lambda n, extra: harness.parse_function_probe(
                n, run_fixture_probe(root, cases, n, extra)))
        except RuntimeError as error:
            assert 'Unknown function' in str(error) or 'failed' in str(error)
        else:
            raise AssertionError('A missing function produced a record')
    print('captures keep PHP types, by-reference outputs, diagnostics, output, log lines and warm differences, and repeat byte for byte')


def function_ambient_contract():
    with tempfile.TemporaryDirectory(prefix='function ambient ') as directory:
        root = fixture_application(directory)
        cases = Path(directory) / 'cases'

        def capture(name, fail=None):
            spec = harness.load_function_cases(cases, [name])[name]
            return harness.capture_function(name, spec, lambda n, extra: harness.parse_function_probe(
                n, run_fixture_probe(root, cases, n, extra, fail)))

        def returned(record):
            return record['result']['returned']['value']

        # ambient.settings reaches the table, ambient.config and ambient.session
        # reach their globals, and the next case sees none of it.
        write_cases(cases, 'fixture_setting', [
            {'id': 'set', 'args': ['k'], 'ambient': {'settings': {'k': 'v'}}},
            {'id': 'rolled_back', 'args': ['k']},
        ])
        by_id = {c['id']: c for c in capture('fixture_setting')['cases']}
        assert returned(by_id['set']) == 'v', by_id['set']
        assert returned(by_id['rolled_back']) is None, 'a setting survived the savepoint rollback'
        assert by_id['set']['ambient'] == {'settings': {'k': 'v'}}

        write_cases(cases, 'fixture_ambient', [
            {'id': 'both', 'args': [], 'ambient': {'config': {'fixture_flag': 'on'},
                                                   'session': {'fixture_user': {'$': 'array', 'pairs': [['id', 7]]}}}},
            {'id': 'restored', 'args': []},
        ])
        by_id = {c['id']: c for c in capture('fixture_ambient')['cases']}
        assert returned(by_id['both']) == ['on', {'$': 'array', 'pairs': [['id', 7]]}], by_id['both']
        assert returned(by_id['restored']) == [None, None], '$config or $_SESSION leaked into the next case'

        # prelude runs a real function and a setting write before the call.
        write_cases(cases, 'fixture_marker', [
            {'id': 'prelude', 'args': [], 'prelude': [{'fn': 'fixture_mark', 'args': ['m']}]}])
        record = capture('fixture_marker')['cases'][0]
        assert returned(record) == 'm' and record['prelude'][0]['fn'] == 'fixture_mark', record
        write_cases(cases, 'fixture_setting', [
            {'id': 'prelude_setting', 'args': ['k'], 'prelude': [{'setting': {'k': 'p'}}]}])
        record = capture('fixture_setting')['cases'][0]
        assert returned(record) == 'p' and record['prelude'] == [{'setting': {'k': 'p'}}], record

        # A setting written between the calls must not drop the cached value:
        # the repeat is expected to read the stale one.
        write_cases(cases, 'fixture_setting', [
            {'id': 'stale', 'args': ['k'], 'ambient': {'settings': {'k': 'before'}},
             'between': [{'setting': {'k': 'after'}}], 'isolated': True}])
        record = capture('fixture_setting')['cases'][0]
        assert returned(record) == 'before' and record['between'] == [{'setting': {'k': 'after'}}], record
        assert record['warm'] == {'same': True}, 'between dropped the cache: ' + str(record['warm'])

        # Shared and isolated cases of one function: each isolated case gets a
        # fresh process, and records come back in file order.
        write_cases(cases, 'fixture_counter', [
            {'id': 'a', 'args': []}, {'id': 'c', 'args': [], 'isolated': True}, {'id': 'b', 'args': []}])
        records = capture('fixture_counter')['cases']
        assert [r['id'] for r in records] == ['a', 'c', 'b']
        assert [returned(r) for r in records] == [1, 1, 3], 'an isolated case shared a process with the others'

        # A case file cannot reach an internal function through a step.
        for key in ('prelude', 'between'):
            write_cases(cases, 'fixture_marker', [{'id': 'bad', 'args': [], key: [{'fn': 'shell_exec', 'args': ['echo hi']}]}])
            refused = run_fixture_probe(root, cases, 'fixture_marker')
            assert refused['exit'] != 0 and 'user-defined' in refused['stdout'] + refused['stderr'], (key, refused)
            assert 'BEHAVIOR_JSON=' not in refused['stdout'], key + ' step ran an internal function'

        # A failed SAVEPOINT, rollback or transaction start stops the run.
        write_cases(cases, 'fixture_marker', [{'id': 'only', 'args': []}])
        for fail, text in (('savepoint', 'SAVEPOINT fnwt_case failed'), ('rollback', 'ROLLBACK TO SAVEPOINT'),
                           ('begin', 'capture transaction')):
            refused = run_fixture_probe(root, cases, 'fixture_marker', fail=fail)
            assert refused['exit'] != 0 and text in refused['stdout'] + refused['stderr'], (fail, refused)
            assert 'BEHAVIOR_JSON=' not in refused['stdout'], 'a failed ' + fail + ' was ignored'

        # Normalisation exempts the author's input only at the case level.
        record = {'function': 'f', 'signature': [], 'cases': [{
            'id': '/var/www/html/i', 'args': ['/var/www/html/a'], 'note': '/var/www/html/n',
            'result': {'by_ref': {'id': {'type': 'string', 'value': '/var/www/html/x'},
                                  'args': {'type': 'string', 'value': '/var/www/html/y'}}}}]}
        out = harness.normalize_function_record(record)['cases'][0]
        assert out['id'] == '/var/www/html/i' and out['args'] == ['/var/www/html/a'] and out['note'] == '/var/www/html/n'
        assert out['result']['by_ref']['id']['value'] == '<APP>/x', out
        assert out['result']['by_ref']['args']['value'] == '<APP>/y', out
    print('ambient settings, config and session, prelude, between and mixed isolation behave; internal-function steps and failed savepoints stop the run')


def function_effect_contract():
    """Statements, table rows, state, generated hashes, sql and request steps."""
    with tempfile.TemporaryDirectory(prefix='function effects ') as directory:
        root = fixture_application(directory)
        cases = Path(directory) / 'cases'

        def capture(name, fail=None):
            spec = harness.load_function_cases(cases, [name])[name]
            return harness.capture_function(name, spec, lambda n, extra: harness.parse_function_probe(
                n, run_fixture_probe(root, cases, n, extra, fail)))

        write_cases(cases, 'fixture_sql_write', [
            {'id': 'recorded', 'args': ['k', 'v'], 'record_sql': True, 'record_tables': True},
            {'id': 'not_asked', 'args': ['k', 'v']}])
        by_id = {c['id']: c for c in capture('fixture_sql_write')['cases']}
        result = by_id['recorded']['result']
        insert = 'INSERT INTO settings (name, value) VALUES (?, ?)'
        assert result['sql'][:2] == [insert + ' -- ["k","v"]', insert + ' -- ["k2","v"]'], result['sql']
        # The duplicate fails: the line carries the error, and SELECT 1 x3 collapses to one line.
        assert result['sql'][2].startswith(insert + ' -- ["k","v"] !! PDOException'), result['sql']
        assert result['sql'][3] == 'SELECT 1 x3', result['sql']
        assert len(result['sql']) == 4, result['sql']
        assert result['table_diff'] == ['+ settings {"name":"k","value":"v"}', '+ settings {"name":"k2","value":"v"}'], result['table_diff']
        # Effects belong to the first call; the repeat fails on the duplicate key and is compared on its answer.
        assert 'sql' not in by_id['recorded']['warm'].get('result', {}), by_id['recorded']['warm']
        quiet = by_id['not_asked']['result']
        assert not {'sql', 'table_diff', 'state'} & set(quiet), 'effects recorded without being asked: ' + str(sorted(quiet))

        write_cases(cases, 'fixture_state', [
            {'id': 'default_areas', 'args': [], 'record_state': True,
             'ambient': {'session': {'gone': 1, 'kept': {'$': 'array', 'pairs': [['inner', 'old'], ['other', 2]]}}}},
            {'id': 'user_settings_area', 'args': [], 'record_state': ['settings_user'], 'isolated': True},
            {'id': 'not_asked', 'args': []}])
        by_id = {c['id']: c for c in capture('fixture_state')['cases']}
        assert by_id['default_areas']['result']['state'] == [
            'set session.kept.inner = "new"', 'set session.plain = "x"', 'unset session.gone', 'set config.fixture_state = "on"'], by_id['default_areas']['result']['state']
        assert by_id['user_settings_area']['result']['state'] == ['set settings_user.tab = {"$":"array","pairs":[["value","v"]]}'], by_id['user_settings_area']['result']
        assert 'state' not in by_id['not_asked']['result']

        # A generated hash differs between the calls and is masked; a stored one repeats and is kept.
        write_cases(cases, 'fixture_hash', [
            {'id': 'generated', 'args': [False], 'mask_generated': True},
            {'id': 'stored', 'args': [True], 'mask_generated': True},
            {'id': 'unmasked', 'args': [False]}])
        by_id = {c['id']: c for c in capture('fixture_hash')['cases']}
        assert by_id['generated']['result']['returned']['value'] == '<generated 32 hex>', by_id['generated']
        assert by_id['generated']['warm'] == {'same': True}, by_id['generated']['warm']
        assert by_id['stored']['result']['returned']['value'] == 'a' * 32, by_id['stored']
        assert by_id['unmasked']['warm']['same'] is False, 'an unmasked random value must show as unstable'

        # sql and request steps run, are recorded, and a failing statement stops the run.
        write_cases(cases, 'fixture_request', [
            {'id': 'steps', 'args': ['q'], 'prelude': [{'sql': "INSERT INTO settings VALUES ('a', 'b')"}, {'sql': 'INSERT INTO settings VALUES (?, ?)', 'params': ['c', 'd']},
                                                       {'request': {'q': {'$': 'array', 'pairs': [['x', 1]]}}}]}])
        record = capture('fixture_request')['cases'][0]
        assert record['prelude'] == ["INSERT INTO settings VALUES ('a', 'b')", 'INSERT INTO settings VALUES (?, ?) -- ["c","d"]',
                                      {'request': {'q': {'$': 'array', 'pairs': [['x', 1]]}}}], record['prelude']
        assert record['result']['returned']['value'] == {'$': 'array', 'pairs': [['x', 1]]}, record['result']
        write_cases(cases, 'fixture_request', [{'id': 'bad', 'args': ['q'], 'prelude': [{'sql': 'FAILSTEP'}]}])
        refused = run_fixture_probe(root, cases, 'fixture_request')
        assert refused['exit'] != 0 and 'SQL step failed' in refused['stdout'] + refused['stderr'], refused
        assert 'BEHAVIOR_JSON=' not in refused['stdout'], 'a failed sql step was ignored'

        # Defaults are stripped from a warm result that follows recorded effects, and put back;
        # a warm result in an older golden keeps its explicit defaults and still compares.
        full = {'diagnostics': [], 'log': [], 'output': ''}
        newer = {'function': 'f', 'signature': [], 'cases': [{'id': 'a', 'args': [], 'note': '', 'warm': {'same': False, 'result': dict(full, returned={'type': 'int', 'value': 2})},
                                                              'result': dict(full, returned={'type': 'int', 'value': 1}, sql=['SELECT 1'])}]}
        stripped = harness.strip_function_defaults(newer)['cases'][0]
        assert stripped['warm']['result'] == {'returned': {'type': 'int', 'value': 2}}, stripped
        assert harness.canonical(harness.restore_function_defaults(harness.strip_function_defaults(newer))) == harness.canonical(newer)
        older = json.loads(json.dumps(newer))
        del older['cases'][0]['result']['sql']
        kept = harness.strip_function_defaults(older)['cases'][0]
        assert kept['warm']['result'] == newer['cases'][0]['warm']['result'], 'an older warm result was rewritten'
        assert harness.canonical(harness.restore_function_defaults(harness.strip_function_defaults(older))) == harness.canonical(older)

        # A function with more than 64 cases keeps every case; only values inside a case are summarized.
        many = {'function': 'f', 'signature': [], 'cases': [{'id': str(i), 'args': [list(range(70))], 'result': {'returned': {'type': 'int', 'value': i}}} for i in range(70)]}
        out = harness.normalize_function_record(many)
        assert isinstance(out['cases'], list) and len(out['cases']) == 70, 'the case list was summarized'
        assert out['cases'][0]['args'][0]['$'] == 'list', out['cases'][0]['args']
    print('statements, table rows, state, generated hashes, sql and request steps and large case lists behave')


def function_record_gaps():
    spec = {'function': 'f', 'cases': [{'id': 'a', 'args': []}, {'id': 'b', 'args': []}]}
    signature = []

    def short(name, extra):
        return {'function': 'f', 'signature': signature, 'cases': [{'id': 'a'}]}

    def empty(name, extra):
        return {'function': 'f', 'signature': signature, 'cases': []}

    def other(name, extra):
        return {'function': 'g', 'signature': signature, 'cases': [{'id': 'a'}, {'id': 'b'}]}

    def whole(name, extra):
        return {'function': 'f', 'signature': signature, 'cases': [{'id': 'a'}, {'id': 'b'}]}

    for probe in (short, empty, other):
        try:
            harness.capture_function('f', spec, probe)
        except RuntimeError:
            pass
        else:
            raise AssertionError('Incomplete probe answer was accepted: ' + probe.__name__)
    assert [c['id'] for c in harness.capture_function('f', spec, whole)['cases']] == ['a', 'b']
    # Output outside the capture, a nonzero exit or no marker is a failed probe.
    for result in ({'exit': 1, 'stdout': 'BEHAVIOR_JSON={}', 'stderr': ''}, {'exit': 0, 'stdout': 'nothing', 'stderr': ''},
                   {'exit': 0, 'stdout': 'stray\nBEHAVIOR_JSON={}', 'stderr': ''}):
        try:
            harness.parse_function_probe('f', result)
        except RuntimeError:
            pass
        else:
            raise AssertionError('Failed probe was accepted: ' + repr(result))
    print('a probe that records fewer cases, none, or another function fails the run')


def function_golden_contract():
    with tempfile.TemporaryDirectory(prefix='function goldens ') as directory:
        controller = Path(directory) / 'controller'
        cases = controller / 'tests/Support/Behavior/function_cases'
        write_cases(cases, 'f', [{'id': 'a', 'args': []}])
        args = types.SimpleNamespace(target='fixture', update_golden=False)
        record = {'function': 'f', 'signature': [], 'cases': [{'id': 'a', 'note': '', 'warm': {'same': True}, 'result': {
            'diagnostics': [], 'log': [], 'output': '', 'returned': {'type': 'int', 'value': 1}}}]}
        with patch.object(harness, 'CONTROLLER_ROOT', controller), patch.object(harness, 'FUNCTION_GOLDENS', controller / 'tests/Golden/functions'), \
                patch.object(harness, 'FUNCTION_CASES', cases):
            assert harness.settle_function_goldens(args, {'f': record}, '8.4') == ['f: MISSING GOLDEN (explicit capture required)']
            args.update_golden = True
            assert harness.settle_function_goldens(args, {'f': record}, '8.4') == []
            args.update_golden = False
            assert harness.settle_function_goldens(args, {'f': record}, '8.4') == []
            # int 1 and float 1.0 compare equal in Python and are different PHP values.
            changed = json.loads(json.dumps(record))
            changed['cases'][0]['result']['returned']['value'] = 1.0
            assert changed == record
            assert harness.settle_function_goldens(args, {'f': changed}, '8.4') == ['f: REGRESSION']
            # A golden whose case file was removed is not silently ignored.
            (cases / 'f.json').unlink()
            write_cases(cases, 'other', [{'id': 'a', 'args': []}])
            assert 'f: golden has no case file' in harness.settle_function_goldens(args, {'other': record}, '8.4')
    print('goldens fail when missing, when int becomes float, or when the case file is gone')


def compact_json_contract():
    # A container stays on one line while its whole line, indentation, key and comma included, fits in 100 columns.
    for length, inline in ((95, True), (96, True), (97, False)):
        text = harness.compact_json(['x' * length])
        assert (text == '["' + 'x' * length + '"]') == inline, (length, text)
        assert inline or text == '[\n  "' + 'x' * length + '"\n]', text
    assert len(harness.compact_json(['x' * 96])) == 100
    # Inner list sits at 2 spaces + '"key": ' and is followed by a comma, so 86 characters reach column 100.
    for length, inline in ((85, True), (86, True), (87, False)):
        lines = harness.compact_json({'key': ['x' * length], 'z': 1}).split('\n')
        if inline:
            assert lines[1] == '  "key": ["' + 'x' * length + '"],' and len(lines[1]) <= 100, lines
        else:
            assert lines[1] == '  "key": [' and lines[2] == '    "' + 'x' * length + '"', lines
    assert harness.compact_json([]) == '[]' and harness.compact_json({}) == '{}'
    assert harness.compact_json({'a': [], 'b': {}}) == '{"a": [], "b": {}}'
    # Columns are characters, not bytes, and non-ASCII text is written as is.
    assert harness.compact_json(['\u00e9' * 96]) == '["' + '\u00e9' * 96 + '"]'
    assert harness.compact_json(['\u00e9' * 97]).count('\n') == 2
    # Key order is sorted at every level, as json.dumps(sort_keys=True) does.
    assert harness.compact_json({'b': 1, 'a': {'d': 1, 'c': 2}}) == '{"a": {"c": 2, "d": 1}, "b": 1}'
    tricky = {
        'quote': 'say "hi"', 'backslash': 'a\\b', 'slash': 'a/b', 'brace': '{[}]', 'comma': 'a, b: c',
        'control': 'tab\there\nnewline\x01', 'unicode': '\u00e9 \U0001F600 \u2028', 'empty': '', 'zero': '0',
        'ints': [0, -1, 2 ** 63 - 1], 'floats': [0.0, 1.0, 0.5, -2.25e-10], 'bools': [True, False, None],
        'nested': {'a': [], 'b': [[], [[]]], 'c': {'d': {'e': 'long ' * 30}}},
        'objects': [{'k': 1}, {'k': [1, 2, 3], 'long': 'y' * 90}],
        'key"with\\odd/keys': 1, 'wide': list(range(60)),
    }
    text = harness.compact_json(tricky)
    assert json.loads(text) == tricky
    assert harness.canonical(json.loads(text)) == harness.canonical(tricky)
    assert harness.compact_json(json.loads(text)) == text
    assert '"floats": [0.0, 1.0, 0.5, -2.25e-10]' in text
    try:
        harness.compact_json({'k': {1: 'x' * 120}})
    except TypeError:
        pass
    else:
        raise AssertionError('non-string keys must be refused')
    print('compact json keeps the 100 column boundary, key order, empty containers and awkward values')


def large_value_summary_contract():
    digest = lambda value: hashlib.sha256(harness.canonical(value).encode()).hexdigest()
    # 64 elements are written out; 65 become a summary whose hash covers the whole list.
    assert harness.summarize_large(list(range(64))) == list(range(64))
    summary = harness.summarize_large(list(range(65)))
    assert summary == {'$': 'list', 'count': 65, 'types': ['int'], 'head': [0, 1, 2, 3], 'sha256': digest(list(range(65)))}, summary
    # One changed element, or int against float, changes the hash.
    changed = list(range(65))
    changed[64] = 99
    assert harness.summarize_large(changed)['sha256'] != summary['sha256']
    as_float = list(range(65))
    as_float[64] = 64.0
    assert harness.summarize_large(as_float)['sha256'] != summary['sha256']
    assert harness.summarize_large(as_float)['types'] == ['float', 'int']
    # Objects follow the same threshold, counted by keys.
    wide = {'k%03d' % i: i for i in range(65)}
    assert harness.summarize_large({k: v for k, v in list(wide.items())[:64]}) == {k: v for k, v in list(wide.items())[:64]}
    summary = harness.summarize_large(wide)
    assert summary['$'] == 'object' and summary['count'] == 65 and summary['sha256'] == digest(wide) and summary['head'] == {'k000': 0, 'k001': 1, 'k002': 2, 'k003': 3}, summary
    # A large value inside small containers is found; a small one inside a large one is covered by its hash.
    nested = {'a': [1, [list(range(65)), 'x']], 'b': [[1, 2]] * 65}
    out = harness.summarize_large(nested)
    assert out['a'][1][0]['$'] == 'list' and out['a'][1][1] == 'x' and out['b']['count'] == 65 and out['b']['head'] == [[1, 2]] * 4, out
    assert harness.summarize_large([list(range(65))] * 3)[0]['count'] == 65
    # The case's own args and its results are both summarized, by-reference outputs included.
    record = {'function': 'f', 'signature': [], 'cases': [{
        'id': 'big', 'args': [list(range(65))], 'note': '', 'warm': {'same': True},
        'result': {'returned': {'type': 'array', 'value': list(range(100))}, 'by_ref': {'0': list(range(70))},
                   'diagnostics': [], 'log': [], 'output': ''}}]}
    case = harness.normalize_function_record(record)['cases'][0]
    assert case['args'][0]['count'] == 65 and case['result']['returned']['value']['count'] == 100 and case['result']['by_ref']['0']['count'] == 70, case
    # Defaults are left out of a golden and restored on read, and only a value equal to the default is left out.
    stripped = harness.strip_function_defaults(record)
    assert stripped['cases'][0]['result'].keys() == {'returned', 'by_ref'} and 'note' not in stripped['cases'][0] and 'warm' not in stripped['cases'][0], stripped
    assert harness.canonical(harness.restore_function_defaults(stripped)) == harness.canonical(record)
    odd = {'function': 'f', 'signature': [], 'cases': [{'id': 'x', 'args': [], 'note': 'n', 'warm': {'same': False},
                                                        'result': {'diagnostics': [['E']], 'log': [''], 'output': '0'}}]}
    assert harness.strip_function_defaults(odd) == odd
    assert harness.canonical(harness.restore_function_defaults(harness.strip_function_defaults(odd))) == harness.canonical(odd)
    print('values over 64 elements are summarized by hash, smaller ones and non-default fields are untouched')


def function_comparison_contract():
    with tempfile.TemporaryDirectory(prefix='function comparison ') as directory:
        root = Path(directory)

        def side(label, value, extra_function=False, extra_case=False):
            case = lambda i, v: {'id': i, 'result': {'returned': {'type': type(v).__name__, 'value': v}}, 'warm': {'same': True}}
            functions = {'f': {'function': 'f', 'signature': [], 'cases': [case('same', 1), case('moved', value)]}}
            if extra_case:
                functions['f']['cases'].append(case('new', 1))
            if extra_function:
                functions['g'] = {'function': 'g', 'signature': [], 'cases': [case('only', 1)]}
            (root / label).mkdir()
            (root / label / 'functions.json').write_text(json.dumps({'php_version': '8.4.0', 'functions': functions}))

        side('base', 1)
        side('cand', 1.0, extra_function=True)
        args = types.SimpleNamespace(results_root=root, baseline='base', candidate='cand', output=None)
        assert harness.compare_functions(args) == 0
        report = json.loads((root / 'cand-vs-base.functions-comparison.json').read_text())
        rows = {row['case']: row for row in report['functions']['f']['cases']}
        assert rows['same'] == {'case': 'same', 'status': 'same'}
        assert rows['moved']['status'] == 'differs' and rows['moved']['review'] == 'unreviewed', rows['moved']
        assert rows['moved']['baseline']['result']['returned']['value'] == 1 and rows['moved']['candidate']['result']['returned']['value'] == 1.0
        assert report['only_in_candidate'] == ['g'] and report['totals'] == {'same': 1, 'differs': 1, 'functions': 1}
        # A case the baseline never ran is not a clean comparison either.
        side('cand_extra', 1, extra_case=True)
        extra_args = types.SimpleNamespace(results_root=root, baseline='base', candidate='cand_extra', output=None)
        assert harness.compare_functions(extra_args) == 1
        extra_report = json.loads((root / 'cand_extra-vs-base.functions-comparison.json').read_text())
        assert extra_report['only_in_candidate_cases'] == {'f': ['new']}, extra_report
        assert 'only_in_candidate_cases' not in report
        # Nothing in common is an error, not a clean comparison.
        (root / 'cand/functions.json').write_text(json.dumps({'functions': {'z': {}}}))
        try:
            harness.compare_functions(args)
        except RuntimeError:
            pass
        else:
            raise AssertionError('A comparison with no shared function passed')
    print('function comparison marks 1 against 1.0 as differs, leaves it unreviewed, and fails with no overlap')


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
    local_login_form_contract()
    print('local login submits the actual fresh-install form without an invented realm')
    source_provenance_failure_contract()
    provenance_contract()
    application_input_boundary_contract()
    base_image_failure_contract()
    pinned_base_image_contract()
    application_image_contract()
    diagnostic_contracts()
    retained_controller_inputs()
    function_codec_contract()
    function_case_file_contract()
    function_capture_contract()
    function_ambient_contract()
    function_effect_contract()
    function_record_gaps()
    function_golden_contract()
    compact_json_contract()
    large_value_summary_contract()
    function_comparison_contract()
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
