#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Check changelog fragments and assemble them into CHANGELOG.md.

A pull request describes its user-visible change in its own file,
changelog.d/<slug>.<category>.md, instead of editing the shared
[Unreleased] list, so merges to main no longer make every open pull request
conflict in CHANGELOG.md. The release preparation folds the fragments into a
versioned section, which the tag-triggered release workflow reads.

    python3 tools/changelog.py check                 validate every fragment
    python3 tools/changelog.py require --base <sha>  the CI gate for a branch
    python3 tools/changelog.py preview               print the pending entries
    python3 tools/changelog.py release 1.3.0         assemble a release section

A fragment holds one or more Markdown list items ("- " lines, continued by
lines indented two spaces). Headings are refused because the release workflow
ends a version's notes at the next "## " line.
"""
from __future__ import annotations

import argparse
import datetime
from pathlib import Path
import re
import subprocess
import sys

ROOT = Path(__file__).resolve().parents[1]
FRAGMENT_DIR = 'changelog.d'
CHANGELOG = 'CHANGELOG.md'
# Keep a Changelog's categories in its order, plus the Tests heading the
# 1.3.0 history already uses.
CATEGORIES = {
    'added': 'Added',
    'changed': 'Changed',
    'deprecated': 'Deprecated',
    'removed': 'Removed',
    'fixed': 'Fixed',
    'security': 'Security',
    'tests': 'Tests',
}
FRAGMENT_NAME = re.compile(r'(?P<slug>[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)\.(?P<category>[a-z]+)\.md\Z')
# Files in changelog.d that are not fragments.
NOT_FRAGMENTS = frozenset(('README.md',))
VERSION = re.compile(r'\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\Z')
UNRELEASED = re.compile(r'^## \[Unreleased\][ \t]*$', re.MULTILINE)
SECTION = re.compile(r'^## ', re.MULTILINE)
# Paths that need no entry: CI, contributor hooks, documentation and the
# changelog itself. Matches the gate this script replaced.
EXEMPT = re.compile(r'(CHANGELOG\.md|\.github/.*|\.githooks/.*|docs/.*|.*\.md)\Z', re.DOTALL)


class ChangelogError(Exception):
    def __init__(self, *problems: str):
        super().__init__('\n'.join(problems))
        self.problems = problems


def annotation(text: str) -> str:
    """Escape text for a GitHub ::error:: command, so a file name cannot start another command."""
    return text.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')


def fragment_problems(name: str, text: str) -> list[str]:
    """Reasons a fragment file is unusable; empty when it is valid."""
    match = FRAGMENT_NAME.match(name)
    if not match:
        return [f'{FRAGMENT_DIR}/{name!r}: name must be <slug>.<category>.md with a lower-case slug of letters, digits and hyphens']
    if match['category'] not in CATEGORIES:
        return [f'{FRAGMENT_DIR}/{name}: unknown category "{match["category"]}"; use one of {", ".join(CATEGORIES)}']
    problems = []
    if '\r' in text:
        problems.append(f'{FRAGMENT_DIR}/{name}: use LF line endings')
    lines = text.strip('\n').split('\n')
    if not text.strip():
        return [f'{FRAGMENT_DIR}/{name}: fragment is empty']
    if not lines[0].startswith('- '):
        problems.append(f'{FRAGMENT_DIR}/{name}:1: start with a "- " list item')
    for number, line in enumerate(lines, 1):
        if line.strip() and not (line.startswith('- ') or line.startswith('  ')):
            problems.append(f'{FRAGMENT_DIR}/{name}:{number}: each line must be a "- " list item or a continuation indented two spaces')
        elif line.startswith('- ') and not line[2:].strip():
            problems.append(f'{FRAGMENT_DIR}/{name}:{number}: empty list item')
    return problems


def read_fragments(root: Path) -> list[tuple[str, str, str]]:
    """Every fragment as (category, file name, text), refusing invalid ones."""
    directory = root / FRAGMENT_DIR
    if not directory.is_dir():
        return []
    fragments = []
    problems = []
    for path in sorted(directory.iterdir()):
        if path.name in NOT_FRAGMENTS:
            continue
        if path.is_symlink() or not path.is_file():
            problems.append(f'{FRAGMENT_DIR}/{path.name!r}: not a regular file')
            continue
        try:
            text = path.read_text(encoding='utf-8')
        except UnicodeDecodeError:
            problems.append(f'{FRAGMENT_DIR}/{path.name}: not UTF-8')
            continue
        found = fragment_problems(path.name, text)
        if found:
            problems.extend(found)
            continue
        fragments.append((FRAGMENT_NAME.match(path.name)['category'], path.name, text.strip('\n')))
    if problems:
        raise ChangelogError(*problems)
    return fragments


def render(fragments: list[tuple[str, str, str]]) -> str:
    """Group fragments under ### headings, in category then file-name order."""
    blocks = []
    for category, heading in CATEGORIES.items():
        entries = sorted((name, text) for kind, name, text in fragments if kind == category)
        if entries:
            blocks.append(f'### {heading}\n\n' + '\n'.join(text for _, text in entries))
    return '\n\n'.join(blocks)


def release(root: Path, version: str, date: str) -> list[Path]:
    """Move [Unreleased] and the fragments into a version section; return the fragments to delete."""
    if not VERSION.match(version):
        raise ChangelogError(f'"{version}" is not a MAJOR.MINOR.PATCH version')
    if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', date):
        raise ChangelogError(f'"{date}" is not a YYYY-MM-DD date')
    path = root / CHANGELOG
    text = path.read_text(encoding='utf-8')
    if re.search(r'^## \[' + re.escape(version) + r'\]', text, re.MULTILINE):
        raise ChangelogError(f'{CHANGELOG} already has a section for {version}')
    heading = UNRELEASED.search(text)
    if heading is None:
        raise ChangelogError(f'{CHANGELOG} has no "## [Unreleased]" heading')
    following = SECTION.search(text, heading.end())
    end = following.start() if following else len(text)
    body = text[heading.end():end]
    # The link definitions at the end of the file stay where they are.
    links = ''
    if following is None:
        definitions = re.search(r'\n(?:\[[^\]\n]+\]: \S+\n?)+\Z', body)
        if definitions:
            links, body = body[definitions.start():], body[:definitions.start()]
    fragments = read_fragments(root)
    parts = [part for part in (body.strip('\n'), render(fragments)) if part]
    if not parts:
        raise ChangelogError('Nothing to release: [Unreleased] is empty and there are no fragments')
    section = f'## [{version}] - {date}\n\n' + '\n\n'.join(parts) + '\n'
    rest = text[end:]
    updated = (text[:heading.end()] + '\n\n' + section
               + ('\n' + rest if rest else '') + links)
    path.write_text(updated, encoding='utf-8')
    return [root / FRAGMENT_DIR / name for _, name, _ in fragments]


def changed_files(root: Path, base: str) -> list[tuple[str, str]]:
    """(status, path) for each file the branch changes relative to base."""
    output = subprocess.run(
        ['git', '-C', str(root), 'diff', '--name-status', '-z', '--no-renames', '--no-ext-diff', base, 'HEAD', '--'],
        check=True, capture_output=True,
    ).stdout.decode('utf-8', 'surrogateescape')
    fields = output.split('\0')
    if fields and fields[-1] == '':
        fields.pop()
    if len(fields) % 2:
        raise ChangelogError('Unreadable git diff output')
    return [(fields[i], fields[i + 1]) for i in range(0, len(fields), 2)]


def require(root: Path, base: str) -> str:
    """Pass a branch that adds an entry or changes nothing user-visible."""
    changes = changed_files(root, base)
    if not any(not EXEMPT.match(path) for _, path in changes):
        return 'No user-visible change in this branch; no entry needed.'
    # Validate every fragment, so a release never trips over a broken one.
    read_fragments(root)
    prefix = FRAGMENT_DIR + '/'
    added = [path for status, path in changes
             if status in ('A', 'M') and path.startswith(prefix)
             and '/' not in path[len(prefix):] and path[len(prefix):] not in NOT_FRAGMENTS]
    if added:
        return 'Changelog fragment: ' + ', '.join(added)
    if any(path == CHANGELOG and status == 'M' for status, path in changes):
        return f'{CHANGELOG} updated.'
    raise ChangelogError(
        f'This branch changes behaviour but adds no changelog entry. Add {FRAGMENT_DIR}/<slug>.<category>.md '
        f'(categories: {", ".join(CATEGORIES)}), or apply the no-changelog label.')


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('--root', type=Path, default=ROOT, help=argparse.SUPPRESS)
    commands = parser.add_subparsers(dest='command', required=True)
    commands.add_parser('check', help='validate every fragment')
    gate = commands.add_parser('require', help='fail a branch with a user-visible change and no entry')
    gate.add_argument('--base', required=True, help='commit the branch is compared with')
    commands.add_parser('preview', help='print the entries the next release will gain')
    cut = commands.add_parser('release', help='assemble [Unreleased] and the fragments into a version section')
    cut.add_argument('version')
    cut.add_argument('--date', default=datetime.datetime.now(datetime.timezone.utc).date().isoformat())
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        if args.command == 'check':
            print(f'OK: {len(read_fragments(root))} changelog fragments')
        elif args.command == 'require':
            print(require(root, args.base))
        elif args.command == 'preview':
            print(render(read_fragments(root)))
        else:
            removed = release(root, args.version, args.date)
            for path in removed:
                path.unlink()
            print(f'Wrote [{args.version}] to {CHANGELOG} and removed {len(removed)} fragments:')
            for path in removed:
                print('  ' + path.relative_to(root).as_posix())
    except ChangelogError as error:
        for problem in error.problems:
            print('::error::' + annotation(problem) if args.command == 'require' else 'ERROR: ' + problem, file=sys.stderr)
        return 1
    except subprocess.CalledProcessError as error:
        print('ERROR: git failed: ' + (error.stderr or b'').decode('utf-8', 'replace').strip(), file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
