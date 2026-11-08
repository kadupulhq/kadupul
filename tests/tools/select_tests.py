#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""List the registered legacy tests that refer to the files a local change touches.

For quick local feedback before CI runs every suite. Memon et al., "Taming
Google-Scale Continuous Testing" (ICSE-SEIP 2017), select the tests that
depend on a change; here the dependency is a test that names a changed file
(tests load production sources by path) or is itself changed. CI still runs
every registered test, so a test this misses still runs before merge.

    python3 tests/tools/select_tests.py origin/main
prints the selected files and a Pest command that runs them. The change is
everything since the merge base with the given revision: the branch's commits,
uncommitted edits and untracked files that git does not ignore.
"""
from __future__ import annotations

import argparse
from pathlib import Path, PurePosixPath
import re
import shlex
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[2]
CONFIGURATIONS = ('tests/phpunit-spikekill.xml', 'tests/phpunit-database.xml')
FILE_ENTRY = re.compile(r'<file>\s*(?:\./)?([^<]+?)\s*</file>')


def registered(root: Path) -> dict[str, str]:
    """Registered test files relative to tests/, mapped to their configuration."""
    tests: dict[str, str] = {}
    for configuration in CONFIGURATIONS:
        path = root / configuration
        if not path.is_file():
            continue
        for entry in FILE_ENTRY.findall(path.read_text()):
            tests.setdefault(entry, configuration)
    if not tests:
        raise ValueError('no registered test files found in ' + ', '.join(CONFIGURATIONS))
    return tests


def git(root: Path, base: str, *arguments: str) -> list[str]:
    """Run a read-only git command in root and return its NUL-separated output, or raise ValueError."""
    result = subprocess.run(['git', '-C', str(root), *arguments], capture_output=True, text=True, check=False)
    if result.returncode != 0:
        raise ValueError(f'git diff against {base} failed: {result.stderr.strip()}')
    return [entry for entry in result.stdout.replace('\0', '\n').splitlines() if entry]


def changed(root: Path, base: str) -> list[str]:
    """Paths changed since the merge base with base, including uncommitted and untracked files.

    Deleted files and both sides of a rename count: a test that still loads a
    deleted or renamed source is one the change breaks. The diff runs against
    the working tree, not HEAD, so an edit that is not yet committed, staged or
    not, selects its tests too.
    """
    if base.startswith('-'):
        raise ValueError(f'base revision {base!r} must not start with "-"')
    merge_base = git(root, base, 'merge-base', base, 'HEAD')[0]
    paths = git(root, base, 'diff', '-z', '--name-only', '--no-renames', merge_base, '--')
    untracked = git(root, base, 'ls-files', '-z', '--others', '--exclude-standard', '--full-name', ':/')
    return sorted(set(paths) | set(untracked))


def refers(text: str, path: str) -> bool:
    """Whether a test names a production file the way tests load one.

    A nested path matches as written ('lib/poller.php'). A top-level name must
    stand alone in a string ('/poller.php' or 'poller.php' inside quotes), so
    'lib/poller.php' does not select tests of the top-level poller.php.
    """
    if '/' in path:
        return path in text
    return re.search(r"['\"]/?" + re.escape(path) + r"['\"]", text) is not None


def select(root: Path, tests: dict[str, str], paths: list[str]) -> dict[str, list[str]]:
    """Map each selected test file to the changed paths that selected it."""
    selected: dict[str, list[str]] = {}
    sources = [path for path in paths if not path.startswith('tests/') and PurePosixPath(path).suffix in ('.php', '.twig', '.js', '.sql', '.xml')]
    for test in sorted(tests):
        file = root / 'tests' / test
        if 'tests/' + test in paths:
            selected.setdefault(test, []).append('tests/' + test)
        if not file.is_file():
            continue
        text = file.read_text(errors='replace')
        for path in sources:
            if refers(text, path):
                selected.setdefault(test, []).append(path)
    return selected


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('base', nargs='?', default='origin/main', help='base revision to diff against (default origin/main)')
    parser.add_argument('--root', type=Path, default=ROOT, help=argparse.SUPPRESS)
    arguments = parser.parse_args(argv)
    try:
        tests = registered(arguments.root)
        paths = changed(arguments.root, arguments.base)
    except ValueError as error:
        print(f'ERROR: {error}', file=sys.stderr)
        return 2
    if not paths:
        print(f'No changes against {arguments.base}.')
        return 0
    selected = select(arguments.root, tests, paths)
    if not selected:
        print(f'{len(paths)} changed files; no registered legacy test names them. CI still runs every suite.')
        return 0
    print(f'{len(selected)} of {len(tests)} registered test files refer to the {len(paths)} changed files:')
    for test, reasons in selected.items():
        print(f'  {test}  ({", ".join(sorted(set(reasons)))})')
    for configuration in sorted({tests[test] for test in selected}):
        files = [test for test in selected if tests[test] == configuration]
        print('\ncd tests && vendor/bin/pest --test-directory=. --configuration=' + PurePosixPath(configuration).name + ' '
              + ' '.join(shlex.quote(test) for test in files))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
