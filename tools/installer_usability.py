#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Validate declared operator observations; never certify human participation."""
import argparse
import json
from pathlib import Path
import re

TASKS = ('prerequisites', 'configure', 'review', 'recover', 'verify', 'support')
BLOCKERS = {'unintended_execution', 'inaccessible_control',
            'misunderstood_partial_failure', 'secret_disclosure'}
ISSUES = BLOCKERS | {'confusing_guidance'}


def template(revision: str) -> dict:
    if re.fullmatch(r'[a-f0-9]{40}', revision) is None:
        raise ValueError('Invalid source revision.')
    return {'format_version': 1, 'source_revision': revision,
            'environment': {'php': None, 'browser': None}, 'sessions': []}


def exact_keys(value: object, keys: set[str]) -> bool:
    return isinstance(value, dict) and set(value) == keys


def assess(report: object) -> dict:
    if not exact_keys(report, {'format_version', 'source_revision',
                               'environment', 'sessions'}):
        raise ValueError('Invalid observation report.')
    if type(report['format_version']) is not int or report['format_version'] != 1:
        raise ValueError('Unsupported observation format.')
    if (not isinstance(report['source_revision'], str)
            or re.fullmatch(r'[a-f0-9]{40}', report['source_revision']) is None):
        raise ValueError('Invalid source revision.')
    sessions = report['sessions']
    if not isinstance(sessions, list) or len(sessions) > 20:
        raise ValueError('Expected at most twenty observation sessions.')
    environment = report['environment']
    if not exact_keys(environment, {'php', 'browser'}):
        raise ValueError('Invalid environment evidence.')
    php = environment['php']
    browser = environment['browser']
    if (php is not None and (not isinstance(php, str)
                            or re.fullmatch(r'8\.4\.[0-9]{1,3}', php) is None)
            or browser not in [None, 'chromium', 'firefox', 'safari', 'edge']
            or sessions and (php is None or browser is None)):
        raise ValueError('Record the observed PHP and browser environment.')
    identifiers, methods, locales, findings = set(), set(), set(), set()
    incomplete = False
    assisted = False
    for session in sessions:
        if not exact_keys(session, {'id', 'consent', 'first_time', 'human',
                                    'locale', 'input_method', 'tasks'}):
            raise ValueError('Invalid observation session.')
        identifier = session['id']
        if (not isinstance(identifier, str)
                or re.fullmatch(r'[a-f0-9]{32}', identifier) is None
                or identifier in identifiers):
            raise ValueError('Use distinct random anonymous session identifiers.')
        identifiers.add(identifier)
        if any(session[key] is not True for key in ['consent', 'first_time', 'human']):
            raise ValueError('Sessions require consented first-time human observations.')
        locale, method = session['locale'], session['input_method']
        if locale not in ['en', 'en-US', 'fr', 'fr-FR'] or method not in ['keyboard', 'pointer', 'assistive']:
            raise ValueError('Invalid observation locale or input method.')
        locales.add(locale.split('-')[0])
        methods.add(method)
        if not exact_keys(session['tasks'], set(TASKS)):
            raise ValueError('Record every required task.')
        for task in session['tasks'].values():
            if not exact_keys(task, {'outcome', 'issues'}):
                raise ValueError('Invalid task observation.')
            if task['outcome'] not in ['unassisted', 'assisted', 'blocked', 'not_run']:
                raise ValueError('Invalid task outcome.')
            issues = task['issues']
            if (not isinstance(issues, list) or len(issues) > len(ISSUES)
                    or any(not isinstance(issue, str) or issue not in ISSUES for issue in issues)
                    or len(set(issues)) != len(issues)):
                raise ValueError('Use only defined issue codes; omit private free text.')
            findings.update(issues)
            incomplete |= task['outcome'] == 'not_run'
            assisted |= task['outcome'] in ['assisted', 'blocked']
    enough = len(sessions) >= 2 and 'keyboard' in methods and 'fr' in locales
    status = ('blocked' if findings & BLOCKERS else
              'needs_sessions' if not enough or incomplete else
              'needs_review' if findings or assisted else 'awaiting_independent_review')
    return {'source_revision': report['source_revision'], 'sessions': len(sessions),
            'status': status, 'issue_codes': sorted(findings),
            'release_accepted': False,
            'participation': 'declared_by_operator_not_independently_verified'}


def unique_object(pairs: list[tuple]) -> dict:
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate report field.')
        result[key] = value
    return result


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument('--report', type=Path)
    group.add_argument('--template', action='store_true')
    parser.add_argument('--revision', help='Exact tested source commit for a new template')
    arguments = parser.parse_args()
    try:
        if arguments.template:
            result = template(arguments.revision or '')
            code = 0
        else:
            with arguments.report.open('rb') as stream:
                contents = stream.read(65537)
            if len(contents) > 65536:
                raise ValueError('Observation report exceeds 64 KiB.')
            result = assess(json.loads(contents, object_pairs_hook=unique_object))
            code = 0 if result['status'] == 'awaiting_independent_review' else 1
        print(json.dumps(result, indent=2))
        return code
    except (OSError, ValueError, TypeError, RecursionError):
        # Invalid reports may contain credentials or participant information.
        print(json.dumps({'error': 'invalid_usability_report'}))
        return 2


if __name__ == '__main__':
    raise SystemExit(main())
