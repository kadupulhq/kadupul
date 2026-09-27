# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for the cli/ tools that change the schema.

Each case runs the frozen original and the shim from the same starting schema
and compares stdout, stderr, the exit code and the schema each leaves behind.

The widen cases also compare cacti.log, whole lines with only the time of day
masked.
"""
import json
import re

from cli_parity_scenarios import clock_free, install_original, log_lines, normalise, run

CONVERT_ORIGINAL = 'tests/Fixtures/legacy-cli/convert_tables.php'
CONVERT_SHIM = 'cli/convert_tables.php'
CONVERT_UTILITY = 'Kadupul Database Conversion Utility'
MYISAM = 'kadupul_parity_myisam'
COMPACT = 'kadupul_parity_compact'
MISSING = 'kadupul_parity_missing'
SEEDS = {
    MYISAM: f"CREATE TABLE {MYISAM} (id int(10) unsigned NOT NULL PRIMARY KEY, name varchar(20) NOT NULL DEFAULT '') "
            'ENGINE=MyISAM DEFAULT CHARSET=latin1',
    COMPACT: f"CREATE TABLE {COMPACT} (id int(10) unsigned NOT NULL PRIMARY KEY, name varchar(20) NOT NULL DEFAULT '') "
             'ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=COMPACT',
}
# Only the original's own diagnostics for the row it failed to find.
UNDEFINED_KEY = re.compile(r'^PHP Warning:  Undefined array key "(TABLE_COLLATION|TABLE_ROWS|ENGINE|ROW_FORMAT)" in \S+ on line \d+\n', re.M)
REFUSED = 'ERROR: Unknown or unauthorized operator\n'
# The one allowed difference that leaves the two schemas unequal; the widen
# cases check that difference column by column instead.
SECOND_LOOP_ALLOWED = 'second loop'
NO_ONE = '999999'
FILE_PER_TABLE_OFF = 'convert innodb_file_per_table off'
FULL_RUN = 'convert full run with a skip table'
# cacti_log() and the shim read the date layout from settings; the harness has
# no rows, so one case sets them to make both honour stored values.
DOTTED = 'convert missing table with d.m.Y dates, original PHP warnings removed'
# A full run only walks the tables cacti.sql creates, so two empty base tables
# are made MyISAM for that case: one is skipped and one is converted.
BASE_SKIPPED = 'poller_command'
BASE_CONVERTED = 'poller_time'

# (label, arguments, allowed difference). Only argument sets the original
# accepts belong here; shim-only behaviour is checked in its own function. A
# label names the one difference its comparison removes.
CONVERT_CASES = [
    ('convert innodb', ['-i', f'--table={MYISAM}'], None),
    ('convert utf8', ['-u', f'-t={MYISAM}'], None),
    ('convert latin1', ['--latin1', f'--table={COMPACT}'], None),
    ('convert installer call', [f'--table={COMPACT}', '--utf8', '--innodb', '--dynamic'], None),
    ('convert too many rows', ['-i', '-s=2', f'--table={MYISAM}'], None),
    ('convert forced', ['-i', '-s=2', '-f', f'--table={MYISAM}'], None),
    ('convert rebuild', ['-i', '-r', f'--table={COMPACT}'], None),
    (FULL_RUN, ['-i', f'-n={BASE_SKIPPED} {MYISAM}'], None),
    ('convert missing table, original PHP warnings removed', ['-u', f'--table={MISSING}'], 'php warnings'),
    (DOTTED, ['-u', f'--table={MISSING}'], 'php warnings'),
    ('convert missing skip table', ['-i', f'--skip-innodb={MISSING}'], None),
    ('convert table and skip', ['-i', f'-t={MYISAM}', f'-n={COMPACT}'], None),
    ('convert no mode', [f'--table={MYISAM}'], None),
    (FILE_PER_TABLE_OFF, ['-i', f'--table={MYISAM}'], None),
    ('convert version', ['-V'], None),
    ('convert help', ['--help'], None),
    ('convert invalid flag, version line removed', ['--bogus'], 'version line before the error'),
]


def seed(harness, base_engine=None):
    for table, create in SEEDS.items():
        harness.sql(f"DROP TABLE IF EXISTS {table}; {create}; INSERT INTO {table} (id, name) VALUES (1, 'a'), (2, 'b');")
    if base_engine is not None:
        harness.sql(''.join(f'ALTER TABLE {table} ENGINE={base_engine};' for table in (BASE_SKIPPED, BASE_CONVERTED)))


def tables(harness):
    listing = harness.sql('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT FROM information_schema.TABLES '
                          'WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')
    return listing + ''.join(harness.sql(f'SHOW CREATE TABLE {table}') for table in SEEDS)


def status(harness, table):
    return harness.sql('SELECT ENGINE, TABLE_COLLATION, ROW_FORMAT FROM information_schema.TABLES '
                       f"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{table}'").strip()


def after_timestamp(lines, marker):
    # The installer check is not parity: it needs only the text after the date.
    return [line.split(' - ', 1)[-1] for line in lines if marker in line]


def without_version(text, utility):
    return '\n'.join(line for line in text.splitlines() if not line.startswith(utility))


def compare(harness, check, label, scripts, arguments, allowed, reset, snapshot, utility):
    """Run the original, then the shim, from equal starting schemas.

    reset() puts the schema back to its starting state and snapshot() reads
    what the comparison looks at. Returns the shim's result, both logs and
    both snapshots taken after the runs.
    """
    original_script, shim_script = scripts
    reset(harness)
    start = snapshot(harness)
    marks = len(log_lines(harness))
    original = run(harness, original_script, arguments)
    after_original = snapshot(harness)
    original_log = log_lines(harness)[marks:]
    reset(harness)
    check(snapshot(harness) == start, f'{label}: the shim starts from the same schema')
    marks = len(log_lines(harness))
    shim = run(harness, shim_script, arguments)
    after_shim = snapshot(harness)
    shim_log = log_lines(harness)[marks:]
    expected, actual, expected_err = normalise(original['stdout']), normalise(shim['stdout']), original['stderr']
    if allowed == 'version line before the error':
        # The original prints its version line inside the help that follows
        # the error; the shim reaches the error before it boots the kernel.
        expected, actual = without_version(expected, utility), '\n'.join(actual.splitlines())
    if allowed == 'php warnings':
        # The original indexed the empty information_schema row it got back.
        expected_err = UNDEFINED_KEY.sub('', expected_err)
    # The caller compares a schema it allows to differ.
    same_schema = allowed == SECOND_LOOP_ALLOWED or after_shim == after_original
    if actual != expected or shim['stderr'] != expected_err or not same_schema:
        print(f'{label}: original {original!r}\n{label}: shim {shim!r}', flush=True)
    check(shim['exit'] == original['exit'], f'{label}: shim exit code matches the original')
    check(actual == expected, f'{label}: shim stdout matches the original')
    check(shim['stderr'] == expected_err, f'{label}: shim stderr matches the original')
    if allowed != SECOND_LOOP_ALLOWED:
        check(after_shim == after_original, f'{label}: shim schema matches the original')
    return shim, original_log, shim_log, after_original, after_shim


def verify_convert(harness, check, admin):
    install_original(harness, CONVERT_ORIGINAL)
    try:
        verify_convert_cases(harness, check)
        verify_convert_shim_only(harness, check, admin)
        verify_installer_conversion(harness, check, admin)
    finally:
        harness.sql(''.join(f'DROP TABLE IF EXISTS {table};' for table in SEEDS))


def verify_convert_cases(harness, check):
    for label, arguments, allowed in CONVERT_CASES:
        if label == FILE_PER_TABLE_OFF:
            harness.sql('SET GLOBAL innodb_file_per_table = OFF')
        if label == DOTTED:
            dates = harness.sql("SELECT name, value FROM settings WHERE name IN ('default_date_format', 'default_datechar')")
            harness.sql("REPLACE INTO settings (name, value) VALUES ('default_date_format', '2'), ('default_datechar', '2')")
        try:
            base_engine = 'MyISAM' if label == FULL_RUN else None
            shim, original_log, shim_log, _, _ = compare(harness, check, label, (CONVERT_ORIGINAL, CONVERT_SHIM), arguments, allowed,
                                                         lambda h: seed(h, base_engine), tables, CONVERT_UTILITY)
        finally:
            if label == FILE_PER_TABLE_OFF:
                harness.sql('SET GLOBAL innodb_file_per_table = ON')
            if label == DOTTED:
                harness.sql("DELETE FROM settings WHERE name IN ('default_date_format', 'default_datechar');"
                            + ''.join(f"INSERT INTO settings (name, value) VALUES ('{name}', '{value}');"
                                      for name, value in (line.split('\t', 1) for line in dates.splitlines())))
            if label == FULL_RUN:
                engines = status(harness, BASE_SKIPPED), status(harness, BASE_CONVERTED)
                seed(harness, 'InnoDB')
        if label == FULL_RUN:
            check(f"Converting Table > '{BASE_SKIPPED}' Successful\n" in shim['stdout']
                  and f"Converting Table > '{BASE_CONVERTED}' Successful\n" in shim['stdout']
                  and engines[0].startswith('MyISAM\t') and engines[1].startswith('InnoDB\t')
                  and status(harness, BASE_SKIPPED) == status(harness, BASE_CONVERTED) == 'InnoDB\tutf8mb4_unicode_ci\tDynamic',
                  f'{label}: the skip list keeps a MyISAM base table off InnoDB')
        if label == 'convert innodb':
            # Matching output is only evidence if the shim reached the server.
            check(shim['stdout'].endswith(f"Converting Table > '{MYISAM}' Successful\n")
                  and "Converting Database Tables to InnoDB with less than '1000000' Records\n" in shim['stdout']
                  and status(harness, MYISAM).startswith('InnoDB\t'),
                  'convert innodb: shim converts the table through the kernel container')
        if label == FILE_PER_TABLE_OFF:
            check(shim['stdout'].endswith('\ninnodb_file_per_table not enabled') and status(harness, MYISAM).startswith('MyISAM\t'),
                  f'{label}: shim prints the refusal without a newline and changes nothing')
        if allowed == 'php warnings':
            case = label.split(',', 1)[0]
            fatal = clock_free(original_log, 'CONVERT FATAL')
            if clock_free(shim_log, 'CONVERT FATAL') != fatal:
                print(f'{case}: original log {fatal!r}\n{case}: shim log {clock_free(shim_log, "CONVERT FATAL")!r}', flush=True)
            check(len(fatal) == 1 and clock_free(shim_log, 'CONVERT FATAL') == fatal,
                  f'{case}: shim logs the same CONVERT FATAL line, date included')
            check(any('DBCALL' in line for line in original_log) and not any('DBCALL' in line for line in shim_log),
                  f'{case}: shim sends no statement for a table the server does not list')
        if label == DOTTED:
            check(re.match(r'\d{2}\.\d{2}\.\d{4} ', fatal[0]) is not None, f'{label}: the stored date layout reaches both logs')


def verify_convert_shim_only(harness, check, admin):
    seed(harness)
    start = tables(harness)
    denied = run(harness, CONVERT_SHIM, ['-i', f'--table={MYISAM}', '--as=nobody'])
    check(denied['exit'] == 1 and denied['stdout'] == REFUSED and tables(harness) == start,
          'convert tables refuses an unknown operator before any statement')
    empty = run(harness, CONVERT_SHIM, ['-i', f'--table={MYISAM}', '--as='])
    check(empty['exit'] == 1 and empty['stdout'].startswith('ERROR: Invalid Parameter --as=\n') and tables(harness) == start,
          'convert tables refuses an empty --as rather than falling back to admin_user')
    harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{NO_ONE}')")
    try:
        nobody = run(harness, CONVERT_SHIM, ['-i', f'--table={MYISAM}'])
    finally:
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
    check(nobody['exit'] == 1 and nobody['stdout'] == REFUSED and tables(harness) == start,
          'convert tables refuses a run with no operator')
    # A row for an account that does not exist still counts as a holder in
    # auth.php, so the realm 15 fallback stays closed here.
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 26; '
                f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (26, {NO_ONE})')
    try:
        refused = run(harness, CONVERT_SHIM, ['-i', f'--table={MYISAM}'])
    finally:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {NO_ONE} AND realm_id = 26; '
                    f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (26, {admin})')
    check(refused['exit'] == 1 and refused['stdout'] == REFUSED and tables(harness) == start,
          'convert tables refuses an operator without the Installation/Upgrades realm')
    verify_realm_fallback(harness, check, admin, start)
    quoted = run(harness, CONVERT_SHIM, ['-u', f'--table={MYISAM}`; DROP TABLE {COMPACT}'])
    check(quoted['exit'] == 0 and f"Converting Table > '{MYISAM}`; DROP TABLE {COMPACT}' Failed\n" in quoted['stdout']
          and tables(harness) == start, 'convert tables sends no DDL for a table name the server does not list')
    installer = run(harness, CONVERT_SHIM, ['-i', '--installer'])
    check(installer['exit'] == 1 and installer['stdout'].startswith('ERROR: Invalid Parameter --installer\n'),
          'convert tables rejects the broken --installer flag')
    size = run(harness, CONVERT_SHIM, ['-i', '--size=1e3'])
    check(size['exit'] == 1 and size['stdout'].startswith('ERROR: Invalid Parameter --size=1e3\n'),
          'convert tables rejects a size that is not a whole number')


def realm_fallback(harness, admin, attempt):
    """Run attempt() with nobody holding realm 26, first without, then with admin's direct realm 15.

    include/auth.php lets direct Settings/Utilities holders install while
    nobody holds Installation/Upgrades; the shims follow it for the run only.
    attempt() returns what the caller checks, schema included, since the
    second run changes it. Returns both, and the realm 26 rows left after.
    """
    users = harness.sql('SELECT user_id FROM user_auth_realm WHERE realm_id = 26').split()
    groups = harness.sql('SELECT group_id FROM user_auth_group_realm WHERE realm_id = 26').split()
    harness.sql('DELETE FROM user_auth_realm WHERE realm_id = 26; DELETE FROM user_auth_group_realm WHERE realm_id = 26')
    try:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 15')
        try:
            without = attempt()
        finally:
            harness.sql(f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (15, {admin})')
        allowed = attempt()
        written = harness.sql('SELECT COUNT(*) FROM user_auth_realm WHERE realm_id = 26').strip()
    finally:
        harness.sql(''.join(f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (26, {user});' for user in users)
                    + ''.join(f'INSERT INTO user_auth_group_realm (realm_id, group_id) VALUES (26, {group});' for group in groups))
    return without, allowed, written


def verify_realm_fallback(harness, check, admin, start):
    (without, before), (allowed, _), written = realm_fallback(
        harness, admin, lambda: (run(harness, CONVERT_SHIM, ['-i', f'--table={MYISAM}']), tables(harness)))
    check(without['exit'] == 1 and without['stdout'] == REFUSED and before == start,
          'convert tables fallback still needs a direct Settings/Utilities grant')
    check(allowed['exit'] == 0 and allowed['stdout'].endswith(f"Converting Table > '{MYISAM}' Successful\n")
          and status(harness, MYISAM).startswith('InnoDB\t') and written == '0',
          'convert tables falls back to Settings/Utilities while nobody holds Installation/Upgrades')
    seed(harness)


def verify_installer_conversion(harness, check, admin):
    # convertDatabase() is private and install() runs the whole template and
    # server install, so the harness calls the conversion step itself. What
    # it runs is the real in-process path, with no operator configured.
    step = ("include './include/cli_check.php'; include_once './lib/installer.php'; include_once './install/functions.php';"
            "$installer = (new ReflectionClass('Installer'))->newInstanceWithoutConstructor();"
            "(new ReflectionMethod('Installer', 'convertDatabase'))->invoke($installer);")
    seed(harness)
    names = set(harness.sql('SELECT name FROM settings').splitlines())
    queued = harness.sql("SELECT name, value FROM settings WHERE name LIKE 'install\\_table\\_%'")
    harness.sql("DELETE FROM settings WHERE name LIKE 'install\\_table\\_%'; "
                f"INSERT INTO settings (name, value) VALUES ('install_table_{MYISAM}', '{MYISAM}'); "
                f"REPLACE INTO settings (name,value) VALUES ('admin_user','{NO_ONE}')")
    marks = len(log_lines(harness))
    try:
        result = harness.php('-r', step)
        converted = status(harness, MYISAM)
        dequeued = harness.sql(f"SELECT value FROM settings WHERE name = 'install_table_{MYISAM}'").strip()
        stray = harness.sql("SELECT COUNT(*) FROM settings WHERE name = '0'").strip()
        written = after_timestamp(log_lines(harness)[marks:], 'INSTALL')
    finally:
        # Restore the queue exactly as it was before the scenario.
        added = set(harness.sql('SELECT name FROM settings').splitlines()) - names
        harness.sql("DELETE FROM settings WHERE name LIKE 'install\\_table\\_%';"
                    + ''.join(f"DELETE FROM settings WHERE name = CONVERT(UNHEX('{name.encode().hex()}') USING utf8mb4) COLLATE utf8mb4_unicode_ci;" for name in added))
        for line in queued.splitlines():
            name, value = line.split('\t', 1)
            harness.sql(f"INSERT INTO settings (name, value) VALUES ('{name}', CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
    if result['exit'] != 0 or not converted.startswith('InnoDB\tutf8mb4_unicode_ci\tDynamic'):
        print(f'installer conversion: {result!r} {converted!r} {written!r}', flush=True)
    check(result['exit'] == 0 and converted == 'InnoDB\tutf8mb4_unicode_ci\tDynamic',
          'installer converts a queued MyISAM table to InnoDB and utf8mb4 in-process')
    check('INSTALL: always: Found 1 tables to convert' in written
          and f"INSTALL: always: Converting Table #1 '{MYISAM}'" in written
          and not any('failed in-process' in line for line in written),
          'installer logs the queued conversion through log_install_always')
    check(dequeued == '' and stray == '0',
          'installer clears the converted table from its queue')


WIDEN_ORIGINAL = 'tests/Fixtures/legacy-cli/fix_mediumint.php'
WIDEN_SHIM = 'cli/fix_mediumint.php'
WIDEN_UTILITY = 'Kadupul Fix Database Range Issue'
IDS = 'kadupul_parity_ids'
# Created only by the shim-only hostile-name case; the backtick, semicolon and
# comment must stay inside one quoted identifier.
HOSTILE = f'{IDS}`; DROP TABLE {IDS}; --'
NARROW = [
    "ALTER TABLE poller_output MODIFY local_data_id mediumint(8) unsigned NOT NULL DEFAULT '0'",
    f"CREATE TABLE {IDS} (id int(10) unsigned NOT NULL PRIMARY KEY, graph_id mediumint(8) unsigned NOT NULL DEFAULT '0', "
    "data_id int(10) unsigned NOT NULL DEFAULT '0') ENGINE=InnoDB",
]
# The cacti.sql definitions, so every case starts from a fresh schema.
RESTORE = [
    "ALTER TABLE poller_output MODIFY local_data_id int(10) unsigned NOT NULL DEFAULT '0'",
    'ALTER TABLE rrdcheck MODIFY local_data_id mediumint(8) unsigned NOT NULL',
    "ALTER TABLE graph_tree_items MODIFY local_graph_id int(10) unsigned NOT NULL DEFAULT '0'",
    f'DROP TABLE IF EXISTS {IDS}',
    'DROP TABLE IF EXISTS `' + HOSTILE.replace('`', '``') + '`',
]
# Tables the cases change, whose SHOW CREATE TABLE joins the snapshot.
CHANGED = ['graph_tree_items', IDS, 'poller_output', 'rrdcheck']
# The original appended these columns to the last named table's statement,
# which failed; the shim gives each table its own statement, as
# install/upgrades/1_2_17.php does. Stdout is the same; only these differ.
SECOND_LOOP = {(IDS, 'graph_id'), ('rrdcheck', 'local_data_id')}
WIDEN_CASES = [
    ('widen fresh schema', [], None),
    ('widen fresh schema debug', ['--debug'], None),
    ('widen local on the primary', ['--local'], None),
    ('widen narrowed columns', [], SECOND_LOOP_ALLOWED),
    ('widen narrowed columns debug', ['-d'], SECOND_LOOP_ALLOWED),
    ('widen version', ['--version'], None),
    ('widen help', ['-h'], None),
    ('widen invalid flag', ['--bogus'], 'version line before the error'),
    # Neither script takes these; bin/console has its own --dry-run.
    ('widen dry-run flag', ['--dry-run'], 'version line before the error'),
    ('widen installer flag', ['--installer'], 'version line before the error'),
]


def columns(harness):
    rows = harness.sql("SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COALESCE(COLUMN_DEFAULT, 'NULL'), EXTRA "
                       'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')
    return [tuple(line.split('\t')) for line in rows.splitlines()]


def widen_schema(harness):
    """Every column, plus SHOW CREATE TABLE for each changed table that exists."""
    present = set(harness.sql('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()').splitlines())
    return columns(harness), {table: harness.sql(f'SHOW CREATE TABLE {table}') for table in CHANGED if table in present}


def restore(harness):
    harness.sql(';'.join(RESTORE) + ';')


def narrowed(harness):
    restore(harness)
    harness.sql(';'.join(NARROW) + ';')


def outside(snapshot):
    rows, created = snapshot
    missed = {table for table, _ in SECOND_LOOP}
    return [row for row in rows if (row[0], row[1]) not in SECOND_LOOP], {t: c for t, c in created.items() if t not in missed}


def dbcall(lines):
    return [line for line in lines if ' - DBCALL ' in line]


def failed_statements(lines):
    # db_execute() follows each DBCALL error with a CMDPHP backtrace line.
    return [line for line in lines if ' - DBCALL ' in line or ' - CMDPHP SQL Backtrace: ' in line]


def verify_widen(harness, check, admin):
    install_original(harness, WIDEN_ORIGINAL)
    # Taken before any RESTORE statement runs, so a RESTORE that does not match
    # the harness schema fails the final check instead of hiding.
    fresh = widen_schema(harness)
    try:
        for label, arguments, allowed in WIDEN_CASES:
            reset = narrowed if allowed == SECOND_LOOP_ALLOWED else restore
            _, original_log, shim_log, after_original, after_shim = compare(
                harness, check, label, (WIDEN_ORIGINAL, WIDEN_SHIM), arguments, allowed, reset, widen_schema, WIDEN_UTILITY)
            expected_log = clock_free(original_log, '')
            if allowed == SECOND_LOOP_ALLOWED:
                check(outside(after_shim) == outside(after_original),
                      f'{label}: shim schema matches the original outside the columns its statement missed')
                widened = [row for row in after_shim[0] if (row[0], row[1]) in SECOND_LOOP]
                check(len(widened) == 2 and all(row[2] == 'int(10) unsigned' for row in widened),
                      f'{label}: shim widens the columns the original statement missed')
                # The original's failed statements are the only lines it logs here.
                check(dbcall(original_log) != [] and failed_statements(original_log) == original_log,
                      f'{label}: only the original logs the statement that failed')
                expected_log = []
            if clock_free(shim_log, '') != expected_log:
                print(f'{label}: original log {original_log!r}\n{label}: shim log {shim_log!r}', flush=True)
            check(clock_free(shim_log, '') == expected_log, f'{label}: shim logs the same cacti.log lines, date included')
        verify_widen_shim_only(harness, check, admin)
    finally:
        restore(harness)
    check(widen_schema(harness) == fresh, 'widen scenarios leave the schema as they found it')


def column(harness, table, name):
    return next(row for row in columns(harness) if row[0] == table and row[1] == name)


def verify_widen_shim_only(harness, check, admin):
    restore(harness)
    harness.sql("ALTER TABLE graph_tree_items MODIFY local_graph_id bigint(20) unsigned NOT NULL DEFAULT '0';")
    run(harness, WIDEN_SHIM, [])
    check(column(harness, 'graph_tree_items', 'local_graph_id')[2] == 'bigint(20) unsigned', 'widen never narrows a bigint column')
    restore(harness)
    harness.sql(f"CREATE TABLE {IDS} (id int(10) unsigned NOT NULL PRIMARY KEY, graph_id mediumint(8) unsigned NOT NULL DEFAULT '0', "
                "data_id int(11) NULL DEFAULT '5') ENGINE=InnoDB;")
    result = run(harness, WIDEN_SHIM, [])
    data_id = column(harness, IDS, 'data_id')
    check(result['stdout'].endswith('NOTE: Column widths adjusted on 1 Tables!\n') and data_id[2] == 'int(10) unsigned'
          and data_id[3] == 'YES' and data_id[4] == '5', 'widen keeps a nullable column nullable')
    verify_widen_hostile_name(harness, check)
    narrowed(harness)
    start = widen_schema(harness)
    verify_widen_refusals(harness, check, admin, start)
    (without, before), (allowed, _), written = realm_fallback(harness, admin, lambda: (run(harness, WIDEN_SHIM, []), widen_schema(harness)))
    check(without['exit'] == 1 and without['stdout'] == REFUSED and before == start,
          'widen fallback still needs a direct Settings/Utilities grant')
    check(allowed['exit'] == 0 and allowed['stdout'].endswith('NOTE: Column widths adjusted on 3 Tables!\n')
          and column(harness, 'poller_output', 'local_data_id')[2] == 'int(10) unsigned' and written == '0',
          'widen falls back to Settings/Utilities while nobody holds Installation/Upgrades')
    narrowed(harness)
    planned = run(harness, 'bin/console', ['kadupul:database:widen-id-columns', '--dry-run', '--json'])
    report = json.loads(planned['stdout']) if planned['exit'] == 0 else {}
    check(report.get('dry_run') is True and [t['name'] for t in report.get('tables', [])] == ['poller_output', IDS, 'rrdcheck']
          and all(t['result'] == 'planned' for t in report['tables']) and widen_schema(harness) == start,
          'widen --dry-run through bin/console plans each table and changes nothing')


def verify_widen_hostile_name(harness, check):
    restore(harness)
    harness.sql(f"CREATE TABLE {IDS} (id int(10) unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB; "
                "CREATE TABLE `" + HOSTILE.replace('`', '``') + "` (graph_id mediumint(8) unsigned NOT NULL DEFAULT '0') ENGINE=InnoDB;")
    marks = len(log_lines(harness))
    result = run(harness, WIDEN_SHIM, ['--debug'])
    widened = column(harness, HOSTILE, 'graph_id')
    ids = [row for row in columns(harness) if row[0] == IDS]
    check(result['exit'] == 0 and f'DEBUG: Updating Table {HOSTILE}.\n' in result['stdout'] and widened[2] == 'int(10) unsigned'
          and len(ids) == 1 and dbcall(log_lines(harness)[marks:]) == [],
          'widen alters a hostile table name as one quoted identifier')


def verify_widen_refusals(harness, check, admin, start):
    denied = run(harness, WIDEN_SHIM, ['--as=nobody'])
    check(denied['exit'] == 1 and denied['stdout'] == REFUSED and widen_schema(harness) == start,
          'widen refuses an unknown operator before any statement')
    empty = run(harness, WIDEN_SHIM, ['--as='])
    check(empty['exit'] == 1 and empty['stdout'].startswith('ERROR: Invalid Parameter --as=\n') and widen_schema(harness) == start,
          'widen refuses an empty --as rather than falling back to admin_user')
    harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{NO_ONE}')")
    try:
        nobody = run(harness, WIDEN_SHIM, [])
    finally:
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
    check(nobody['exit'] == 1 and nobody['stdout'] == REFUSED and widen_schema(harness) == start,
          'widen refuses a run with no operator')
    # A holder that does not exist keeps the realm 15 fallback closed.
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 26; '
                f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (26, {NO_ONE})')
    try:
        refused = run(harness, WIDEN_SHIM, [])
    finally:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {NO_ONE} AND realm_id = 26; '
                    f'INSERT INTO user_auth_realm (realm_id, user_id) VALUES (26, {admin})')
    check(refused['exit'] == 1 and refused['stdout'] == REFUSED and widen_schema(harness) == start,
          'widen refuses an operator without the Installation/Upgrades realm')


def verify_schema_parity(harness, check):
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shims act as settings.admin_user, which must hold
        # realm 26 for them to reach the schema at all.
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}')")
        check(harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={admin} AND realm_id=26').strip() == '1',
              'convert: admin_user holds the Installation/Upgrades realm')
        verify_convert(harness, check, admin)
        verify_widen(harness, check, admin)
    finally:
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
