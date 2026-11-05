# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Exercise tools/review_risk.py against a real throwaway repository."""
import contextlib
import io
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import review_risk  # noqa: E402


class ReviewRiskTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.repo = self.directory.name
        self.day = 0
        self.git('init', '-q', '-b', 'main')

    def tearDown(self):
        self.directory.cleanup()

    def git(self, *arguments, author='owner@example.org'):
        environment = dict(os.environ, GIT_AUTHOR_NAME='Author', GIT_AUTHOR_EMAIL=author,
                           GIT_COMMITTER_NAME='Author', GIT_COMMITTER_EMAIL=author,
                           GIT_AUTHOR_DATE=f'2026-01-{1 + self.day:02d}T00:00:00Z',
                           GIT_COMMITTER_DATE=f'2026-01-{1 + self.day:02d}T00:00:00Z', GIT_CONFIG_GLOBAL='/dev/null')
        return subprocess.run(['git', '-C', self.repo, *arguments], check=True, capture_output=True, text=True,
                              env=environment).stdout

    def commit(self, subject, files, author='owner@example.org'):
        for path, content in files.items():
            target = Path(self.repo, path)
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_text(content)
            self.git('add', path)
        self.git('commit', '-q', '-m', subject, author=author)
        self.day += 1
        return self.git('rev-parse', 'HEAD').strip()

    def run_tool(self, *arguments):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            status = review_risk.main(['--repo', self.repo, *arguments])
        return status, out.getvalue(), err.getvalue()

    def build_history(self):
        self.commit('feat: start', {'lib/auth.php': 'a\n' * 100, 'lib/util.php': 'u\n' * 100, 'docs/x.md': 'd\n'})
        self.commit('fix(auth): first defect', {'lib/auth.php': 'a\n' * 101})
        self.commit('fix(auth): second defect', {'lib/auth.php': 'a\n' * 102})
        base = self.git('rev-parse', 'HEAD').strip()
        small = self.commit('chore: tweak util', {'lib/util.php': 'u\n' * 100 + 'v\n', 'tests/UtilTest.php': 't\n'})
        risky = self.commit('fix(auth): rewrite login (#42)', {'lib/auth.php': 'b\n' * 150, 'install/setup.php': 's\n' * 40},
                            author='newcomer@example.org')
        prose = self.commit('docs: explain', {'docs/x.md': 'd\n' * 50})
        generated = self.commit('chore(i18n): catalogs', {'locales/po/cacti.pot': 'p\n' * 5000})
        return base, {'small': small, 'risky': risky, 'prose': prose, 'generated': generated}

    def test_signals_follow_the_published_predictors(self):
        base, shas = self.build_history()
        status, out, err = self.run_tool('--json', f'{base}..main')
        self.assertEqual(status, 0, err)
        rows = {row['sha']: row for row in json.loads(out)}
        self.assertEqual(len(rows), 4)

        risky = rows[shas['risky']]['signals']
        self.assertEqual(risky['prior_fixes'], 2.0)            # two earlier fixes touched lib/auth.php
        self.assertEqual(risky['low_ownership'], 1.0)          # the author never touched these files before
        self.assertEqual(risky['untested'], 1.0)               # production change with no test file
        self.assertEqual(risky['trust_boundary'], 2.0)         # lib/auth.php and install/
        self.assertAlmostEqual(risky['relative_churn'], (150 + 102 + 40) / 102)

        small = rows[shas['small']]['signals']
        self.assertEqual(small['untested'], 0.0)
        self.assertEqual(small['trust_boundary'], 0.0)
        self.assertEqual(small['low_ownership'], 0.0)

        generated = rows[shas['generated']]['signals']
        self.assertEqual(generated['files'], 0.0)              # catalogs are generated, not reviewed churn
        self.assertEqual(generated['relative_churn'], 0.0)

    def test_the_riskiest_commit_ranks_first_and_the_report_links_its_pull_request(self):
        base, shas = self.build_history()
        status, out, err = self.run_tool('--limit', '2', f'{base}..main')
        self.assertEqual(status, 0, err)
        lines = out.splitlines()
        self.assertEqual(len(lines), 4)                        # header, rule, two rows
        self.assertIn(shas['risky'][:10], lines[2])
        self.assertIn('| #42 |', lines[2])
        self.assertIn('prior_fixes', lines[2])

        status, out, _ = self.run_tool('--json', f'{base}..main')
        ranked = [row['sha'] for row in json.loads(out)]
        self.assertEqual(ranked[0], shas['risky'])
        self.assertEqual(ranked[-1], shas['generated'])

    def test_an_empty_range_or_bad_revision_is_an_error_not_an_empty_report(self):
        self.build_history()
        status, out, err = self.run_tool('main..main')
        self.assertEqual(status, 2)
        self.assertEqual(out, '')
        self.assertIn('no commits', err)

        status, _, err = self.run_tool('no-such-ref..main')
        self.assertEqual(status, 2)
        self.assertIn('git log', err)


if __name__ == '__main__':
    unittest.main()
