# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Assert the installed database matches the checked-in audit schema."""
import json


def assert_clean_schema_audit(harness, label):
    """Run the production Symfony audit and fail on any reported drift."""
    from harness import CONTROLLER_ROOT

    baseline = CONTROLLER_ROOT / 'docs/audit_schema.sql'
    if not baseline.is_file():
        raise RuntimeError('Checked-in audit schema is missing: ' + str(baseline))
    harness.command('mkdir', '-p', '/var/www/html/docs')
    harness.compose('cp', str(baseline), 'web:/tmp/kadupul-audit-schema.sql')
    harness.command('cp', '/tmp/kadupul-audit-schema.sql', '/var/www/html/docs/audit_schema.sql')

    result = harness.php('bin/console', 'kadupul:database:audit', '--report', '--json', '--as=admin')
    if result['exit'] != 0:
        raise RuntimeError(label + ' schema audit command failed: ' + _diagnostic(result))
    try:
        report = json.loads(result['stdout'])
    except json.JSONDecodeError as error:
        raise RuntimeError(label + ' schema audit did not return JSON: ' + _diagnostic(result)) from error

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


def _diagnostic(result):
    """Keep CI failure evidence useful without dumping every audit table."""
    try:
        report = json.loads(result['stdout'])
    except (json.JSONDecodeError, TypeError):
        report = {}
    tables = report.get('tables', [])
    flagged = [
        {'name': table.get('name'), 'errors': table.get('errors'), 'warnings': table.get('warnings'),
         'findings': table.get('findings', [])}
        for table in tables if isinstance(table, dict)
        and (table.get('errors') or table.get('warnings') or table.get('findings'))
    ] if isinstance(tables, list) else []

    return json.dumps({'exit': result.get('exit'), 'status': report.get('status'),
                        'baseline': report.get('baseline'), 'flagged_tables': flagged,
                        'stderr': result.get('stderr', '')}, sort_keys=True)
