# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Assert the installed database matches the checked-in audit schema."""
import json


def assert_clean_schema_audit(harness, label):
    """Run the production Symfony audit and fail on any reported drift."""
    _stage_audit_baseline(harness)

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


def _stage_audit_baseline(harness):
    """Place the repository's baseline in the image, which intentionally omits docs/."""
    from harness import CONTROLLER_ROOT

    baseline = CONTROLLER_ROOT / 'docs/audit_schema.sql'
    if not baseline.is_file():
        raise RuntimeError('Checked-in audit schema is missing: ' + str(baseline))
    harness.command('mkdir', '-p', '/var/www/html/docs')
    harness.compose('cp', str(baseline), 'web:/tmp/kadupul-audit-schema.sql')
    harness.command('cp', '/tmp/kadupul-audit-schema.sql', '/var/www/html/docs/audit_schema.sql')


def assert_baseline_reproducible(harness):
    """Regenerate baseline rows from the fresh cacti.sql install and compare them."""
    _stage_audit_baseline(harness)
    result = harness.php('bin/console', 'kadupul:database:audit', '--load', '--json', '--as=admin')
    if result['exit'] != 0:
        raise RuntimeError('Fresh install baseline generation failed: ' + _diagnostic(result))
    try:
        generated = json.loads(result['stdout'])
    except json.JSONDecodeError as error:
        raise RuntimeError('Baseline generation did not return JSON: ' + _diagnostic(result)) from error
    if generated.get('status') != 'ok' or generated.get('exported') is not True:
        raise RuntimeError('Fresh install baseline was not exported: ' + _diagnostic(result))

    parse = r'''require 'include/vendor/autoload.php';
$baseline = Kadupul\Platform\Domain\Schema\AuditSchemaDump::parse(file_get_contents($argv[1]));
$columns = array_map(static fn ($row) => $row->row(), $baseline->columnRows);
usort($columns, static fn ($a, $b) => [$a['table_name'], $a['table_sequence'], $a['table_field']] <=> [$b['table_name'], $b['table_sequence'], $b['table_field']]);
$indexes = array_map(static function ($row) { $values = $row->row(); unset($values['idx_cardinality']); return $values; }, $baseline->indexRows);
usort($indexes, static fn ($a, $b) => [strtolower($a['idx_table_name']), strtolower($a['idx_key_name']), $a['idx_seq_in_index'], strtolower($a['idx_column_name'])] <=> [strtolower($b['idx_table_name']), strtolower($b['idx_key_name']), $b['idx_seq_in_index'], strtolower($b['idx_column_name'])]);
echo json_encode(['columns' => $columns, 'indexes' => $indexes], JSON_THROW_ON_ERROR);'''
    parsed = harness.php('-r', parse, '/tmp/kadupul-audit-schema.sql')
    if parsed['exit'] != 0:
        raise RuntimeError('Committed audit baseline could not be parsed: ' + json.dumps(parsed))
    try:
        expected = json.loads(parsed['stdout'])
    except json.JSONDecodeError as error:
        raise RuntimeError('Committed audit baseline parser did not return JSON') from error

    actual = {
        'columns': harness.rows("SELECT JSON_OBJECT('table_name',table_name,'table_sequence',table_sequence,'table_field',table_field,'table_type',table_type,'table_null',table_null,'table_key',table_key,'table_default',table_default,'table_extra',table_extra,'table_collation',table_collation) FROM table_columns ORDER BY table_name,table_sequence,table_field"),
        'indexes': harness.rows("SELECT JSON_OBJECT('idx_table_name',idx_table_name,'idx_non_unique',idx_non_unique,'idx_key_name',idx_key_name,'idx_seq_in_index',idx_seq_in_index,'idx_column_name',idx_column_name,'idx_collation',idx_collation,'idx_sub_part',idx_sub_part,'idx_packed',idx_packed,'idx_null',idx_null,'idx_index_type',idx_index_type,'idx_comment',idx_comment) FROM table_indexes ORDER BY idx_table_name,idx_key_name,idx_seq_in_index,idx_column_name"),
    }
    if actual != expected:
        raise RuntimeError('Generated audit baseline differs from docs/audit_schema.sql: ' + json.dumps({
            'expected_columns': len(expected.get('columns', [])), 'actual_columns': len(actual['columns']),
            'expected_indexes': len(expected.get('indexes', [])), 'actual_indexes': len(actual['indexes']),
            'column_sample': _first_difference(expected.get('columns', []), actual['columns']),
            'index_sample': _first_difference(expected.get('indexes', []), actual['indexes']),
        }, sort_keys=True))

    summary = {'columns': len(actual['columns']), 'indexes': len(actual['indexes']),
               'cardinality': 'ignored because it varies with database statistics'}
    print('PASS Fresh cacti.sql baseline reproduces docs/audit_schema.sql: ' + json.dumps(summary, sort_keys=True), flush=True)
    return summary


def _first_difference(expected, actual):
    for index, pair in enumerate(zip(expected, actual)):
        if pair[0] != pair[1]:
            return {'row': index, 'expected': pair[0], 'actual': pair[1]}
    if len(expected) != len(actual):
        return {'row': min(len(expected), len(actual)), 'expected_count': len(expected), 'actual_count': len(actual)}

    return None


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
