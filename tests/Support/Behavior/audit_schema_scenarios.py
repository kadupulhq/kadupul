# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Assert the installed database matches the checked-in audit schema."""
import json


def assert_clean_schema_audit(harness, label):
    """Run the production Symfony audit and fail on any reported drift."""
    result = harness.php('bin/console', 'kadupul:database:audit', '--report', '--json', '--as=admin')
    if result['exit'] != 0:
        raise RuntimeError(label + ' schema audit command failed: ' + json.dumps(result))
    try:
        report = json.loads(result['stdout'])
    except json.JSONDecodeError as error:
        raise RuntimeError(label + ' schema audit did not return JSON: ' + json.dumps(result)) from error

    tables = report.get('tables')
    findings = [finding for table in tables if isinstance(table, dict)
                for finding in table.get('findings', [])] if isinstance(tables, list) else []
    dirty = [table for table in tables if not isinstance(table, dict)
             or table.get('errors') != 0 or table.get('warnings') != 0
             or table.get('findings') != []] if isinstance(tables, list) else ['missing table results']
    if (report.get('status') != 'ok' or report.get('baseline') != 'loaded'
            or not isinstance(tables, list) or not tables or dirty):
        raise RuntimeError(label + ' schema audit found drift: ' + json.dumps({
            'status': report.get('status'), 'baseline': report.get('baseline'),
            'flagged_tables': dirty, 'findings': findings,
        }, sort_keys=True))

    summary = {'status': report['status'], 'baseline': report['baseline'], 'tables': len(tables), 'findings': 0}
    print('PASS ' + label + ' database audit: ' + json.dumps(summary, sort_keys=True), flush=True)
    return summary
