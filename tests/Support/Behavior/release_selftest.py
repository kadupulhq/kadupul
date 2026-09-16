"""Exercise rehearsal failures without requiring Docker or valid Git baselines."""
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
import json
from pathlib import Path
import tempfile
from unittest.mock import patch
import release_readiness as release


def main():
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
