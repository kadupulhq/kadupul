# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Check the changelog fragment gate and the release assembly in tools/changelog.py."""
import contextlib
import io
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'tools'))
import changelog  # noqa: E402

HEAD = '''# Changelog

Intro.

## [Unreleased]
- An entry added before fragments existed.

- Another legacy entry,
  wrapped onto two lines.

## [1.3.0-alpha.1]

- Earlier release.


[Unreleased]: https://github.com/kadupulhq/kadupul/commits/main
'''

# The release workflow's extraction (kadupulhq/.github release.yml), so the
# assembled section is checked against the consumer that publishes it. Its
# pattern is unanchored after the version, so "1.3.0" would also match the
# "1.3.0-alpha.1" heading and keep reading; the test releases 1.4.0 instead.
RELEASE_NOTES_AWK = r'''$0 ~ "^## \\[?" v "\\]?" { grab = 1; next }
grab && /^## / { exit }
grab { print }'''


def git(root, *args):
    return subprocess.run(['git', '-C', str(root), *args], check=True, capture_output=True, text=True).stdout


class Repository:
    def __init__(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='kadupul-changelog-test-')
        self.root = Path(self.temporary.name)
        git(self.root, 'init', '-q', '-b', 'main')
        git(self.root, 'config', 'user.email', 'test@example.invalid')
        git(self.root, 'config', 'user.name', 'Test')
        git(self.root, 'config', 'commit.gpgsign', 'false')
        git(self.root, 'config', 'core.hooksPath', '/dev/null')
        self.write('CHANGELOG.md', HEAD)
        self.write('lib/code.php', '<?php\n')
        self.write('changelog.d/README.md', 'How to write a fragment.\n')
        self.base = self.commit()

    def write(self, relative, text):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(text)

    def commit(self):
        git(self.root, 'add', '-A')
        git(self.root, 'commit', '-q', '--allow-empty', '-m', 'change')
        return git(self.root, 'rev-parse', 'HEAD').strip()

    def require(self):
        return changelog.require(self.root, self.base)

    def close(self):
        self.temporary.cleanup()


class FragmentFormat(unittest.TestCase):
    def test_accepts_items_with_continuations(self):
        self.assertEqual([], changelog.fragment_problems('1450.fixed.md', '- One.\n  Continued.\n- Two.\n'))
        self.assertEqual([], changelog.fragment_problems('reindex-cache.security.md', '- One.'))

    def test_refuses_bad_names_and_categories(self):
        for name in ('fix.md', 'Upper.fixed.md', '-lead.fixed.md', 'trail-.fixed.md', 'a_b.fixed.md',
                     'a.fixed.txt', 'a.b.fixed.md', '.fixed.md'):
            with self.subTest(name=name):
                self.assertIn('name must be', changelog.fragment_problems(name, '- Entry.\n')[0])
        self.assertIn('unknown category "bugfix"', changelog.fragment_problems('a.bugfix.md', '- Entry.\n')[0])

    def test_refuses_content_that_breaks_the_release_section(self):
        cases = {
            '': 'empty',
            '\n\n': 'empty',
            'Plain prose.\n': 'start with',
            '- Entry.\n## [9.9.9]\n': 'each line',
            '- Entry.\n### Fixed\n': 'each line',
            '- \n': 'empty list item',
            '- Entry.\r\n': 'LF line endings',
        }
        for text, reason in cases.items():
            with self.subTest(text=text):
                problems = changelog.fragment_problems('a.fixed.md', text)
                self.assertTrue(any(reason in problem for problem in problems), problems)

    def test_annotation_escapes_command_separators(self):
        self.assertEqual('a%0A::warning::b%0D%25', changelog.annotation('a\n::warning::b\r%'))


class RequireEntry(unittest.TestCase):
    def setUp(self):
        self.repo = Repository()
        self.addCleanup(self.repo.close)

    def test_code_change_without_an_entry_fails(self):
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.commit()
        with self.assertRaisesRegex(changelog.ChangelogError, 'adds no changelog entry'):
            self.repo.require()

    def test_fragment_satisfies_the_gate(self):
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.write('changelog.d/echo-one.fixed.md', '- Echo one.\n')
        self.repo.commit()
        self.assertEqual('Changelog fragment: changelog.d/echo-one.fixed.md', self.repo.require())

    def test_editing_an_existing_fragment_satisfies_the_gate(self):
        self.repo.write('changelog.d/echo-one.fixed.md', '- Echo one.\n')
        self.repo.base = self.repo.commit()
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.write('changelog.d/echo-one.fixed.md', '- Echo one, now with detail.\n')
        self.repo.commit()
        self.assertIn('echo-one.fixed.md', self.repo.require())

    def test_existing_changelog_line_still_satisfies_the_gate(self):
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.write('CHANGELOG.md', HEAD.replace('## [Unreleased]\n', '## [Unreleased]\n- Echo one.\n'))
        self.repo.commit()
        self.assertEqual('CHANGELOG.md updated.', self.repo.require())

    def test_documentation_only_change_needs_no_entry(self):
        self.repo.write('docs/guide.md', 'Guide.\n')
        self.repo.write('.github/workflows/x.yml', 'name: x\n')
        self.repo.commit()
        self.assertIn('no entry needed', self.repo.require())

    def test_invalid_fragment_fails_even_with_a_valid_one(self):
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.write('changelog.d/echo-one.fixed.md', '- Echo one.\n')
        self.repo.write('changelog.d/echo-two.bugfix.md', '- Echo two.\n')
        self.repo.commit()
        with self.assertRaisesRegex(changelog.ChangelogError, 'unknown category'):
            self.repo.require()

    def test_files_that_are_not_fragments_do_not_count(self):
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.write('changelog.d/README.md', 'Edited guidance.\n')
        self.repo.commit()
        with self.assertRaisesRegex(changelog.ChangelogError, 'adds no changelog entry'):
            self.repo.require()

    def test_deleting_a_fragment_is_not_an_entry(self):
        self.repo.write('changelog.d/old.fixed.md', '- Old.\n')
        self.repo.base = self.repo.commit()
        (self.repo.root / 'changelog.d/old.fixed.md').unlink()
        self.repo.write('lib/code.php', '<?php\necho 1;\n')
        self.repo.commit()
        with self.assertRaisesRegex(changelog.ChangelogError, 'adds no changelog entry'):
            self.repo.require()

    def test_unknown_base_is_an_error(self):
        self.repo.base = '0' * 40
        with self.assertRaises(subprocess.CalledProcessError):
            self.repo.require()


class Release(unittest.TestCase):
    def setUp(self):
        self.repo = Repository()
        self.addCleanup(self.repo.close)
        self.path = self.repo.root / 'CHANGELOG.md'

    def run_cli(self, *args):
        output, errors = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(output), contextlib.redirect_stderr(errors):
            status = changelog.main(['--root', str(self.repo.root), *args])
        return status, output.getvalue(), errors.getvalue()

    def test_assembles_unreleased_and_fragments_into_a_version_section(self):
        self.repo.write('changelog.d/zeta.fixed.md', '- Fix zeta.\n')
        self.repo.write('changelog.d/alpha.fixed.md', '- Fix alpha,\n  on two lines.\n')
        self.repo.write('changelog.d/new-page.added.md', '- Add a page.\n- And its route.\n')
        self.repo.write('changelog.d/suite.tests.md', '- Test it.\n')
        status, output, _ = self.run_cli('release', '1.3.0', '--date', '2026-12-01')
        self.assertEqual(0, status)
        self.assertIn('removed 4 fragments', output)
        self.assertEqual(['README.md'], sorted(p.name for p in (self.repo.root / 'changelog.d').iterdir()))
        expected = HEAD.replace('## [Unreleased]\n', '''## [Unreleased]

## [1.3.0] - 2026-12-01

''').replace('''  wrapped onto two lines.
''', '''  wrapped onto two lines.

### Added

- Add a page.
- And its route.

### Fixed

- Fix alpha,
  on two lines.
- Fix zeta.

### Tests

- Test it.
''')
        self.assertEqual(expected, self.path.read_text())

    @unittest.skipIf(shutil.which('awk') is None, 'awk is unavailable')
    def test_release_workflow_reads_exactly_the_new_section(self):
        self.repo.write('changelog.d/one.security.md', '- Close a hole.\n')
        self.assertEqual(0, self.run_cli('release', '1.4.0', '--date', '2026-12-01')[0])
        notes = subprocess.run(['awk', '-v', 'v=1.4.0', RELEASE_NOTES_AWK, str(self.path)],
                               check=True, capture_output=True, text=True).stdout.strip('\n')
        self.assertEqual('''- An entry added before fragments existed.

- Another legacy entry,
  wrapped onto two lines.

### Security

- Close a hole.''', notes)

    def test_fragments_alone_are_released_when_unreleased_is_empty(self):
        self.repo.write('CHANGELOG.md', '# Changelog\n\n## [Unreleased]\n\n[Unreleased]: https://example.invalid\n')
        self.repo.write('changelog.d/one.changed.md', '- Change one.\n')
        self.assertEqual(0, self.run_cli('release', '2.0.0', '--date', '2027-01-02')[0])
        self.assertEqual('''# Changelog

## [Unreleased]

## [2.0.0] - 2027-01-02

### Changed

- Change one.

[Unreleased]: https://example.invalid
''', self.path.read_text())

    def test_refuses_and_changes_nothing_on_error(self):
        self.repo.write('changelog.d/bad.bugfix.md', '- Bad.\n')
        cases = [
            (('release', '1.3', '--date', '2026-12-01'), 'not a MAJOR.MINOR.PATCH'),
            (('release', '1.3.0', '--date', '01/12/2026'), 'not a YYYY-MM-DD'),
            (('release', '1.3.0-alpha.1', '--date', '2026-12-01'), 'already has a section'),
            (('release', '1.3.0', '--date', '2026-12-01'), 'unknown category'),
        ]
        for args, reason in cases:
            with self.subTest(args=args):
                status, _, errors = self.run_cli(*args)
                self.assertEqual(1, status)
                self.assertIn(reason, errors)
                self.assertEqual(HEAD, self.path.read_text())
                self.assertTrue((self.repo.root / 'changelog.d/bad.bugfix.md').exists())

    def test_refuses_an_empty_release(self):
        self.repo.write('CHANGELOG.md', '# Changelog\n\n## [Unreleased]\n\n## [1.0.0]\n\n- Old.\n')
        status, _, errors = self.run_cli('release', '1.1.0', '--date', '2026-12-01')
        self.assertEqual(1, status)
        self.assertIn('Nothing to release', errors)

    def test_refuses_a_changelog_without_unreleased(self):
        self.repo.write('CHANGELOG.md', '# Changelog\n\n## [1.0.0]\n\n- Old.\n')
        self.repo.write('changelog.d/one.fixed.md', '- One.\n')
        status, _, errors = self.run_cli('release', '1.1.0', '--date', '2026-12-01')
        self.assertEqual(1, status)
        self.assertIn('no "## [Unreleased]" heading', errors)

    def test_check_and_preview(self):
        self.repo.write('changelog.d/one.fixed.md', '- One.\n')
        self.assertEqual((0, 'OK: 1 changelog fragments\n', ''), self.run_cli('check'))
        self.assertEqual((0, '### Fixed\n\n- One.\n', ''), self.run_cli('preview'))
        self.repo.write('changelog.d/two.fixed.txt', '- Two.\n')
        status, _, errors = self.run_cli('check')
        self.assertEqual(1, status)
        self.assertIn("'two.fixed.txt': name must be", errors)


class RepositoryFragments(unittest.TestCase):
    def test_checked_in_fragments_are_valid(self):
        changelog.read_fragments(ROOT)
        self.assertTrue((ROOT / 'changelog.d/README.md').is_file())


if __name__ == '__main__':
    unittest.main(verbosity=2)
