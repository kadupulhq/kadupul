"""Exercise rehearsal failures without requiring Docker or valid Git baselines."""
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
import json
import os
import hashlib
import subprocess
from pathlib import Path
import tempfile
from unittest.mock import patch
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


def main():
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


if __name__ == '__main__':
    main()
