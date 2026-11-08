#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Rank commits by defect risk to choose which ones get a review.

Each commit is measured by signals that predicted defects in published
studies, then ranked by their average percentile across the range:

- relative churn: lines added and deleted over the touched files' size at the
  parent (Nagappan & Ball, "Use of Relative Code Churn Measures to Predict
  System Defect Density", ICSE 2005);
- spread: number of touched files and directories (same study);
- prior fixes: fix and revert commits that touched the same files in the
  preceding window (Kim et al., "Predicting Faults from Cached History",
  ICSE 2007);
- low ownership: the author's share of earlier commits to the touched files,
  and how many minor contributors those files have (Bird et al., "Don't Touch
  My Code!", FSE 2011);
- untested change: production files changed with no test file in the commit;
- trust boundary: the commit touches authentication, session, database,
  installer, process, import or collection code, which AGENTS.md asks
  reviewers to trace end to end.

Generated and vendored paths (catalogs, baselines, locks) are left out of
every signal. The score orders commits for a reviewer; it is not a defect
probability and says nothing about a commit it ranks low.
"""
from __future__ import annotations

import argparse
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone
import json
from pathlib import PurePosixPath
import re
import subprocess
import sys

GENERATED = re.compile(
    r'^(include/vendor/|include/fa/|include/js/|node_modules/|locales/|tests/security/baselines/'
    r'|docs/migration/functions-inventory/)|(^|/)(composer|package)(-lock)?\.(json|lock)$|\.(min\.js|min\.css|mo|po|pot)$')
TEST = re.compile(r'^(tests/|.*Test\.php$|.*_test\.py$|.*/test_[^/]*\.py$)')
PROSE = re.compile(r'\.(md|txt|rst)$|^docs/')
TRUST_BOUNDARY = re.compile(
    r'(^|/)(auth[^/]*\.php|lib/(auth|database|import|boost|poller|rrd|snmp|ping|installer|functions)\.php'
    r'|install/|src/[^/]*/(Security|Authentication|Session|Database|Installer|Process)[^/]*/'
    r'|src/(Identity|Security|Session|Installer|Database)/|poller[^/]*\.php|cmd\.php|remote_agent\.php|script_server\.php)')
FIX_SUBJECT = re.compile(r'^(fix|revert)(\(|:|!)|^Revert ', re.IGNORECASE)
PR_NUMBER = re.compile(r'\(#(\d+)\)\s*$')
MINOR_SHARE = 0.05
SIGNALS = ('relative_churn', 'files', 'directories', 'prior_fixes', 'low_ownership', 'minor_contributors',
           'untested', 'trust_boundary')


@dataclass
class Commit:
    sha: str
    author: str
    when: datetime
    subject: str
    files: dict[str, int] = field(default_factory=dict)  # path -> lines added + deleted


@dataclass
class Assessment:
    commit: Commit
    signals: dict[str, float]
    score: float = 0.0
    drivers: list[str] = field(default_factory=list)


def git(repo: str, *arguments: str) -> str:
    result = subprocess.run(['git', '-C', repo, *arguments], capture_output=True, text=True, check=False)
    if result.returncode != 0:
        raise RuntimeError(f'git {" ".join(arguments)} failed: {result.stderr.strip()}')
    return result.stdout


def read_commits(repo: str, *selection: str) -> list[Commit]:
    """First-parent commits, newest first, with per-file churn from --numstat."""
    output = git(repo, 'log', '--first-parent', '--no-merges', '--numstat', '--format=%x1e%H%x1f%ae%x1f%cI%x1f%s', *selection)
    commits = []
    for record in output.split('\x1e')[1:]:
        header, _, body = record.partition('\n')
        sha, author, when, subject = header.split('\x1f', 3)
        commit = Commit(sha, author.lower(), datetime.fromisoformat(when), subject)
        for line in body.splitlines():
            parts = line.split('\t')
            if len(parts) != 3:
                continue
            added, deleted, path = parts
            if ' => ' in path:  # rename: keep the new path
                path = re.sub(r'\{[^{}]* => ([^{}]*)\}', r'\1', path).replace('//', '/')
                path = path.split(' => ')[-1]
            # Binary files report '-'; count them as one changed line.
            commit.files[path] = (int(added) if added.isdigit() else 1) + (int(deleted) if deleted.isdigit() else 0)
        commits.append(commit)
    return commits


def line_counts(repo: str, revision: str, paths: list[str]) -> dict[str, int]:
    """Line count of each path at a revision; paths absent there count as zero."""
    if not paths:
        return {}
    request = ''.join(f'{revision}:{path}\n' for path in paths)
    result = subprocess.run(['git', '-C', repo, 'cat-file', '--batch'], input=request.encode(), capture_output=True, check=False)
    if result.returncode != 0:
        raise RuntimeError('git cat-file --batch failed: ' + result.stderr.decode(errors='replace').strip())
    counts: dict[str, int] = {}
    data = result.stdout
    position = 0
    for path in paths:
        end = data.index(b'\n', position)
        header = data[position:end].split()
        position = end + 1
        if len(header) >= 2 and header[1] == b'missing':
            counts[path] = 0
            continue
        size = int(header[2])
        counts[path] = data[position:position + size].count(b'\n')
        position += size + 1
    return counts


def assess(repo: str, commits: list[Commit], history: list[Commit], window_days: int) -> list[Assessment]:
    """Measure each commit against the commits that came before it."""
    ordered = sorted(history, key=lambda item: item.when)
    assessments = []
    for commit in commits:
        paths = [path for path in commit.files if not GENERATED.search(path)]
        production = [path for path in paths if not TEST.search(path) and not PROSE.search(path)]
        earlier = [item for item in ordered if item.when < commit.when and item.sha != commit.sha]
        since = commit.when - timedelta(days=window_days)

        parent_lines = line_counts(repo, commit.sha + '^', paths) if paths else {}
        churn = sum(commit.files[path] for path in paths)
        relative = churn / max(1, sum(parent_lines.values()))

        prior_fixes = sum(1 for item in earlier if item.when >= since and FIX_SUBJECT.search(item.subject)
                          and any(path in item.files for path in production))

        owner_shares = []
        minor = 0
        for path in production:
            authors: dict[str, int] = {}
            for item in earlier:
                if path in item.files:
                    authors[item.author] = authors.get(item.author, 0) + 1
            total = sum(authors.values())
            if total == 0:
                continue
            owner_shares.append(authors.get(commit.author, 0) / total)
            minor += sum(1 for count in authors.values() if count / total < MINOR_SHARE)

        signals = {
            'relative_churn': relative,
            'files': float(len(paths)),
            'directories': float(len({str(PurePosixPath(path).parent) for path in paths})),
            'prior_fixes': float(prior_fixes),
            'low_ownership': 1.0 - min(owner_shares) if owner_shares else 0.0,
            'minor_contributors': float(minor),
            'untested': 1.0 if production and not any(TEST.search(path) for path in paths) else 0.0,
            'trust_boundary': float(sum(1 for path in production if TRUST_BOUNDARY.search(path))),
        }
        assessments.append(Assessment(commit, signals))
    rank(assessments)
    return assessments


def rank(assessments: list[Assessment]) -> None:
    """Score is the mean percentile of each signal across the range, highest first."""
    count = len(assessments)
    if count == 0:
        return
    for name in SIGNALS:
        values = sorted(item.signals[name] for item in assessments)
        for item in assessments:
            value = item.signals[name]
            # Mid-rank percentile: ties share a value, and a signal that is zero everywhere adds nothing.
            below = sum(1 for other in values if other < value)
            equal = sum(1 for other in values if other == value)
            percentile = (below + (equal - 1) / 2) / max(1, count - 1) if value > 0 else 0.0
            item.score += percentile / len(SIGNALS)
            if value > 0 and percentile >= 0.8:
                item.drivers.append(name)
    assessments.sort(key=lambda item: (-item.score, item.commit.when))


def report(assessments: list[Assessment], limit: int) -> str:
    lines = ['| Rank | Commit | PR | Score | Main drivers | Subject |', '| ---: | --- | ---: | ---: | --- | --- |']
    for index, item in enumerate(assessments[:limit], start=1):
        number = PR_NUMBER.search(item.commit.subject)
        subject = PR_NUMBER.sub('', item.commit.subject).replace('|', '\\|')
        lines.append(f'| {index} | {item.commit.sha[:10]} | {"#" + number[1] if number else ""} | {item.score:.2f} | '
                     f'{", ".join(item.drivers) or "-"} | {subject} |')
    return '\n'.join(lines)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('revisions', nargs='?', default='origin/main~100..origin/main',
                        help='commit range to rank (default: the last 100 commits on origin/main)')
    parser.add_argument('--repo', default='.', help='repository path')
    parser.add_argument('--window-days', type=int, default=180, help='history window for prior fixes (default 180)')
    parser.add_argument('--limit', type=int, default=25, help='rows to print (default 25)')
    parser.add_argument('--json', action='store_true', help='print every assessment as JSON')
    arguments = parser.parse_args(argv)
    if arguments.window_days < 1 or arguments.limit < 1:
        parser.error('--window-days and --limit must be positive')

    if arguments.revisions.startswith('-'):
        parser.error('the revision range must not start with "-"')

    try:
        commits = read_commits(arguments.repo, arguments.revisions)
        if not commits:
            raise RuntimeError(f'no commits in {arguments.revisions}')
        oldest = min(item.when for item in commits) - timedelta(days=arguments.window_days)
        newest = max(item.when for item in commits)
        # The tip of A..B or A...B; a single revision is its own tip.
        tip = re.split(r'\.\.\.?', arguments.revisions)[-1] or 'HEAD'
        history = read_commits(arguments.repo, f'--since={oldest.astimezone(timezone.utc).isoformat()}',
                               f'--until={newest.astimezone(timezone.utc).isoformat()}', tip)
        known = {item.sha: item for item in history + commits}
        assessments = assess(arguments.repo, commits, list(known.values()), arguments.window_days)
    except (RuntimeError, ValueError) as error:
        print(f'ERROR: {error}', file=sys.stderr)
        return 2

    if arguments.json:
        print(json.dumps([{'sha': item.commit.sha, 'subject': item.commit.subject, 'score': round(item.score, 4),
                           'drivers': item.drivers, 'signals': item.signals} for item in assessments], indent=2))
    else:
        print(report(assessments, arguments.limit))
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
