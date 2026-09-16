"""Exercise rehearsal failures without requiring Docker or valid Git baselines."""
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
import json
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
        (root / 'rra/nested/sample.rrd').unlink()
        (root / 'rra/sample.rrd').unlink()
        try:
            release.rrd_manifest(runtime)
        except RuntimeError as error:
            assert 'No RRD files' in str(error)
        else:
            raise AssertionError('Empty RRD manifest accepted')
    print('RRD manifests distinguish nested paths, detect nested changes, and reject empty stores')


def main():
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
