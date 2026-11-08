#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Count bug issues by their Orthogonal Defect Classification fields.

Chillarege et al., "Orthogonal Defect Classification" (IBM, IEEE TSE 1992),
classify each defect by what the fix changed (type) and what exposed it
(trigger). Counts over many bugs show where defects come from, which a single
review cannot. The bug report form asks for type, qualifier, trigger and
origin; this reads them back from issue bodies.

Export the issues with the GitHub CLI and pass the file:

    gh issue list --repo kadupulhq/kadupul --label bug --state all --limit 1000 \\
        --json number,title,state,body > bugs.json
    python3 tools/odc_summary.py bugs.json

A field is read from the first "### <label>" section of the body outside a
fenced code block, which the form writes and a maintainer can add to an issue
filed without it. Only the form's dropdown options count, either in full or as
the short name before the explanation in parentheses; any other value counts
as "other". "_No response_", an absent section and the origin "Not sure" count
as unclassified.
"""
from __future__ import annotations

import argparse
from collections import Counter
import json
from pathlib import Path
import re
import sys

FIELDS = (('type', 'Defect type'), ('qualifier', 'Defect qualifier'), ('trigger', 'Trigger'), ('origin', 'Origin'))
UNCLASSIFIED = 'unclassified'
OTHER = 'other'
# The short names of the bug form's dropdown options; tests/tools/test_odc_summary.py keeps them in step with the form.
OPTIONS = {
    'type': ('Assignment or initialization', 'Checking', 'Algorithm or method', 'Function or class', 'Interface',
             'Timing or serialization', 'Build, package or merge', 'Documentation'),
    'qualifier': ('Missing', 'Incorrect', 'Extraneous'),
    'trigger': ('Normal operation', 'Startup, restart or upgrade', 'Recovery or error handling', 'Workload or stress',
                'Software configuration', 'Hardware or platform', 'Backward compatibility', 'Lateral compatibility',
                'Rare situation'),
    'origin': ('Inherited from Cacti, unchanged', 'Ported from upstream Cacti', 'Kadupul change on the 1.2 maintenance line',
               'Symfony migration', 'New Kadupul feature', 'Not sure'),
}
# Values the form offers for "I do not know", which say nothing about the defect.
UNKNOWN = {'origin': 'Not sure'}
SECTION = re.compile(r'^###\s+(.+?)\s*$')
FENCE = re.compile(r'^ {0,3}(`{3,}|~{3,})')


def sections(body: str) -> dict[str, str]:
    """Map each "### " heading outside a fenced block to the first non-empty line under it.

    The body is untrusted reporter text. Only the first section of each name
    counts, so a heading repeated later, for example inside pasted log output,
    cannot override the form's answer.
    """
    found: dict[str, str | None] = {}
    current = None
    fence = ''
    for line in (body or '').splitlines():
        marker = FENCE.match(line)
        if fence:
            if marker and marker.group(1)[0] == fence[0] and len(marker.group(1)) >= len(fence) \
                    and not line.strip().lstrip(fence[0]):
                fence = ''
            continue
        heading = SECTION.match(line)
        if heading:
            name = heading.group(1).lower()
            # None marks a section seen but not yet answered; a repeated heading is ignored.
            current = None if name in found else name
            if current is not None:
                found[current] = None
            continue
        if marker:
            fence = marker.group(1)
        if current is not None and found[current] is None and line.strip():
            found[current] = line.strip()
    return {name: value or '' for name, value in found.items()}


def option(key: str, value: str) -> str:
    """Return the short dropdown option a value names, OTHER when it names none, or UNCLASSIFIED."""
    if value in ('', '_No response_', 'None'):
        return UNCLASSIFIED
    for name in OPTIONS[key]:
        if value == name or value.startswith(name + ' ('):
            return UNCLASSIFIED if UNKNOWN.get(key) == name else name
    return OTHER


def classify(body: str) -> dict[str, str]:
    """Read the ODC fields from an issue body; the short name drops the explanation in parentheses."""
    found = sections(body)
    return {key: option(key, found.get(label.lower(), '')) for key, label in FIELDS}


def table(title: str, counter: Counter, total: int) -> list[str]:
    lines = [f'### {title}', '', '| Value | Issues | Share |', '| --- | ---: | ---: |']
    for value, count in sorted(counter.items(), key=lambda item: (item[0] == UNCLASSIFIED, -item[1], item[0])):
        lines.append(f'| {value} | {count} | {count / total:.1%} |')
    return lines + ['']


def summarize(issues: list[dict]) -> str:
    if not issues:
        raise ValueError('no issues in the export')
    rows = []
    for issue in issues:
        if not isinstance(issue, dict) or 'number' not in issue:
            raise ValueError('each issue needs a number; export with --json number,title,state,body')
        rows.append((issue, classify(issue.get('body') or '')))
    total = len(rows)
    classified = sum(1 for _, fields in rows if fields['type'] != UNCLASSIFIED)
    lines = [f'{total} bug issues, {classified} with a defect type.', '']
    for key, label in FIELDS:
        lines += table(label, Counter(fields[key] for _, fields in rows), total)
    pairs = Counter((fields['type'], fields['trigger']) for _, fields in rows if fields['type'] != UNCLASSIFIED)
    if pairs:
        lines += ['### Defect type by trigger', '', '| Defect type | Trigger | Issues |', '| --- | --- | ---: |']
        lines += [f'| {kind} | {trigger} | {count} |' for (kind, trigger), count in pairs.most_common()]
        lines.append('')
    return '\n'.join(lines)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('export', type=Path, help='JSON from gh issue list --json number,title,state,body')
    arguments = parser.parse_args(argv)
    try:
        issues = json.loads(arguments.export.read_text())
        if not isinstance(issues, list):
            raise ValueError('the export must be a JSON list')
        print(summarize(issues))
    except (OSError, ValueError) as error:
        print(f'ERROR: {error}', file=sys.stderr)
        return 2
    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
