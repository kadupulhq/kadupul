# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for cli/audit_database.php.

Each case restores the same starting state, runs the frozen original, restores
it again, runs the shim, and compares stdout, stderr, the exit code, the
schema, the audit tables, docs/audit_schema.sql and cacti.log, whole lines
with only the time of day masked.
"""
import json
from pathlib import Path
import re
import tempfile

from cli_parity_scenarios import ROOT, clock_free, install_original, normalise, run
from cli_schema_scenarios import REFUSED, compare, realm_fallback, verify_refusals

AUDIT_ORIGINAL = 'tests/Fixtures/legacy-cli/audit_database.php'
AUDIT_SHIM = 'cli/audit_database.php'
AUDIT_UTILITY = 'Kadupul Database Audit Utility'
DOCS = f'{ROOT}/docs'
DUMP = f'{DOCS}/audit_schema.sql'
# The image leaves docs/ out, so the checked-in baseline is copied in, and
# any docs/ the image does have is moved aside for the scenario.
PRISTINE = '/tmp/kadupul-parity-audit-schema.sql'
# The same file with poller_id_last_updated given no index type, which the
# original turned into an ADD INDEX without its closing parenthesis.
UNTYPED = '/tmp/kadupul-parity-untyped-schema.sql'
UNTYPED_ROWS = re.compile(r"^(INSERT INTO `table_indexes` VALUES \('poller_command',1,'poller_id_last_updated',.*),'BTREE',''\);$", re.M)
DOCS_ASIDE = '/tmp/kadupul-parity-docs'
# The image's MariaDB 11.8 client insists on TLS, which the harness server
# does not offer, and the original gave its client no host, relying on the
# client's default. Both the original's mariadb and mariadb-dump read this
# file; the shim names the host itself and takes only skip-ssl from it.
CLIENT_CONFIG = '/etc/mysql/conf.d/zz-kadupul-parity.cnf'
CONFIG = f'{ROOT}/include/config.php'
CONFIG_ASIDE = '/tmp/kadupul-parity-config.php'
# Backups live in their own database, so neither script audits or imports them.
BACKUP = 'kadupul_parity_audit'
DRIFTED = 'poller_command'
# poller_command's command column shrank, it lost an index, and it gained a
# column and an index. A changed default would not do: the original reads an
# empty baseline default as true, which any non-empty value equals.
DRIFT = [
    f"ALTER TABLE {DRIFTED} MODIFY command varchar(150) NOT NULL DEFAULT ''",
    f'ALTER TABLE {DRIFTED} ADD COLUMN kadupul_extra int NULL',
    f'ALTER TABLE {DRIFTED} DROP INDEX poller_id_last_updated',
    f'ALTER TABLE {DRIFTED} ADD INDEX kadupul_stray (time)',
]
# Two rows share a path once the unique index is gone, so the server refuses
# the repair's ADD UNIQUE INDEX for both scripts.
FAILING = 'poller_resource_cache'
FAIL = [
    f'ALTER TABLE {FAILING} DROP INDEX path',
    f"INSERT INTO {FAILING} (resource_type, path) VALUES ('script', 'kadupul/parity'), ('script', 'kadupul/parity')",
]
# A baseline column the live table lacks, which the repair adds back.
MISSING_COLUMN = f'ALTER TABLE {FAILING} DROP COLUMN attributes'
# Rows the upgrade and the prune step change; restored before every run.
ROW_TABLES = ['settings', 'plugin_config', 'plugin_hooks', 'plugin_realms', 'plugin_db_changes']
AUDIT_TABLES = ['table_columns', 'table_indexes']
PREVIOUS = '1.2.30'
# The fixture plugin is behind its INFO version and lacks an upgrade function;
# the second row names a directory that does not exist, so the prune step
# removes it.
BEHIND_PLUGINS = ("INSERT INTO plugin_config (directory, name, status, author, webpage, version) VALUES "
                  "('compatibility_test', 'Compatibility', 1, 'Kadupul', 'https://example.invalid', '0.9.0'), "
                  "('kadupul_parity_gone', 'Gone', 1, 'Kadupul', 'https://example.invalid', '1.0')")
# The first statement of the file fails for the client and the parser alike,
# so neither loads a single row.
UNPARSABLE = 'INSERT INTO kadupul_parity_nowhere VALUES (1);'
# Upgrade output and its log carry elapsed seconds, which are clock readings too.
SECONDS = re.compile(r'in \d+\.\d{2} seconds')
# cacti_log(..., true) printed "<date> <time> - UPGRADE ..." to stdout.
STAMP = re.compile(r'^(\S+) \d{2}:\d{2}:\d{2} - ', re.M)
# db_execute() followed each failed statement with a backtrace; the shim logs
# the statement's error line alone, as the other write commands do.
BACKTRACE = ' - CMDPHP SQL Backtrace: '
# The server's answer to the original's untyped ADD INDEX; the shim sends no
# statement for a clause with no typed form, so it logs nothing.
SYNTAX_ERROR = ' - DBCALL ERROR: A DB Exec Failed!, Error: You have an error in your SQL syntax'
# The original piped the file into the client and let its error through.
CLIENT_ERROR = re.compile(r'^-{14}\n.*?\n-{14}\n\nERROR \d+ \([0-9A-Z]+\) at line \d+: [^\n]*\n', re.S | re.M)
# The line after "FATAL: Failed Load the Audit Schema": the client's output
# for the original, the line that did not parse for the shim.
LOAD_ERROR = re.compile(r'^ERROR: .*$', re.M)
# The upgrade stamps settings.install_updated with the time it finished.
UPDATED = re.compile(r'^install_updated\t.*$', re.M)
# --load reads every table before it imports any, so the shim records the two
# audit tables' own indexes with the cardinality of empty tables; the original
# read each table after importing the ones before it.
IMPORTED_CARDINALITY = [
    re.compile(r"^(table_(?:columns|indexes)\t\d+\t[^\t]+\t\d+\t[^\t]+\t[^\t]*\t)\d+", re.M),
    re.compile(r"^(INSERT INTO `table_indexes` VALUES \('table_(?:columns|indexes)',\d+,'[^']*',\d+,'[^']*','[^']*',)\d+", re.M),
]
UNPARSED = 'audit report with an unparsable audit schema'
EXPORT_FAILS = 'audit load with a failing export'

# (label, arguments, starting state). Only argument sets the original accepts
# belong here; shim-only behaviour is checked in its own functions.
AUDIT_CASES = [
    ('audit report on a clean schema', ['--report'], 'clean'),
    ('audit report on a drifted table', ['--report'], 'drifted'),
    ('audit report with alters', ['--report', '--alters'], 'drifted'),
    ('audit alters on a drifted table', ['--alters'], 'drifted'),
    ('audit repair on a drifted table', ['--repair'], 'drifted'),
    ('audit repair with alters', ['--repair', '--alters'], 'drifted'),
    ('audit repair with a failing alter', ['--repair'], 'failing'),
    ('audit repair with a missing column', ['--repair'], 'missing column'),
    ('audit repair with an untyped baseline index', ['--repair'], 'untyped index'),
    ('audit create', ['--create'], 'drifted'),
    ('audit create with alters', ['--create', '--alters'], 'drifted'),
    ('audit load', ['--load'], 'drifted'),
    ('audit load with alters', ['--load', '--alters'], 'drifted'),
    (EXPORT_FAILS, ['--load'], 'dump denied'),
    ('audit load without a docs directory', ['--load'], 'no docs'),
    ('audit report with the audit schema missing', ['--report'], 'no dump'),
    ('audit create with the audit schema missing', ['--create'], 'no dump'),
    (UNPARSED, ['--report'], 'unparsable'),
    ('audit report when table_columns cannot be created', ['--report'], 'create denied'),
    ('audit upgrade required', ['--report'], 'behind'),
    ('audit upgrade from the previous version', ['--upgrade', '--create'], 'behind'),
    ('audit upgrade then report', ['--upgrade', '--report'], 'behind'),
    ('audit upgrade without a mode', ['--upgrade'], 'behind'),
    ('audit no mode', ['--bogus'], 'drifted'),
    ('audit no arguments', [], 'drifted'),
    ('audit version', ['-V'], 'drifted'),
    ('audit help first', ['-h', '--report'], 'drifted'),
    ('audit help after a mode', ['--report', '--help'], 'drifted'),
    ('audit stops at a plain argument', ['x', '--report'], 'drifted'),
]


def as_root(harness, script):
    # docs/, the client configuration and config.php are the image's; the
    # scripts run as www-data and the harness sets them up as root.
    return harness.compose('exec', '-T', 'web', 'sh', '-c', script, check=True)


def touched_tables(harness, version):
    """Every table a repair could change: the two the cases alter, and each
    one the original proposes an alter for on the harness schema."""
    reset(harness, 'failing', [DRIFTED, FAILING], version)
    proposed = run(harness, AUDIT_ORIGINAL, ['--alters'])['stdout']
    tables = re.findall(r'^-- Proposed Alter for Table : (\S+)$', proposed, re.M)
    return sorted(set(tables) | {DRIFTED, FAILING})


def backup(harness, tables):
    harness.sql(f'CREATE DATABASE IF NOT EXISTS {BACKUP};'
                + ''.join(f'CREATE TABLE {BACKUP}.{table} LIKE {table}; INSERT INTO {BACKUP}.{table} SELECT * FROM {table};'
                          for table in tables))


def restore(harness, tables):
    harness.sql(''.join(f'DROP TABLE IF EXISTS {table}; CREATE TABLE {table} LIKE {BACKUP}.{table}; '
                        f'INSERT INTO {table} SELECT * FROM {BACKUP}.{table};' for table in tables)
                + ''.join(f'DELETE FROM {table}; INSERT INTO {table} SELECT * FROM {BACKUP}.{table};' for table in ROW_TABLES))


def grant(harness):
    user = "'cactiuser'@'%'"
    harness.sql(f'GRANT ALL PRIVILEGES ON cacti.* TO {user}')
    return user


def reset(harness, state, tables, version):
    restore(harness, tables)
    user = grant(harness)
    statements = [f'DROP TABLE IF EXISTS {table}' for table in AUDIT_TABLES]
    if state in ('drifted', 'failing', 'untyped index'):
        statements += DRIFT
    if state == 'failing':
        statements += FAIL
    if state == 'missing column':
        statements.append(MISSING_COLUMN)
    if state == 'behind':
        statements.append(BEHIND_PLUGINS)
    if state == 'create denied':
        statements.append(f'REVOKE CREATE ON cacti.* FROM {user}')
    if state == 'dump denied':
        # mysqldump locks the tables it reads, so both dumps fail the same way.
        statements.append(f'REVOKE LOCK TABLES ON cacti.* FROM {user}')
    statements.append(f"UPDATE version SET cacti = '{PREVIOUS if state == 'behind' else version}'")
    harness.sql(';'.join(statements) + ';')
    docs = f'rm -rf {DOCS} && mkdir {DOCS} && '
    if state == 'no docs':
        docs = f'rm -rf {DOCS}'
    elif state == 'no dump':
        docs += f'chown www-data:www-data {DOCS}'
    elif state == 'unparsable':
        docs += f"{{ echo '{UNPARSABLE}'; cat {PRISTINE}; }} > {DUMP} && chown -R www-data:www-data {DOCS}"
    elif state == 'untyped index':
        docs += f'cp -f {UNTYPED} {DUMP} && chown -R www-data:www-data {DOCS}'
    else:
        docs += f'cp -f {PRISTINE} {DUMP} && chown -R www-data:www-data {DOCS}'
    as_root(harness, docs)


def dump_file(harness):
    """docs/ as a listing, and the dump without the line that dates it."""
    listing = harness.command('sh', '-c', f'ls -a {DOCS} 2>/dev/null || true')['stdout']
    return listing, harness.command('sh', '-c', f'grep -v "^-- Dump completed" {DUMP} 2>/dev/null || true')['stdout']


def schema(harness, with_dump=True, imported=False):
    """Every base table's columns, indexes and options, the audit tables' rows,
    the rows the upgrade changes, and docs/audit_schema.sql.

    imported masks the cardinality --load records for the audit tables' own
    indexes, in the rows and in the dump.
    """
    base = "FROM information_schema.{} WHERE TABLE_SCHEMA = DATABASE()"
    columns = harness.sql("SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL'), EXTRA "
                          + base.format('COLUMNS') + ' ORDER BY 1, 3')
    indexes = harness.sql('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE '
                          + base.format('STATISTICS') + ' ORDER BY 1, 2, 3')
    options = harness.sql('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, ROW_FORMAT, TABLE_COMMENT '
                          + base.format('TABLES') + ' ORDER BY 1')
    present = set(harness.sql("SELECT TABLE_NAME " + base.format('TABLES')
                              + " AND TABLE_NAME IN ('table_columns', 'table_indexes')").split())
    rows = ''.join(harness.sql(f'SELECT * FROM {table} ORDER BY 1, 2, 3, 4') for table in AUDIT_TABLES if table in present)
    state = UPDATED.sub('install_updated\tTIME', harness.sql(
        'SELECT cacti FROM version; SELECT name, value FROM settings ORDER BY name; '
        'SELECT directory, status, version FROM plugin_config ORDER BY directory; '
        'SELECT name, hook FROM plugin_hooks ORDER BY id; SELECT plugin, file FROM plugin_realms ORDER BY id'))
    listing, dump = dump_file(harness) if with_dump else ('', '')
    if imported:
        rows = IMPORTED_CARDINALITY[0].sub(r'\1N', rows)
        dump = IMPORTED_CARDINALITY[1].sub(r'\1N', dump)
    return columns, indexes, options, rows, state, listing, dump


def masked(text):
    return SECONDS.sub('in N seconds', STAMP.sub(r'\1 HH:MM:SS - ', normalise(text)))


def log_masked(lines):
    return [SECONDS.sub('in N seconds', line) for line in clock_free(lines, '') if BACKTRACE not in line and SYNTAX_ERROR not in line]


def found(harness):
    return schema(harness), harness.sql("SHOW GRANTS FOR 'cactiuser'@'%'; SHOW DATABASES")


def verify_audit(harness, check, admin):
    install_original(harness, AUDIT_ORIGINAL)
    version = harness.sql('SELECT cacti FROM version').strip()
    # Taken before anything is set up, so a restore that misses a change
    # fails the final check instead of hiding.
    before = found(harness)
    source = Path(__file__).resolve().parents[2] / 'docs/audit_schema.sql'
    harness.compose('cp', str(source), f'web:{PRISTINE}')
    untyped, rows = UNTYPED_ROWS.subn(r"\1,'','');", source.read_text())
    check(rows == 2, 'audit: the checked-in audit schema types poller_id_last_updated as BTREE')
    with tempfile.NamedTemporaryFile('w', suffix='.sql') as file:
        file.write(untyped)
        file.flush()
        harness.compose('cp', file.name, f'web:{UNTYPED}')
    as_root(harness, f'rm -rf {DOCS_ASIDE}; if [ -e {DOCS} ]; then mv {DOCS} {DOCS_ASIDE}; fi; '
                     f"printf '[client]\\nhost=db\\nskip-ssl\\n' > {CLIENT_CONFIG}")
    # The tables the cases change, and the rows, are saved before any case runs.
    tables = [DRIFTED, FAILING]
    harness.sql(f'DROP DATABASE IF EXISTS {BACKUP}')
    backup(harness, tables + ROW_TABLES)
    try:
        proposed = touched_tables(harness, version)
        backup(harness, [table for table in proposed if table not in tables])
        tables = proposed
        verify_audit_cases(harness, check, tables, version)
        verify_audit_shim_only(harness, check, admin, tables, version)
    finally:
        restore(harness, tables)
        grant(harness)
        harness.sql(''.join(f'DROP TABLE IF EXISTS {table};' for table in AUDIT_TABLES)
                    + f"UPDATE version SET cacti = '{version}'; DROP DATABASE IF EXISTS {BACKUP}")
        as_root(harness, f'rm -rf {DOCS} {PRISTINE} {UNTYPED} {CLIENT_CONFIG}; if [ -e {DOCS_ASIDE} ]; then mv {DOCS_ASIDE} {DOCS}; fi')
    check(found(harness) == before, 'audit scenarios leave the schema, settings, grants and docs/ as they found them')


def verify_audit_cases(harness, check, tables, version):
    for label, arguments, state in AUDIT_CASES:
        dumps = []

        def starting(h, state=state, dumps=dumps):
            # What the previous run left in docs/, before it is put back.
            dumps.append(dump_file(h))
            reset(h, state, tables, version)

        unparsed = label == UNPARSED
        stdout = (lambda text: LOAD_ERROR.sub('ERROR: <load error>', masked(text), count=1)) if unparsed else masked
        stderr_filter = (lambda text: CLIENT_ERROR.sub('', text)) if unparsed else None
        # The original truncated the file before its dump failed, so a failed
        # export compares the file on its own below.
        snapshot = (lambda h: schema(h, with_dump=label != EXPORT_FAILS, imported=True)) if '--load' in arguments else schema
        ran = compare(harness, check, label, (AUDIT_ORIGINAL, AUDIT_SHIM), arguments, None, starting, snapshot, AUDIT_UTILITY,
                      stdout=stdout, stderr_filter=stderr_filter, log_filter=log_masked)
        original, shim = ran['original'], ran['shim']
        if label == UNPARSED:
            check('\nERROR: \n' in original['stdout'] and CLIENT_ERROR.search(original['stderr']) is not None
                  and 'FATAL: Failed Load the Audit Schema\nERROR: docs/audit_schema.sql line 1 does not parse\n' in shim['stdout']
                  and shim['stderr'] == '',
                  f'{label}: shim names the line that does not parse instead of the client error')
        if label == EXPORT_FAILS:
            check(dumps[1] != dumps[0] and dump_file(harness) == dumps[0]
                  and shim['stdout'].endswith('Finished Creating Audit Schema with ERROR\n\n'),
                  f'{label}: shim leaves the previous audit schema file where the original truncated it')
        if label == 'audit repair on a drifted table':
            # Matching output is only evidence if the shim changed the drifted table.
            indexes = harness.sql('SELECT INDEX_NAME FROM information_schema.STATISTICS '
                                  f"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{DRIFTED}'").split()
            check('Executing Alter for Table : poller_command - Success\n' in shim['stdout']
                  and 'kadupul_stray' not in indexes and 'poller_id_last_updated' in indexes,
                  'audit repair: shim drops the stray index and restores the drifted table through the kernel container')
        if label == 'audit repair with a failing alter':
            failed = [line for line in ran['shim_log'] if ' - DBCALL ERROR: A DB Exec Failed!, Error: Duplicate entry ' in line]
            check(f'Executing Alter for Table : {FAILING} - Failed\nALTER TABLE `{FAILING}`\n' in shim['stdout']
                  and len(failed) == 1 and any(BACKTRACE in line for line in ran['original_log']),
                  'audit repair with a failing alter: shim logs the refused statement without the backtrace')
        if label == 'audit repair with a missing column':
            check(f'Executing Alter for Table : {FAILING} - Success\n' in shim['stdout']
                  and 'attributes' in harness.sql(f'SHOW COLUMNS FROM {FAILING}').split(),
                  f'{label}: shim adds the column back')
        if label == 'audit repair with an untyped baseline index':
            check(f'Executing Alter for Table : {DRIFTED} - Failed\n' in shim['stdout']
                  and len([line for line in ran['original_log'] if SYNTAX_ERROR in line]) == 1
                  and not any(' - DBCALL ' in line for line in ran['shim_log']),
                  f'{label}: shim sends no statement for an alter with no typed form')
        if label == 'audit report when table_columns cannot be created':
            check(shim['stdout'] == "Failed to create 'table_columns'" and shim['exit'] == 0,
                  f'{label}: shim stops without a trailing newline')
        if label == 'audit upgrade from the previous version':
            check(harness.sql('SELECT cacti FROM version').strip() == version
                  and 'UPGRADE WARNING: Plugin compatibility_test lacks an upgrade function.\n' in shim['stdout']
                  and 'kadupul_parity_gone' not in harness.sql('SELECT directory FROM plugin_config'),
                  'audit upgrade: shim upgrades the database to the code version')
    grant(harness)


def verify_audit_shim_only(harness, check, admin, tables, version):
    reset(harness, 'drifted', tables, version)
    start = schema(harness)
    verify_refusals(harness, check, admin, AUDIT_SHIM, ['--repair'], schema, start, 'audit')
    # Flags only bin/console offers, and --as in any form but --as=NAME, are
    # refused before the kernel boots; the original ignored them and ran.
    for arguments in (['--repair', '--dry-run'], ['--report', '--json'], ['--repair', '--as'], ['--repair', '--as', 'admin']):
        flag = arguments[1]
        refused = run(harness, AUDIT_SHIM, arguments)
        check(refused['exit'] == 1 and refused['stdout'].startswith(f'ERROR: Invalid Parameter {flag}\n\nusage: audit_database.php')
              and schema(harness) == start,
              f'audit refuses {" ".join(arguments[1:])} through the shim before any statement')
    # The original printed its help for this; the shim checks the operator first.
    unauthorized = run(harness, AUDIT_SHIM, ['--as=nobody'])
    check(unauthorized['exit'] == 1 and unauthorized['stdout'] == REFUSED and schema(harness) == start,
          'audit refuses an unauthorized --as with no mode instead of printing the help')
    (without, before), (allowed, _), written = realm_fallback(harness, admin, lambda: (run(harness, AUDIT_SHIM, ['--create']), schema(harness)))
    check(without['exit'] == 1 and without['stdout'] == REFUSED and before == start,
          'audit fallback still needs a direct Settings/Utilities grant')
    check(allowed['exit'] == 0 and allowed['stdout'] == 'SUCCESS: Loaded the Audit Schema\n' and written == '0',
          'audit falls back to Settings/Utilities while nobody holds Installation/Upgrades')
    reset(harness, 'drifted', tables, version)
    planned = run(harness, 'bin/console', ['kadupul:database:audit', '--repair', '--dry-run', '--json'])
    report = json.loads(planned['stdout']) if planned['exit'] == 0 else {}
    alters = {alter['table']: alter for alter in report.get('alters', [])}
    check(report.get('dry_run') is True and report.get('baseline') == 'planned' and alters.get(DRIFTED, {}).get('result') == 'planned'
          and 'statement' in alters.get(DRIFTED, {}) and schema(harness) == start,
          'audit --dry-run through bin/console plans the repair and changes nothing, not even the audit tables')
    verify_remote_collector(harness, check, start)


def verify_remote_collector(harness, check, start):
    as_root(harness, f'cp -f {CONFIG} {CONFIG_ASIDE} && printf "\\n\\$poller_id = 2;\\n" >> {CONFIG}')
    try:
        repair = run(harness, AUDIT_SHIM, ['--repair'])
        helped = run(harness, AUDIT_SHIM, ['--help'])
    finally:
        as_root(harness, f'cp -f {CONFIG_ASIDE} {CONFIG} && rm -f {CONFIG_ASIDE}')
    fatal = 'FATAL: This utility is designed for the main Data Collector only\n'
    check(repair['exit'] == helped['exit'] == 1 and repair['stdout'] == helped['stdout'] == fatal and schema(harness) == start,
          'audit refuses a remote collector before any statement, --help included')


def verify_audit_parity(harness, check):
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which must hold
        # realm 26 for it to reach the schema at all.
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
        verify_audit(harness, check, admin)
    finally:
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
