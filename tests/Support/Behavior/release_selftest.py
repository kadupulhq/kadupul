"""Exercise rehearsal failures without requiring Docker or valid Git baselines."""
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
import json
import os
import hashlib
import io
import subprocess
from pathlib import Path
import tempfile
from unittest.mock import Mock, patch
import release_readiness as release


def recursive_rrd_manifest():
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        (root / 'rra/nested').mkdir(parents=True)
        (root / 'rra/sample.rrd').write_bytes(b'root RRD')
        (root / 'rra/nested/sample.rrd').write_bytes(b'nested RRD')
        class LocalRuntime:
            def php(self, *args):
                result = subprocess.run(['php', *args], cwd=root, capture_output=True, text=True)
                return dict(exit=result.returncode, stdout=result.stdout, stderr=result.stderr)
        runtime = LocalRuntime()
        before = release.rrd_manifest(runtime)
        assert before == {name: hashlib.sha256((root / 'rra' / name).read_bytes()).hexdigest()
                          for name in ('sample.rrd', 'nested/sample.rrd')}
        (root / 'rra/nested/sample.rrd').write_bytes(b'changed nested RRD')
        assert release.rrd_manifest(runtime) != before
        (root / 'external.rrd').write_bytes(b'external RRD')
        (root / 'rra/nested/link.rrd').symlink_to(root / 'external.rrd')
        try:
            release.rrd_manifest(runtime)
        except RuntimeError as error:
            assert 'symlink target' in str(error)
        else:
            raise AssertionError('External RRD symlink accepted')
        (root / 'rra/nested/link.rrd').unlink()
        (root / 'outside').mkdir()
        (root / 'outside/external.rrd').write_bytes(b'external directory RRD')
        (root / 'rra/directory-link').symlink_to(root / 'outside', target_is_directory=True)
        try:
            release.rrd_manifest(runtime)
        except RuntimeError as error:
            assert 'symlink target' in str(error)
        else:
            raise AssertionError('External RRD directory symlink accepted')
        (root / 'rra/directory-link').unlink()
        (root / 'rra').rename(root / 'real-rra')
        (root / 'rra').symlink_to(root / 'real-rra', target_is_directory=True)
        try:
            release.rrd_manifest(runtime)
        except RuntimeError as error:
            assert 'symlink target' in str(error)
        else:
            raise AssertionError('External RRD root symlink accepted')
        (root / 'rra').unlink()
        (root / 'real-rra').rename(root / 'rra')
        (root / 'rra/nested/sample.rrd').unlink()
        (root / 'rra/sample.rrd').unlink()
        try:
            release.rrd_manifest(runtime)
        except RuntimeError as error:
            assert 'No RRD files' in str(error)
        else:
            raise AssertionError('Empty RRD manifest accepted')
    print('RRD manifests distinguish nested paths, detect nested changes, and reject empty stores')


def baseline_checkout_metadata():
    revision = release.harness.run(['git', '-C', str(release.ROOT), 'rev-parse', 'HEAD'])['stdout'].strip()
    with tempfile.TemporaryDirectory(prefix='release baseline checkout ') as directory:
        baseline = Path(directory) / 'baseline'
        # Reproduce the Git environment inherited by a pre-push hook, but
        # point it at a disposable repository so a regression cannot damage ROOT.
        decoy = Path(directory) / 'caller'
        release.harness.run(['git', 'init', str(decoy)])
        before = (decoy / '.git/HEAD').read_bytes()
        hook_env = {'GIT_DIR': str(decoy / '.git'), 'GIT_WORK_TREE': str(decoy),
                    'GIT_COMMON_DIR': str(decoy / '.git'), 'GIT_INDEX_FILE': str(decoy / '.git/index')}
        with patch.dict(os.environ, hook_env):
            release.prepare_baseline(revision, baseline)
            assert release.harness.run(['git', '-C', str(baseline), 'rev-parse', 'HEAD'])['stdout'].strip() == revision
        assert (decoy / '.git/HEAD').read_bytes() == before
        assert not (decoy / '.git/index').exists()
        assert release.harness.run(['git', '-C', str(release.ROOT), 'rev-parse', 'HEAD'])['stdout'].strip() == revision
        actual = release.harness.run(['git', '-C', str(baseline), 'rev-parse', 'HEAD'])['stdout'].strip()
        assert actual == revision
        assert (baseline / '.git').is_dir()
        expected_schema = release.harness.run(['git', '-C', str(release.ROOT), 'show', revision + ':cacti.sql'])['stdout'].encode()
        assert (baseline / 'cacti.sql').read_bytes() == expected_schema
        with patch.object(release.harness, 'ROOT', baseline):
            release.harness.validate_application_inputs()
    print('Release baseline preserves revision metadata, schema and validated controller overlay')


def baseline_overlay_replacement():
    with tempfile.TemporaryDirectory(prefix='release obsolete inputs ') as directory:
        source = Path(directory) / 'source'
        source.mkdir()
        def git(*arguments):
            return release.harness.run(['git', '-C', str(source), '-c', 'user.name=Harness Fixture',
                '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                '-c', 'core.hooksPath=/dev/null', *arguments])['stdout'].strip()
        git('init', '-q')
        for relative in ('tests/Support/Behavior', 'tests/Fixtures', 'tests/behavior'):
            (source / relative).mkdir(parents=True)
            (source / relative / 'current').write_text('candidate input')
            (source / relative / 'obsolete').write_text('baseline-only input')
        (source / 'cacti.sql').write_text('unchanged schema')
        (source / '.dockerignore').write_text('.git')
        git('add', '.')
        git('commit', '-q', '-s', '-m', 'Create baseline fixture')
        revision = git('rev-parse', 'HEAD')
        for path in source.rglob('obsolete'):
            path.unlink()
        git('add', '-u')
        git('commit', '-q', '-s', '-m', 'Remove obsolete controller inputs')
        baseline = Path(directory) / 'baseline'
        with patch.object(release, 'ROOT', source):
            release.prepare_baseline(revision, baseline)
        assert not list(baseline.rglob('obsolete'))
        assert len(list((baseline / 'tests').rglob('current'))) == 3
        assert (baseline / 'cacti.sql').read_text() == 'unchanged schema'
    print('Baseline overlays remove obsolete helpers without changing application data')


def baseline_ignore_contract():
    for original in (None, '.git', 'different/'):
        with tempfile.TemporaryDirectory(prefix='release ignore ') as directory:
            source = Path(directory) / 'source'
            source.mkdir()
            def git(*args):
                return release.harness.run(['git', '-C', str(source), '-c', 'user.name=Harness Fixture',
                    '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                    '-c', 'core.hooksPath=/dev/null', *args])['stdout'].strip()
            git('init', '-q')
            (source / 'cacti.sql').write_text('schema')
            if original is not None:
                (source / '.dockerignore').write_text(original)
            git('add', '.')
            git('commit', '-qm', 'Baseline')
            revision = git('rev-parse', 'HEAD')
            for relative in ('tests/Support/Behavior', 'tests/Fixtures', 'tests/behavior'):
                (source / relative).mkdir(parents=True)
            (source / '.dockerignore').write_text('.git')
            baseline = Path(directory) / 'baseline'
            with patch.object(release, 'ROOT', source):
                if original == 'different/':
                    try:
                        release.prepare_baseline(revision, baseline)
                    except RuntimeError as error:
                        assert 'refusing to overwrite' in str(error)
                    else:
                        raise AssertionError('Changed baseline build inputs accepted')
                    assert (baseline / '.dockerignore').read_text() == original
                else:
                    evidence = release.prepare_baseline(revision, baseline)
                    assert evidence['added'] == (original is None)
                    assert (evidence['baseline_sha256'] is None) == (original is None)
                    assert (baseline / '.dockerignore').read_text() == '.git'
    print('Baseline build exclusions are preserved; absent historical inputs are explicitly recorded')


def shallow_fetched_baseline():
    with tempfile.TemporaryDirectory(prefix='release shallow baseline ') as directory:
        remote = Path(directory) / 'remote'
        source = Path(directory) / 'source'
        baseline = Path(directory) / 'baseline'
        remote.mkdir()
        def git(tree, *args):
            return release.harness.run(['git', '-C', str(tree), '-c', 'user.name=Harness Fixture',
                '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false',
                '-c', 'core.hooksPath=/dev/null', *args])['stdout'].strip()
        git(remote, 'init', '-q')
        for relative in ('tests/Support/Behavior', 'tests/Fixtures', 'tests/behavior'):
            (remote / relative).mkdir(parents=True)
            (remote / relative / 'input').write_text('fixture')
        (remote / 'cacti.sql').write_text('baseline schema')
        (remote / '.dockerignore').write_text('.git')
        git(remote, 'add', '.')
        git(remote, 'commit', '-q', '-s', '-m', 'Baseline fixture')
        revision = git(remote, 'rev-parse', 'HEAD')
        (remote / 'cacti.sql').write_text('candidate schema')
        git(remote, 'commit', '-qam', 'Candidate fixture')
        release.harness.run(['git', 'clone', '--depth=1', '--', remote.as_uri(), str(source)])
        git(source, 'fetch', '--no-tags', remote.as_uri(), revision)
        assert git(source, 'rev-parse', '--is-shallow-repository') == 'true'
        candidate = git(source, 'rev-parse', 'HEAD')
        with patch.object(release, 'ROOT', source):
            release.prepare_baseline(revision, baseline)
        assert git(baseline, 'rev-parse', 'HEAD') == revision
        assert (baseline / 'cacti.sql').read_text() == 'baseline schema'
        assert git(source, 'rev-parse', 'HEAD') == candidate
    print('Shallow checkouts preserve a separately fetched baseline revision')


def legacy_build_input_integrity():
    import hashlib
    import shutil
    evidence = release.ROOT / 'tests/behavior/evidence/historical-baseline'
    with tempfile.TemporaryDirectory() as directory:
        baseline = Path(directory) / 'baseline'
        baseline.mkdir()
        hashes = release.legacy_build_inputs(baseline)
        assert hashlib.sha256((baseline / '.behavior-legacy.Dockerfile').read_bytes()).hexdigest() == hashes['tests/behavior/Dockerfile']
        assert hashlib.sha256((baseline / '.behavior-legacy.Dockerfile.dockerignore').read_bytes()).hexdigest() == hashes['.dockerignore']
        with patch.dict(os.environ, {'PHP_VERSION': '8.4'}):
            pin = release.pin_legacy_runtime(baseline)
        assert pin['archived_recipe_sha256'] == hashes['tests/behavior/Dockerfile']
        assert pin['executed_recipe_sha256'] != pin['archived_recipe_sha256']
        assert pin['base_ref'].startswith('ghcr.io/kadupulhq/kadupul-mirrors/php:8.4-apache@sha256:')
        assert ('LABEL org.kadupul.behavior.php-base="' + pin['base_ref'] + '"') in (baseline / '.behavior-legacy.Dockerfile').read_text()
        executed = (baseline / '.behavior-legacy.Dockerfile').read_bytes()
        with patch.dict(os.environ, {'PHP_VERSION': 'unsupported'}):
            try:
                release.pin_legacy_runtime(baseline)
            except RuntimeError as error:
                assert 'pinned rehearsal runtime' in str(error)
            else:
                raise AssertionError('An unsupported historical runtime was accepted')
        assert (baseline / '.behavior-legacy.Dockerfile').read_bytes() == executed
        forged_root = Path(directory) / 'forged'
        forged_evidence = forged_root / 'tests/behavior/evidence/historical-baseline'
        shutil.copytree(evidence, forged_evidence)
        manifest = json.loads((forged_evidence / 'first.json').read_text())
        manifest['provenance']['harness_inputs_sha256']['tests/behavior/Dockerfile'] = '0' * 64
        (forged_evidence / 'first.json').write_text(json.dumps(manifest))
        destination = Path(directory) / 'rejected'
        destination.mkdir()
        with patch.object(release, 'ROOT', forged_root):
            try:
                release.legacy_build_inputs(destination)
            except RuntimeError as error:
                assert 'hash mismatch' in str(error)
            else:
                raise AssertionError('Unverified historical build input accepted')
        assert not list(destination.iterdir())
    print('Historical release build inputs retain verified hashes and reject tampering')


def runtime_provenance_consistency():
    source = (release.ROOT / 'tests/behavior/Dockerfile').read_text()
    references = release.pinned_runtime_references()
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        recipe = root / 'tests/behavior/Dockerfile'
        recipe.parent.mkdir(parents=True)
        old = references['8.4']
        updated = old.split('@')[0] + '@sha256:' + 'a' * 64
        for changed in (source.replace('FROM ' + old, 'FROM ' + updated, 1),
                        source.replace('php-base="' + old, 'php-base="' + updated, 1),
                        source + '\nFROM ' + updated + ' AS php-8.4\n'):
            recipe.write_text(changed)
            with patch.object(release, 'ROOT', root):
                try:
                    release.pinned_runtime_references()
                except RuntimeError as error:
                    assert 'provenance' in str(error)
                else:
                    raise AssertionError('Mismatched PHP image provenance was accepted')
        recipe.write_text(source.replace(old, updated))
        with patch.object(release, 'ROOT', root):
            assert release.pinned_runtime_references()['8.4'] == updated
    print('PHP runtime provenance rejects FROM-only and LABEL-only updates and accepts matched updates')


def recovery_evidence_contract():
    assert release.RECOVERY_CASES == ('partial_collector_sync', 'worker_crash', 'database_outage')
    valid = {name: {'observed': True, 'preserved': True} for name in release.RECOVERY_CASES}
    valid['partial_collector_sync']['repaired_sync'] = True
    valid['partial_collector_sync'].update(remote_before=['old template', '0'], remote_after=['new template', '1'])
    for name in ('worker_crash', 'database_outage'):
        valid[name]['native_and_outer_stopped'] = True
    release.require_recovery_evidence(valid)
    for name in release.RECOVERY_CASES:
        for missing in ('case', 'observed', 'preserved'):
            changed = json.loads(json.dumps(valid))
            if missing == 'case':
                del changed[name]
            else:
                changed[name][missing] = False
            try:
                release.require_recovery_evidence(changed)
            except RuntimeError as error:
                assert name in str(error)
            else:
                raise AssertionError('Missing actual interruption evidence was accepted: ' + name)
    valid['partial_collector_sync']['repaired_sync'] = False
    try:
        release.require_recovery_evidence(valid)
    except RuntimeError as error:
        assert 'real synchronization' in str(error)
    else:
        raise AssertionError('Unrepaired collector was accepted as successful recovery')
    valid['partial_collector_sync']['repaired_sync'] = True
    for name in ('worker_crash', 'database_outage'):
        changed = json.loads(json.dumps(valid))
        changed[name]['native_and_outer_stopped'] = False
        try:
            release.require_recovery_evidence(changed)
        except RuntimeError as error:
            assert name in str(error)
        else:
            raise AssertionError('An unconfirmed native descendant was accepted as stopped')
    valid['partial_collector_sync']['remote_after'] = valid['partial_collector_sync']['remote_before']
    try:
        release.require_recovery_evidence(valid)
    except RuntimeError as error:
        assert 'previously stale' in str(error)
    else:
        raise AssertionError('Cloned, unchanged collector values passed as transferred data')
    print('All three native recovery cases and repaired collector evidence are required')


def failed_fault_cleanup_stops_owned_web():
    for stop_fails in (False, True):
        lock = Mock()
        lock.stdin = io.StringIO()
        lock.stdout = io.StringIO('wrong owned row\n')
        lock.poll.return_value = None
        lock.communicate.return_value = ('', '')
        runtime = Mock()
        runtime.dc = ['docker', 'compose', '-p', 'owned-selftest-only']
        runtime.compose.return_value = {'exit': 0, 'stdout': '', 'stderr': ''}
        if stop_fails:
            runtime.compose.side_effect = RuntimeError('cleanup stop failure')
        runtime.command.return_value = {'exit': 0, 'stdout': '', 'stderr': ''}
        with patch.object(release.subprocess, 'Popen', return_value=lock), \
                patch.object(release.select, 'select', return_value=([lock.stdout], [], [])):
            try:
                release.locked_finishing_attempt(runtime, 'worker_crash')
            except RuntimeError as error:
                assert str(error) == 'Owned collector row lock was not acquired'
                assert getattr(error, 'rehearsal_cleanup_errors', []) == (['stop_web:RuntimeError'] if stop_fails else [])
            else:
                raise AssertionError('Invalid lock acquisition unexpectedly admitted a worker')
        runtime.compose.assert_called_once_with('stop', 'web', timeout=60)
        assert lock.stdin.getvalue().endswith('ROLLBACK;\nquit\n')
        lock.communicate.assert_called_once_with(timeout=10)
        args = runtime.command.call_args.args
        assert args[:2] == ('rm', '-f') and args[2].startswith('/tmp/installer-rehearsal-')
    print('Fault cleanup preserves original assertions and releases owned locks/PID files even when web stop fails')


def main():
    recovery_evidence_contract()
    failed_fault_cleanup_stops_owned_web()
    runtime_provenance_consistency()
    legacy_build_input_integrity()
    baseline_ignore_contract()
    baseline_checkout_metadata()
    baseline_overlay_replacement()
    shallow_fetched_baseline()
    recursive_rrd_manifest()
    projects = []
    for missing_docker in (False, True):
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            if argv[0] == 'git':
                return {'exit': 128, 'stdout': '', 'stderr': 'unknown baseline'}
            if missing_docker:
                raise FileNotFoundError('docker unavailable')
            return {'exit': 0, 'stdout': '', 'stderr': ''}
        with tempfile.TemporaryDirectory() as directory:
            # The equals form passes the hostile revision as a value.
            with patch('sys.argv', ['rehearsal', '--baseline=--bad-option', '--output', directory]), patch.object(release.harness, 'run', run):
                assert release.main() == 1
            evidence = json.loads((Path(directory) / 'observations.json').read_text())
            assert evidence['complete'] is False
            assert 'unknown baseline' in evidence['error']
            assert ('cleanup_error' in evidence) == missing_docker
            projects.append(evidence['project'])
            assert calls[0][-2:] == ['--end-of-options', '--bad-option^{commit}']
            for call in calls[1:]:
                assert any(evidence['project'] in arg for arg in call), call
    assert len(set(projects)) == 2
    print('Invalid revisions and unavailable Docker preserve incomplete evidence; cleanup is scoped to unique projects')
    print('PASS 10 release rehearsal self-test groups')


if __name__ == '__main__':
    main()
