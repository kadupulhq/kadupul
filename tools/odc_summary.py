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

A field is read from a "### <label>" section of the body, which the form
writes and a maintainer can add to an issue filed without it. "_No response_"
and an absent section both count as unclassified.
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
SECTION = re.compile(r'^###\s+(.+?)\s*$', re.MULTILINE)


def classify(body: str) -> dict[str, str]:
    """Read the ODC fields from an issue body; the short name drops the explanation in parentheses."""
    sections: dict[str, str] = {}
    headings = list(SECTION.finditer(body or ''))
    for index, heading in enumerate(headings):
        end = headings[index + 1].start() if index + 1 < len(headings) else len(body)
        sections[heading.group(1).lower()] = body[heading.end():end].strip()
    result = {}
    for key, label in FIELDS:
        value = sections.get(label.lower(), '')
        value = value.splitlines()[0].strip() if value else ''
        if value in ('', '_No response_', 'None'):
            result[key] = UNCLASSIFIED
        else:
            result[key] = re.sub(r'\s*\(.*\)$', '', value)
    return result


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
