# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for the cli/ tools that manage graphs.

Each case runs the frozen original and the shim from the same starting rows and
compares stdout, stderr, the exit code and the rows each leaves behind. A case
that cannot match, because the original misbehaved, is checked on its own in
the differences.
"""
import re
import time

from cli_parity_scenarios import QUIET, ROOT, install_original, normalise, run

PREFIX = 'kadupul_parity_graph_'
TITLES_ORIGINAL = 'tests/Fixtures/legacy-cli/poller_graphs_reapply_names.php'
TITLES_SHIM = 'cli/poller_graphs_reapply_names.php'
TITLES_UTILITY = 'Kadupul Reapply graph Names Utility'
SOURCES_UTILITY = 'Kadupul Reapply Data Source Names Utility'
STALE = 'stale title'
SOURCES_ORIGINAL = 'tests/Fixtures/legacy-cli/poller_data_sources_reapply_names.php'
SOURCES_SHIM = 'cli/poller_data_sources_reapply_names.php'
SOURCES_PREFIX = 'kadupul_parity_source_'
REORDER_UTILITY = 'Kadupul Reorder Data Query Utility'
REORDER_ORIGINAL = 'tests/Fixtures/legacy-cli/reorder_data_query.php'
REORDER_SHIM = 'cli/reorder_data_query.php'
REORDER_PREFIX = 'kadupul_parity_reorder_'
REPAIR_ORIGINAL = 'tests/Fixtures/legacy-cli/repair_graphs.php'
REPAIR_SHIM = 'cli/repair_graphs.php'
BROKEN_ORIGINAL = 'tests/Fixtures/legacy-cli/remove_broken_graphs.php'
BROKEN_SHIM = 'cli/remove_broken_graphs.php'
BROKEN_FIRST_GRAPH = 4000000001
REMOVE_ORIGINAL = 'tests/Fixtures/legacy-cli/remove_graphs.php'
REMOVE_SHIM = 'cli/remove_graphs.php'
ADD_TEMPLATE_ORIGINAL = 'tests/Fixtures/legacy-cli/add_graph_template.php'
ADD_TEMPLATE_SHIM = 'cli/add_graph_template.php'
ADD_TEMPLATE_UTILITY = 'Kadupul Add Graph Template Utility'
ADD_QUERY_ORIGINAL = 'tests/Fixtures/legacy-cli/add_data_query.php'
ADD_QUERY_SHIM = 'cli/add_data_query.php'
ADD_QUERY_UTILITY = 'Kadupul Add Data Query Utility'
ADD_DS_ORIGINAL = 'tests/Fixtures/legacy-cli/add_datasource.php'
ADD_DS_SHIM = 'cli/add_datasource.php'
ADD_DS_UTILITY = 'Kadupul Add Data Source'
# Devices, data templates and the first new data source of the add_datasource.php cases.
ADD_DS_FIRST = 9100001
ADD_DS_NEXT = 9100010
REBUILD_ORIGINAL = 'tests/Fixtures/legacy-cli/rebuild_poller_cache.php'
REBUILD_SHIM = 'cli/rebuild_poller_cache.php'
REBUILD_UTILITY = 'Kadupul Rebuild poller cache Tool'
# Devices 1-4 (a polled one with a data source, a quiet one without, a disabled one, a second polled one), two device templates and a data template.
REBUILD_FIRST = 9200001
# The child's "Child Started" line begins with the time of day, which only the wall clock decides.
REBUILD_CLOCK = re.compile(r'^\S+ \d{2}:\d{2}:\d{2} - ', re.MULTILINE)
# In debug, the spawn line carries the elapsed time, and how often the master polls depends on how fast its children run.
REBUILD_TIMING = re.compile(r'Total\[[0-9.]+\] |^PUSHOUT: \d+ Processes Running, keeping for 2 seconds\.\n', re.MULTILINE)
# The worker runs under the installation's php.ini, not the harness's -d display_errors=0, so it shows the warnings the original hid.
REBUILD_DISPLAYED = re.compile(r'^\n?(?:PHP )?(?:Warning|Notice|Deprecated): .* on line \d+\n', re.MULTILINE)
TREE_ORIGINAL = 'tests/Fixtures/legacy-cli/add_tree.php'
TREE_SHIM = 'cli/add_tree.php'
TREE_UTILITY = 'Kadupul Add Tree Utility'
ADD_GRAPHS_ORIGINAL = 'tests/Fixtures/legacy-cli/add_graphs.php'
ADD_GRAPHS_SHIM = 'cli/add_graphs.php'
ADD_GRAPHS_UTILITY = 'Kadupul Add Graphs Utility'
# Devices, graphs and data sources the creation cases make; the next ids are pinned so that the original and the shim print the same ones.
ADD_GRAPHS_DEVICE = 9000101
ADD_GRAPHS_NEXT = 9500001
# graph_tree.id is a smallint, so the seeded tree sits near the top of its range; the nodes reuse the removal ids.
TREE_FIRST = 60001
# Every id the removal cases print is fixed (below 16777215, the largest device template id), because the original and the shim each start from a fresh seed.
REMOVE_FIRST = 9000001
# BSD stty says "stdin isn't a terminal", GNU stty "'standard input': Inappropriate ioctl for device".
STTY = re.compile(r'^stty: .*\n', re.MULTILINE)
# A CI php.ini logs the original's PHP warnings (an undefined sort method) to stderr even with display_errors off.
PHP_LOG = re.compile(r'^(?:PHP )?(?:Warning|Notice|Deprecated): .*\n', re.MULTILINE)
# The original also raised PHP deprecations on a terminal-less run; both sides run with display_errors off.
QUIET_PHP = '-d display_errors=0 '


def titles_seed(harness):
    """Two devices with two graphs each, a graph on no device, and templates to filter on. Every cached title is stale."""
    titles_reset(harness)
    harness.sql(
        f"INSERT INTO host (description, hostname) VALUES ('{PREFIX}core', '{PREFIX}core.example'), ('{PREFIX}edge', '{PREFIX}edge.example'); "
        f"INSERT INTO graph_templates (hash, name) VALUES ('{'0' * 31}1', '{PREFIX}Traffic'), ('{'0' * 31}2', '{PREFIX}Load');")
    ids = {
        'core': int(harness.sql(f"SELECT id FROM host WHERE description = '{PREFIX}core'").strip()),
        'edge': int(harness.sql(f"SELECT id FROM host WHERE description = '{PREFIX}edge'").strip()),
        'traffic': int(harness.sql(f"SELECT id FROM graph_templates WHERE name = '{PREFIX}Traffic'").strip()),
        'load': int(harness.sql(f"SELECT id FROM graph_templates WHERE name = '{PREFIX}Load'").strip()),
    }
    for host, template, label in ((ids['core'], ids['traffic'], 'core-traffic'), (ids['core'], ids['load'], 'core-load'),
                                  (ids['edge'], ids['traffic'], 'edge-traffic'), (ids['edge'], ids['load'], 'edge-load'),
                                  (0, ids['load'], 'floating')):
        harness.sql(f'INSERT INTO graph_local (graph_template_id, host_id) VALUES ({template}, {host})')
        local = int(harness.sql('SELECT MAX(id) FROM graph_local').strip())
        harness.sql('INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title, title_cache) '
                    f"VALUES ({local}, {template}, '|host_description| {PREFIX}{label}', '{STALE} {PREFIX}{label}')")
    return ids


def titles_reset(harness):
    harness.sql(
        f"DELETE FROM graph_templates_graph WHERE title LIKE '%{PREFIX}%'; "
        f"DELETE FROM graph_local WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name LIKE '{PREFIX}%'); "
        f"DELETE FROM host WHERE description LIKE '{PREFIX}%'; "
        f"DELETE FROM graph_templates WHERE name LIKE '{PREFIX}%'")


def titles_rows(harness):
    return harness.sql(f"SELECT title_cache FROM graph_templates_graph WHERE title LIKE '%{PREFIX}%' ORDER BY local_graph_id")


def without_version(text, utility=TITLES_UTILITY):
    """The original's help began with its version line even after an argument error; the shim reports the error before it can read one."""
    return ''.join(line for line in text.splitlines(keepends=True) if not line.startswith(utility))


def titles_cases(ids):
    core, edge = str(ids['core']), str(ids['edge'])
    # The prefix filter keeps `all` to the seeded graphs, so other steps' graphs are not renamed.
    only = f'--filter={PREFIX}'
    return [
        ('rename one device', [f'--host-id={core}', only]),
        ('rename with the short flags', [f'-id={core}', f'-s={PREFIX}']),
        ('rename a list of devices', [f'--host-id={core},{edge}', only]),
        ('rename every device', ['--host-id=all', only]),
        ('rename every device in capitals', ['--host-id=ALL', only]),
        ('rename graphs on no device', ['--host-id=0', only]),
        ('rename filtered by a title', [f'--host-id={core}', f'--filter={PREFIX}core-load']),
        ('rename filtered by a template name', ['--host-id=all', f'--filter={PREFIX}Traffic']),
        ('rename with a filter that matches nothing', ['--host-id=all', f'--filter={PREFIX}nothing']),
        ('rename a device with no graphs', ['--host-id=999999', only]),
        ('rename with a debug flag', [f'--host-id={core}', only, '--debug']),
        ('rename with the short debug flag', [f'--host-id={core}', only, '-d']),
        ('rename with debug and nothing selected', ['--host-id=999999', '--debug']),
        ('rename with an empty filter', [f'--host-id={core}', '--filter=']),
        ('rename with a filter containing an equals sign', [f'--host-id={core}', f'--filter={PREFIX}a=b']),
        ('rename a bare device flag', ['--host-id', only]),
        ('rename an empty device', ['--host-id=', only]),
        ('rename a device list with zero', ['--host-id=0,1', only]),
        ('rename a device list with a trailing comma', [f'--host-id={core},', only]),
        ('rename a negative device', ['--host-id=-1', only]),
        ('rename a device that is not a number', ['--host-id=abc', only]),
        ('rename with only a debug flag', ['--debug']),
        ('rename with only a filter', [only]),
        ('rename usage', []),
        ('rename help', ['-h']),
        ('rename long help', ['--help']),
        ('rename version', ['-V']),
        ('rename lower version', ['-v']),
        ('rename long version', ['--version']),
        ('rename unknown flag', ['--bogus']),
        ('rename unknown flag after a valid one', [f'--host-id={core}', '--bogus=1']),
        ('rename help stops before a later error', ['-h', '--bogus']),
    ]


def verify_titles_cases(harness, check):
    ids = titles_seed(harness)
    for label, arguments in titles_cases(ids):
        titles_seed_rows(harness, ids)
        original = run(harness, TITLES_ORIGINAL, arguments)
        original_rows = titles_rows(harness)
        titles_seed_rows(harness, ids)
        native = run(harness, TITLES_SHIM, arguments)
        native_rows = titles_rows(harness)
        # Only an argument error loses the version line; the rest must match byte for byte.
        expected = without_version(normalise(original['stdout'])) if label in ('rename unknown flag', 'rename unknown flag after a valid one') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same titles')
    titles_reset(harness)


def titles_seed_rows(harness, ids):
    """Every run starts from stale cached titles; the seeded graphs themselves stay."""
    harness.sql(f"UPDATE graph_templates_graph SET title_cache = CONCAT('{STALE} ', SUBSTRING_INDEX(title, ' ', -1)) WHERE title LIKE '%{PREFIX}%'")


def verify_titles_differences(harness, check):
    ids = titles_seed(harness)
    renamed = run(harness, TITLES_SHIM, [f"--host-id={ids['core']}", f'--filter={PREFIX}'])
    rows = titles_rows(harness).splitlines()
    check(renamed['exit'] == 0 and rows[0] == f'{PREFIX}core {PREFIX}core-traffic', 'a rename expands the device name into the cached title')
    check(rows[2].startswith(STALE), 'a rename leaves the other device untouched')

    # The original put the filter inside the SQL text, so a quote ended the string.
    titles_seed_rows(harness, ids)
    quoted = run(harness, TITLES_SHIM, ['--host-id=all', "--filter=x') OR 1=1 -- "])
    check(quoted['exit'] == 0 and quoted['stdout'].endswith('cause issues\n') and quoted['stdout'].count('\n') == 1 and all(row.startswith(STALE) for row in titles_rows(harness).splitlines()),
          'a quote in the filter is text and selects nothing')

    dry = run(harness, 'bin/console', ['kadupul:graph:reapply-titles', '--host-id=all', f'--filter={PREFIX}', '--dry-run', '--json'])
    check(dry['exit'] == 0 and '"dry_run":true' in dry['stdout'] and all(row.startswith(STALE) for row in titles_rows(harness).splitlines()),
          'a dry run lists the graphs and renames none')

    # The original needed no operator; the shim acts as admin_user and needs realm 5.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 5')
    try:
        denied = run(harness, TITLES_SHIM, ['--host-id=all', f'--filter={PREFIX}'])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a rename without graph management is refused')
        check(all(row.startswith(STALE) for row in titles_rows(harness).splitlines()), 'a refused rename changes no title')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 5)')
    titles_reset(harness)


def sources_seed(harness):
    """Two devices with two data sources each, one on no device, and templates to filter on. Every cached name is stale."""
    sources_reset(harness)
    harness.sql(
        f"INSERT INTO host (description, hostname) VALUES ('{SOURCES_PREFIX}core', '{SOURCES_PREFIX}core.example'), ('{SOURCES_PREFIX}edge', '{SOURCES_PREFIX}edge.example'); "
        f"INSERT INTO data_template (hash, name) VALUES ('{'0' * 31}5', '{SOURCES_PREFIX}Traffic'), ('{'0' * 31}6', '{SOURCES_PREFIX}Load');")
    ids = {
        'core': int(harness.sql(f"SELECT id FROM host WHERE description = '{SOURCES_PREFIX}core'").strip()),
        'edge': int(harness.sql(f"SELECT id FROM host WHERE description = '{SOURCES_PREFIX}edge'").strip()),
        'traffic': int(harness.sql(f"SELECT id FROM data_template WHERE name = '{SOURCES_PREFIX}Traffic'").strip()),
        'load': int(harness.sql(f"SELECT id FROM data_template WHERE name = '{SOURCES_PREFIX}Load'").strip()),
    }
    for host, template, label in ((ids['core'], ids['traffic'], 'core-traffic'), (ids['core'], ids['load'], 'core-load'),
                                  (ids['edge'], ids['traffic'], 'edge-traffic'), (ids['edge'], ids['load'], 'edge-load'),
                                  (0, ids['load'], 'floating')):
        harness.sql(f'INSERT INTO data_local (data_template_id, host_id) VALUES ({template}, {host})')
        local = int(harness.sql('SELECT MAX(id) FROM data_local').strip())
        harness.sql('INSERT INTO data_template_data (local_data_id, data_template_id, name, name_cache) '
                    f"VALUES ({local}, {template}, '|host_description| {SOURCES_PREFIX}{label}', '{STALE} {SOURCES_PREFIX}{label}')")
    return ids


def sources_reset(harness):
    harness.sql(
        f"DELETE FROM data_template_data WHERE name LIKE '%{SOURCES_PREFIX}%'; "
        f"DELETE FROM data_local WHERE data_template_id IN (SELECT id FROM data_template WHERE name LIKE '{SOURCES_PREFIX}%'); "
        f"DELETE FROM host WHERE description LIKE '{SOURCES_PREFIX}%'; "
        f"DELETE FROM data_template WHERE name LIKE '{SOURCES_PREFIX}%'")


def sources_rows(harness):
    return harness.sql(f"SELECT name_cache FROM data_template_data WHERE name LIKE '%{SOURCES_PREFIX}%' ORDER BY local_data_id")


def sources_stale(harness):
    """Every run starts from stale cached names; the seeded data sources themselves stay."""
    harness.sql(f"UPDATE data_template_data SET name_cache = CONCAT('{STALE} ', SUBSTRING_INDEX(name, ' ', -1)) WHERE name LIKE '%{SOURCES_PREFIX}%'")


def sources_cases(ids):
    core, edge = str(ids['core']), str(ids['edge'])
    # The prefix filter keeps `all` to the seeded data sources, so other steps' data sources are not renamed.
    only = f'--filter={SOURCES_PREFIX}'
    return [
        ('sources rename one device', [f'--host-id={core}', only]),
        ('sources rename with the short flags', [f'-id={core}', f'-s={SOURCES_PREFIX}']),
        ('sources rename with the id flag', [f'--id={core}', only]),
        ('sources rename a list of devices', [f'--host-id={core},{edge}', only]),
        ('sources rename every device', ['--host-id=all', only]),
        ('sources rename every device in capitals', ['--host-id=ALL', only]),
        ('sources rename on no device', ['--host-id=0', only]),
        ('sources rename filtered by a name', [f'--host-id={core}', f'--filter={SOURCES_PREFIX}core-load']),
        ('sources rename filtered by a data template', ['--host-id=all', f'--filter={SOURCES_PREFIX}Traffic']),
        ('sources rename with a filter that matches nothing', ['--host-id=all', f'--filter={SOURCES_PREFIX}nothing']),
        ('sources rename a device with none', ['--host-id=999999', only]),
        ('sources rename with a debug flag', [f'--host-id={core}', only, '--debug']),
        ('sources rename with the short debug flag', [f'--host-id={core}', only, '-d']),
        ('sources rename with an empty filter', [f'--host-id={core}', '--filter=']),
        ('sources rename with a filter containing an equals sign', [f'--host-id={core}', f'--filter={SOURCES_PREFIX}a=b']),
        ('sources rename a bare device flag', ['--host-id', only]),
        ('sources rename an empty device', ['--host-id=', only]),
        ('sources rename a device list with zero', ['--host-id=0,1', only]),
        ('sources rename a device list with a trailing comma', [f'--host-id={core},', only]),
        ('sources rename a negative device', ['--host-id=-1', only]),
        ('sources rename a device that is not a number', ['--host-id=abc', only]),
        ('sources rename with only a debug flag', ['--debug']),
        ('sources rename with only a filter', [only]),
        ('sources rename usage', []),
        ('sources rename help', ['-h']),
        ('sources rename long help', ['--help']),
        ('sources rename version', ['-V']),
        ('sources rename lower version', ['-v']),
        ('sources rename long version', ['--version']),
        ('sources rename unknown flag', ['--bogus']),
        ('sources rename unknown flag after a valid one', [f'--host-id={core}', '--bogus=1']),
        ('sources rename help stops before a later error', ['-h', '--bogus']),
    ]


def verify_sources_cases(harness, check):
    ids = sources_seed(harness)
    for label, arguments in sources_cases(ids):
        sources_stale(harness)
        original = run(harness, SOURCES_ORIGINAL, arguments)
        original_rows = sources_rows(harness)
        sources_stale(harness)
        native = run(harness, SOURCES_SHIM, arguments)
        native_rows = sources_rows(harness)
        # Only an argument error loses the version line; the rest must match byte for byte.
        expected = without_version(normalise(original['stdout']), SOURCES_UTILITY) if label in ('sources rename unknown flag', 'sources rename unknown flag after a valid one') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same names')
    sources_reset(harness)


def verify_sources_differences(harness, check):
    ids = sources_seed(harness)
    renamed = run(harness, SOURCES_SHIM, [f"--host-id={ids['core']}", f'--filter={SOURCES_PREFIX}'])
    rows = sources_rows(harness).splitlines()
    check(renamed['exit'] == 0 and rows[0] == f'{SOURCES_PREFIX}core {SOURCES_PREFIX}core-traffic', 'a data source rename expands the device name into the cached name')
    check(rows[2].startswith(STALE), 'a data source rename leaves the other device untouched')

    # The original put the filter inside the SQL text, so a quote ended the string.
    sources_stale(harness)
    quoted = run(harness, SOURCES_SHIM, ['--host-id=all', "--filter=x') OR 1=1 -- "])
    check(quoted['exit'] == 0 and quoted['stdout'].endswith('selected criteria.') and all(row.startswith(STALE) for row in sources_rows(harness).splitlines()),
          'a quote in the data source filter is text and selects nothing')

    # With --debug and nothing selected the original also printed its selection SQL.
    debug = run(harness, SOURCES_SHIM, ['--host-id=999999', '--debug'])
    check(debug['exit'] == 0 and debug['stdout'].endswith('selected criteria.') and 'SELECT' not in debug['stdout'], 'debug with nothing selected prints no SQL')

    dry = run(harness, 'bin/console', ['kadupul:data-source:reapply-titles', '--host-id=all', f'--filter={SOURCES_PREFIX}', '--dry-run', '--json'])
    check(dry['exit'] == 0 and '"dry_run":true' in dry['stdout'] and all(row.startswith(STALE) for row in sources_rows(harness).splitlines()),
          'a data source dry run lists the data sources and renames none')

    # The original needed no operator; the shim acts as admin_user and needs realm 3.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    try:
        denied = run(harness, SOURCES_SHIM, ['--host-id=all', f'--filter={SOURCES_PREFIX}'])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a data source rename without data source management is refused')
        check(all(row.startswith(STALE) for row in sources_rows(harness).splitlines()), 'a refused data source rename changes no name')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    sources_reset(harness)

def reorder_seed(harness):
    """Three devices and two data queries. core and edge have the first query with a stored sort field; core also has the second with none stored, whose data source stops a run; far has no data query. Every stored index is stale."""
    reorder_reset(harness)
    harness.sql(
        f"INSERT INTO host (description, hostname) VALUES ('{REORDER_PREFIX}core', '{REORDER_PREFIX}core.example'), ('{REORDER_PREFIX}edge', '{REORDER_PREFIX}edge.example'), ('{REORDER_PREFIX}far', '{REORDER_PREFIX}far.example'); "
        f"INSERT INTO data_input (hash, name) VALUES ('{'0' * 31}7', '{REORDER_PREFIX}input'); "
        f"INSERT INTO data_template (hash, name) VALUES ('{'0' * 31}8', '{REORDER_PREFIX}Traffic');")
    ids = {name: int(harness.sql(f"SELECT id FROM host WHERE description = '{REORDER_PREFIX}{name}'").strip()) for name in ('core', 'edge', 'far')}
    ids['input'] = int(harness.sql(f"SELECT id FROM data_input WHERE name = '{REORDER_PREFIX}input'").strip())
    ids['template'] = int(harness.sql(f"SELECT id FROM data_template WHERE name = '{REORDER_PREFIX}Traffic'").strip())
    for position, code in enumerate(('index_type', 'index_value', 'output_type'), start=1):
        harness.sql(f"INSERT INTO data_input_fields (hash, data_input_id, name, data_name, input_output, type_code) VALUES ('{'0' * 30}{position}9', {ids['input']}, '{REORDER_PREFIX}{code}', '{code}', 'in', '{code}')")
        ids[code] = int(harness.sql(f"SELECT id FROM data_input_fields WHERE data_input_id = {ids['input']} AND type_code = '{code}'").strip())
    for number in ('one', 'two'):
        harness.sql(f"INSERT INTO snmp_query (hash, xml_path, name, data_input_id) VALUES ('{'0' * 28}{number[:3]}0', '', '{REORDER_PREFIX}{number}', {ids['input']})")
        ids[number] = int(harness.sql(f"SELECT id FROM snmp_query WHERE name = '{REORDER_PREFIX}{number}'").strip())
    harness.sql(
        f"INSERT INTO host_snmp_query (host_id, snmp_query_id, sort_field, title_format) VALUES ({ids['core']}, {ids['one']}, 'ifName', ''), ({ids['edge']}, {ids['one']}, 'ifDescr', ''), ({ids['core']}, {ids['two']}, '', ''); "
        f"INSERT INTO host_snmp_cache (host_id, snmp_query_id, field_name, field_value, snmp_index, oid) VALUES "
        f"({ids['core']}, {ids['one']}, 'ifName', 'eth1', '1', ''), ({ids['core']}, {ids['one']}, 'ifName', 'eth2', '2', ''), ({ids['edge']}, {ids['one']}, 'ifDescr', 'port 1', '1', '')")
    # (host, query, snmp_index, output type): the second core row has no cached value, so its index is left alone; far has no data query row at all.
    for host, query, index, label, output in ((ids['core'], ids['one'], '1', 'core-1', '77'), (ids['core'], ids['one'], '2', 'core-2', '78'), (ids['core'], ids['one'], '9', 'core-9', '79'),
                                              (ids['core'], ids['two'], '1', 'core-q2', '80'), (ids['edge'], ids['one'], '1', 'edge-1', '81'), (ids['far'], ids['one'], '1', 'far-1', '82')):
        harness.sql(f'INSERT INTO data_local (data_template_id, host_id, snmp_query_id, snmp_index) VALUES ({ids["template"]}, {host}, {query}, \'{index}\')')
        local = int(harness.sql('SELECT MAX(id) FROM data_local').strip())
        harness.sql(f"INSERT INTO data_template_data (local_data_id, data_template_id, data_input_id, name, name_cache) VALUES ({local}, {ids['template']}, {ids['input']}, '{REORDER_PREFIX}{label}', '{REORDER_PREFIX}{label}')")
        template_data = int(harness.sql('SELECT MAX(id) FROM data_template_data').strip())
        harness.sql(f"INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) VALUES ({ids['output_type']}, {template_data}, '', '{output}')")
    return ids


def reorder_reset(harness):
    harness.sql(
        f"DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE name LIKE '{REORDER_PREFIX}%'); "
        f"DELETE FROM data_template_data WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"DELETE FROM data_local WHERE data_template_id IN (SELECT id FROM data_template WHERE name LIKE '{REORDER_PREFIX}%'); "
        f"DELETE FROM host_snmp_cache WHERE host_id IN (SELECT id FROM host WHERE description LIKE '{REORDER_PREFIX}%'); "
        f"DELETE FROM host_snmp_query WHERE host_id IN (SELECT id FROM host WHERE description LIKE '{REORDER_PREFIX}%'); "
        f"DELETE FROM snmp_query WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"DELETE FROM data_input_fields WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"DELETE FROM data_input WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"DELETE FROM host WHERE description LIKE '{REORDER_PREFIX}%'; "
        f"DELETE FROM data_template WHERE name LIKE '{REORDER_PREFIX}%'")


def reorder_rows(harness):
    """What a run can change: the stored index of each data source and the sort field of each device's data query."""
    return (harness.sql(f"SELECT dtd.name, did.data_input_field_id, did.value FROM data_input_data did JOIN data_template_data dtd ON dtd.id = did.data_template_data_id WHERE dtd.name LIKE '{REORDER_PREFIX}%' ORDER BY dtd.name, did.data_input_field_id")
            + harness.sql(f"SELECT h.description, hsq.snmp_query_id, hsq.sort_field, hsq.title_format FROM host_snmp_query hsq JOIN host h ON h.id = hsq.host_id WHERE h.description LIKE '{REORDER_PREFIX}%' ORDER BY h.description, hsq.snmp_query_id"))


def reorder_stale(harness, ids):
    """Every run starts from stale stored indexes and the seeded sort fields."""
    harness.sql(
        f"DELETE FROM data_input_data WHERE data_input_field_id IN ({ids['index_type']}, {ids['index_value']}) AND data_template_data_id IN (SELECT id FROM data_template_data WHERE name LIKE '{REORDER_PREFIX}%'); "
        f"INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) SELECT {ids['index_type']}, id, '', 'stale' FROM data_template_data WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) SELECT {ids['index_value']}, id, '', 'stale' FROM data_template_data WHERE name LIKE '{REORDER_PREFIX}%'; "
        f"UPDATE host_snmp_query SET sort_field = CASE WHEN snmp_query_id = {ids['two']} THEN '' WHEN host_id = {ids['edge']} THEN 'ifDescr' ELSE 'ifName' END, title_format = '' WHERE host_id IN ({ids['core']}, {ids['edge']})")


def reorder_cases(ids):
    core, edge, far, one, two = (str(ids[name]) for name in ('core', 'edge', 'far', 'one', 'two'))
    return [
        ('reorder one device and query', [f'--host-id={core}', f'--qid={one}']),
        ('reorder with the short flags', [f'-id={edge}', f'-qid={one}']),
        ('reorder with the id flag', [f'--id={edge}', f'--qid={one}']),
        ('reorder every device of a query', ['--host-id=all', f'--qid={one}']),
        ('reorder every device in capitals', ['--host-id=ALL', f'--qid={one}']),
        ('reorder a query only', [f'--qid={one}']),
        ('reorder with leading zeros', [f'--host-id=00{edge}', f'--qid=0{one}']),
        ('reorder a device with no data query row', [f'--host-id={far}', f'--qid={one}']),
        ('reorder a device that has no data sources', ['--host-id=999999', f'--qid={one}']),
        ('reorder a query that has no data sources', [f'--host-id={core}', '--qid=999999']),
        ('reorder a query whose sort field is not stored', [f'--host-id={core}', f'--qid={two}']),
        ('reorder with a debug flag', [f'--host-id={core}', f'--qid={one}', '--debug']),
        ('reorder with the short debug flag', [f'--host-id={edge}', f'--qid={one}', '-d']),
        ('reorder debug with nothing selected', ['--host-id=999999', f'--qid={one}', '-d']),
        ('reorder debug stopping at a data query', [f'--host-id={core}', f'--qid={two}', '--debug']),
        ('reorder a bare device flag', ['--host-id', f'--qid={one}']),
        ('reorder an empty device', ['--host-id=', f'--qid={one}']),
        ('reorder device zero', ['--host-id=0', f'--qid={one}']),
        ('reorder a negative device', ['--host-id=-1', f'--qid={one}']),
        ('reorder a device list', [f'--host-id={core},{edge}', f'--qid={one}']),
        ('reorder a device that is not a number', ['--host-id=abc', f'--qid={one}']),
        ('reorder a missing query', [f'--host-id={core}']),
        ('reorder a bare query flag', [f'--host-id={core}', '--qid']),
        ('reorder an empty query', [f'--host-id={core}', '--qid=']),
        ('reorder query zero', [f'--host-id={core}', '--qid=0']),
        ('reorder a query that is not a number', [f'--host-id={core}', '--qid=invalid']),
        ('reorder the host is judged before the query', ['--host-id=0']),
        ('reorder with only a debug flag', ['--debug']),
        ('reorder usage', []),
        ('reorder help', ['-h']),
        ('reorder long help', ['--help']),
        ('reorder short help', ['-H']),
        ('reorder version', ['-V']),
        ('reorder lower version', ['-v']),
        ('reorder long version', ['--version']),
        ('reorder unknown flag', ['--bogus']),
        ('reorder unknown flag after a valid one', [f'--host-id={core}', '--bogus=1']),
        ('reorder help stops before a later error', ['-h', '--bogus']),
    ]


def verify_reorder_cases(harness, check):
    ids = reorder_seed(harness)
    for label, arguments in reorder_cases(ids):
        reorder_stale(harness, ids)
        original = run(harness, REORDER_ORIGINAL, arguments)
        original_rows = reorder_rows(harness)
        reorder_stale(harness, ids)
        native = run(harness, REORDER_SHIM, arguments)
        native_rows = reorder_rows(harness)
        # Only an argument error loses the version line; the rest must match byte for byte.
        expected = without_version(normalise(original['stdout']), REORDER_UTILITY) if label in ('reorder unknown flag', 'reorder unknown flag after a valid one') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same indexes')
    reorder_reset(harness)


def verify_reorder_differences(harness, check):
    ids = reorder_seed(harness)
    reorder_stale(harness, ids)
    stale = reorder_rows(harness)
    reordered = run(harness, REORDER_SHIM, [f"--host-id={ids['core']}", f"--qid={ids['one']}"])
    rows = reorder_rows(harness)
    check(reordered['exit'] == 0 and rows != stale and 'ifName' in rows, 'a reorder stores the index field and value for a cached index')

    dry = run(harness, 'bin/console', ['kadupul:data-query:reorder', '--host-id=all', f"--qid={ids['one']}", '--dry-run', '--json'])
    reorder_stale(harness, ids)
    check(dry['exit'] == 0 and '"dry_run":true' in dry['stdout'] and reorder_rows(harness) == stale, 'a reorder dry run lists the items and reorders none')

    stopped = run(harness, 'bin/console', ['kadupul:data-query:reorder', f"--host-id={ids['core']}", f"--qid={ids['two']}", '--json'])
    check(stopped['exit'] == 1 and f'"host_id":{ids["core"]}' in stopped['stdout'] and '"status":"partial"' in stopped['stdout'], 'a reorder that cannot find a sort field names the device and query it stopped at')

    # The original needed no operator; the shim acts as admin_user and needs realm 3.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    try:
        denied = run(harness, REORDER_SHIM, ['--host-id=all', f"--qid={ids['one']}"])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a reorder without device management is refused')
        check(reorder_rows(harness) == stale, 'a refused reorder changes no index')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    reorder_reset(harness)



def repair_seed(harness):
    """Three devices. core has a data source with an RRD item and two graphs, one with a wrong item; edge has a data source with no RRD item; far has none."""
    repair_reset(harness)
    harness.sql(
        f"INSERT INTO host (description, hostname) VALUES ('{PREFIX}core', '{PREFIX}core.example'), ('{PREFIX}edge', '{PREFIX}edge.example'), ('{PREFIX}far', '{PREFIX}far.example'); "
        f"INSERT INTO graph_templates (hash, name) VALUES ('{'0' * 31}3', '{PREFIX}Repair'); "
        f"INSERT INTO data_template (hash, name) VALUES ('{'0' * 31}4', '{PREFIX}Repair');")

    def one(query):
        return int(harness.sql(query).strip())

    ids = {
        'core': one(f"SELECT id FROM host WHERE description = '{PREFIX}core'"),
        'edge': one(f"SELECT id FROM host WHERE description = '{PREFIX}edge'"),
        'far': one(f"SELECT id FROM host WHERE description = '{PREFIX}far'"),
        'gt': one(f"SELECT id FROM graph_templates WHERE name = '{PREFIX}Repair'"),
        'dt': one(f"SELECT id FROM data_template WHERE name = '{PREFIX}Repair'"),
    }
    harness.sql(f"INSERT INTO data_template_rrd (local_data_template_rrd_id, local_data_id, data_template_id, data_source_name) VALUES (0, 0, {ids['dt']}, 'kpg_ds')")
    ids['template_rrd'] = one(f"SELECT MAX(id) FROM data_template_rrd WHERE data_template_id = {ids['dt']}")
    for host in ('core', 'edge'):
        harness.sql(f"INSERT INTO data_local (data_template_id, host_id) VALUES ({ids['dt']}, {ids[host]})")
        ids['ds_' + host] = one(f"SELECT MAX(id) FROM data_local WHERE host_id = {ids[host]}")
    harness.sql(f"INSERT INTO data_template_rrd (local_data_template_rrd_id, local_data_id, data_template_id, data_source_name) VALUES ({ids['template_rrd']}, {ids['ds_core']}, {ids['dt']}, 'kpg_ds')")
    ids['rrd'] = one(f"SELECT MAX(id) FROM data_template_rrd WHERE local_data_id = {ids['ds_core']}")
    # A real RRD item of another data source: a valid reference that is the wrong one for the core graph.
    harness.sql(f"INSERT INTO data_template_rrd (local_data_template_rrd_id, local_data_id, data_template_id, data_source_name) VALUES (0, {ids['ds_edge']}, 0, 'kpg_other')")
    ids['other'] = one(f"SELECT MAX(id) FROM data_template_rrd WHERE local_data_id = {ids['ds_edge']}")
    harness.sql(f"INSERT INTO graph_templates_item (graph_template_id, local_graph_id, local_graph_template_item_id, task_item_id) VALUES ({ids['gt']}, 0, 0, {ids['template_rrd']})")
    ids['template_item'] = one(f"SELECT MAX(id) FROM graph_templates_item WHERE graph_template_id = {ids['gt']}")
    for name, host in (('g1', 'core'), ('g2', 'core'), ('g3', 'edge'), ('g4', 'far')):
        harness.sql(f"INSERT INTO graph_local (graph_template_id, host_id) VALUES ({ids['gt']}, {ids[host]})")
        ids[name] = one(f"SELECT MAX(id) FROM graph_local WHERE host_id = {ids[host]}")
    for graph, rrd in (('g1', 'other'), ('g1', 'rrd'), ('g2', 'rrd'), ('g3', 'other'), ('g4', 'other')):
        harness.sql(f"INSERT INTO graph_templates_item (graph_template_id, local_graph_id, local_graph_template_item_id, task_item_id) VALUES ({ids['gt']}, {ids[graph]}, {ids['template_item']}, {ids[rrd]})")
    ids['wrong_item'] = one(f"SELECT MIN(id) FROM graph_templates_item WHERE local_graph_id = {ids['g1']}")
    return ids


def repair_reset(harness):
    harness.sql(
        f"DELETE FROM graph_templates_item WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name = '{PREFIX}Repair'); "
        f"DELETE FROM graph_local WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name = '{PREFIX}Repair'); "
        f"DELETE FROM data_template_rrd WHERE data_template_id IN (SELECT id FROM data_template WHERE name = '{PREFIX}Repair') OR data_source_name = 'kpg_other'; "
        f"DELETE FROM data_local WHERE host_id IN (SELECT id FROM host WHERE description LIKE '{PREFIX}%'); "
        f"DELETE FROM host WHERE description LIKE '{PREFIX}%'; "
        f"DELETE FROM graph_templates WHERE name = '{PREFIX}Repair'; "
        f"DELETE FROM data_template WHERE name = '{PREFIX}Repair'")


def repair_rows(harness, ids):
    return harness.sql(f"SELECT local_graph_id, local_graph_template_item_id, task_item_id FROM graph_templates_item WHERE graph_template_id = {ids['gt']} ORDER BY id")


def wrong_item(harness, ids):
    return harness.sql(f"SELECT task_item_id FROM graph_templates_item WHERE id = {ids['wrong_item']}").strip()


def repair_cases(ids):
    gt, dt, core, edge, far = (str(ids[key]) for key in ('gt', 'dt', 'core', 'edge', 'far'))
    both = [f'--graph-template-id={gt}', f'--data-template-id={dt}']
    return [
        ('repair executes', [*both, '--execute']),
        ('repair shows the statement only', [*both, '--show-sql']),
        ('repair shows the statement and executes', [*both, '--show-sql', '--execute']),
        ('repair one device', [*both, f'--host-id={core}', '--execute']),
        ('repair a device whose data source has no RRD item', [*both, f'--host-id={edge}', '--execute']),
        ('repair a device with no data source', [*both, f'--host-id={far}', '--execute']),
        ('repair a device that does not exist', [*both, '--host-id=999999', '--execute']),
        ('repair a graph template with no graphs', [f'--graph-template-id=999999', f'--data-template-id={dt}', '--execute']),
        ('repair a data template with no data sources', [f'--graph-template-id={gt}', '--data-template-id=999999', '--execute']),
        ('repair with padded ids', [f'--graph-template-id={gt}', f'--data-template-id={dt}', f'--host-id= {core} ', '--execute']),
        ('repair with flags in another order', ['--execute', f'--data-template-id={dt}', f'--graph-template-id={gt}']),
        ('repair twice the same flag takes the last', [f'--graph-template-id=999999', *both, '--execute']),
        ('repair with neither mode', both),
        ('repair without a data template', [f'--graph-template-id={gt}', '--execute']),
        ('repair without a graph template', [f'--data-template-id={dt}', '--execute']),
        ('repair a bare host flag', [*both, '--host-id', '--execute']),
        ('repair an empty graph template', ['--graph-template-id=', f'--data-template-id={dt}', '--execute']),
        ('repair a graph template that is not a number', ['--graph-template-id=abc', f'--data-template-id={dt}', '--execute']),
        ('repair a data template that is not a number', [f'--graph-template-id={gt}', '--data-template-id=abc', '--execute']),
        ('repair a host that is not a number', [*both, '--host-id=abc', '--execute']),
        ('repair an error stops before a later help', ['--host-id=abc', '--help']),
        ('repair help stops before a later error', ['--help', '--host-id=abc']),
        ('repair usage', []),
        ('repair help', ['-h']),
        ('repair upper help', ['-H']),
        ('repair long help', ['--help']),
        ('repair version', ['-v']),
        ('repair upper version', ['-V']),
        ('repair long version', ['--version']),
        ('repair unknown flag', ['--bogus']),
        ('repair unknown flag with a value', [*both, '--bogus=1']),
    ]


def verify_repair_cases(harness, check):
    ids = repair_seed(harness)
    for label, arguments in repair_cases(ids):
        harness.sql(f"UPDATE graph_templates_item SET task_item_id = {ids['other']} WHERE id = {ids['wrong_item']}")
        original = run(harness, REPAIR_ORIGINAL, arguments)
        original_rows = repair_rows(harness, ids)
        harness.sql(f"UPDATE graph_templates_item SET task_item_id = {ids['other']} WHERE id = {ids['wrong_item']}")
        native = run(harness, REPAIR_SHIM, arguments)
        native_rows = repair_rows(harness, ids)
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(normalise(original['stdout']) == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same graph items')
    repair_reset(harness)


def verify_repair_differences(harness, check):
    ids = repair_seed(harness)
    both = [f"--graph-template-id={ids['gt']}", f"--data-template-id={ids['dt']}"]
    # The original read is_numeric(): a decimal passed and matched no device. The shim wants a whole number.
    decimal = run(harness, REPAIR_SHIM, [*both, '--host-id=1.5', '--execute'])
    check(decimal['exit'] == 1 and decimal['stdout'] == 'ERROR: You must supply a valid host-id to run this script!\n', 'a decimal device id is refused')
    # The original printed help with the version line first after an unknown flag.
    bogus = run(harness, REPAIR_SHIM, ['--bogus'])
    check(bogus['exit'] == 1 and bogus['stdout'].startswith('ERROR: Invalid Parameter --bogus\n\nusage: repair_graphs.php'), 'an unknown flag prints the error and the help')

    dry = run(harness, 'bin/console', ['kadupul:graph:repair-items', *both, '--json'])
    check(dry['exit'] == 0 and '"executed":false' in dry['stdout'] and '"repointed":1' in dry['stdout'], 'bin/console reports without --execute')
    check(wrong_item(harness, ids) == str(ids['other']), 'a report changes no graph item')

    # The original needed no operator; the shim acts as admin_user and needs realm 5.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 5')
    try:
        denied = run(harness, REPAIR_SHIM, [*both, '--execute'])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a repair without graph management is refused')
        check(wrong_item(harness, ids) == str(ids['other']), 'a refused repair changes no graph item')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 5)')
    repair_reset(harness)


def broken_seed(harness):
    """Two broken graphs on one template, a healthy graph, and a second template with one broken graph."""
    broken_reset(harness)
    harness.sql(
        f"INSERT INTO graph_templates (hash, name) VALUES ('{'0' * 31}5', '{PREFIX}BrokenA'), ('{'0' * 31}6', '{PREFIX}BrokenB');")
    templates = [int(harness.sql(f"SELECT id FROM graph_templates WHERE name = '{PREFIX}Broken{letter}'").strip()) for letter in 'AB']
    graphs = []
    # Fixed ids: the removal cases print them, and the original and the shim each start from a fresh seed.
    next_id = BROKEN_FIRST_GRAPH
    for template, count in ((templates[0], 2), (templates[1], 1)):
        for _ in range(count):
            graph = next_id
            next_id += 1
            graphs.append(graph)
            harness.sql(f'INSERT INTO graph_local (id, graph_template_id, host_id) VALUES ({graph}, {template}, 0); '
                        f"INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title_cache) VALUES ({graph}, {template}, '{PREFIX}broken-{graph}'); "
                        f'INSERT INTO graph_templates_item (graph_template_id, local_graph_id, local_graph_template_item_id, task_item_id) VALUES ({template}, {graph}, 0, 987654321)')
    return templates, graphs


def broken_reset(harness):
    harness.sql(
        f"DELETE FROM graph_templates_item WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name LIKE '{PREFIX}Broken%'); "
        f"DELETE FROM graph_templates_graph WHERE title_cache LIKE '{PREFIX}broken-%'; "
        f"DELETE FROM graph_local WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name LIKE '{PREFIX}Broken%'); "
        f"DELETE FROM graph_templates WHERE name LIKE '{PREFIX}Broken%'")


def broken_rows(harness):
    return harness.sql(f"SELECT COUNT(*) FROM graph_local WHERE graph_template_id IN (SELECT id FROM graph_templates WHERE name LIKE '{PREFIX}Broken%')")


BROKEN_CASES = [
    ('broken report by default', []),
    ('broken report', ['--report']),
    ('broken remove', ['--remove']),
    ('broken debug is accepted', ['--debug']),
    ('broken remove with debug', ['--remove', '--debug']),
    ('broken report and remove are exclusive', ['--report', '--remove']),
    ('broken remove and report are exclusive', ['--remove', '--report']),
    ('broken unknown flag', ['--bogus']),
    ('broken help', ['-h']),
    ('broken upper help', ['-H']),
    ('broken long help', ['--help']),
    ('broken version', ['-v']),
    ('broken upper version', ['-V']),
    ('broken long version', ['--version']),
    ('broken help stops before a later error', ['--help', '--bogus']),
    ('broken flags with values are ignored', ['--report=yes']),
]


def verify_broken_cases(harness, check):
    for label, arguments in BROKEN_CASES:
        broken_seed(harness)
        original = run(harness, QUIET_PHP + BROKEN_ORIGINAL, arguments)
        original_rows = broken_rows(harness)
        broken_seed(harness)
        native = run(harness, QUIET_PHP + BROKEN_SHIM, arguments)
        native_rows = broken_rows(harness)
        # Only an argument error loses the version line; the rest must match byte for byte.
        expected = without_utility(normalise(original['stdout']), 'Kadupul Remove Broken Graphs Utility') if label == 'broken unknown flag' else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        # Run without a terminal, the original's `stty size` complained on stderr.
        check(STTY.sub('', normalise(original['stderr'])) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same graphs')
    # With nothing broken from the seed, both say so.
    broken_reset(harness)
    original = run(harness, QUIET_PHP + BROKEN_ORIGINAL, [])
    native = run(harness, QUIET_PHP + BROKEN_SHIM, [])
    check(original['exit'] == native['exit'] and normalise(original['stdout']) == normalise(native['stdout']), 'broken with nothing broken prints the same lines')


def without_utility(text, utility):
    return ''.join(line for line in text.splitlines(keepends=True) if not line.startswith(utility))


def verify_broken_differences(harness, check):
    broken_seed(harness)
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 5')
    try:
        denied = run(harness, BROKEN_SHIM, ['--remove'])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a removal without graph management is refused')
        check(broken_rows(harness).strip() == '3', 'a refused removal removes no graph')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 5)')
    report = run(harness, 'bin/console', ['kadupul:graph:remove-broken', '--json'])
    check(report['exit'] == 0 and '"broken":3' in report['stdout'] and broken_rows(harness).strip() == '3', 'bin/console reports without removing')
    both = run(harness, 'bin/console', ['kadupul:graph:remove-broken', '--report', '--remove'])
    check(both['exit'] == 2, 'bin/console treats both modes as invalid input')
    broken_reset(harness)


def remove_seed(harness):
    """Three devices on two device templates, five graphs (one on no device), and a data source behind the first graph."""
    remove_reset(harness)
    a, b, c = REMOVE_FIRST, REMOVE_FIRST + 1, REMOVE_FIRST + 2
    harness.sql(
        f"INSERT INTO host_template (id, hash, name) VALUES ({a}, '{'0' * 31}7', '{PREFIX}RmRouter'), ({b}, '{'0' * 31}8', '{PREFIX}RmSwitch'); "
        f"INSERT INTO graph_templates (id, hash, name) VALUES ({a}, '{'0' * 31}9', '{PREFIX}RmTraffic'), ({b}, '{'0' * 30}10', '{PREFIX}RmLoad'); "
        f'INSERT INTO host_template_graph (host_template_id, graph_template_id) VALUES ({a}, {a}), ({a}, {b}), ({b}, {a}); '
        f"INSERT INTO host (id, description, hostname, host_template_id) VALUES ({a}, '{PREFIX}rmcore', '{PREFIX}rmcore.example', {a}), "
        f"({b}, '{PREFIX}rmedge', '{PREFIX}rmedge.example', {a}), ({c}, '{PREFIX}rmfar', '{PREFIX}rmfar.example', {b}); "
        f'INSERT INTO data_local (id, data_template_id, host_id) VALUES ({a}, 0, {a}); '
        f"INSERT INTO data_template_data (local_data_id, name_cache) VALUES ({a}, '{PREFIX}rm-data'); "
        f"INSERT INTO data_template_rrd (id, local_data_id, data_source_name) VALUES ({a}, {a}, 'traffic_in')")
    graphs = ((a, a, a, 'core-traffic'), (a + 1, b, a, 'core-load'), (a + 2, a, b, 'edge-traffic'), (a + 3, a, c, 'far-traffic'), (a + 4, b, 0, 'floating'))
    for graph, template, host, label in graphs:
        harness.sql(f'INSERT INTO graph_local (id, graph_template_id, host_id) VALUES ({graph}, {template}, {host}); '
                    f"INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title_cache) VALUES ({graph}, {template}, '{PREFIX}rm-{label}')")
    harness.sql(f'INSERT INTO graph_templates_item (graph_template_id, local_graph_id, local_graph_template_item_id, task_item_id) VALUES ({a}, {a}, 0, {a})')


def remove_reset(harness):
    harness.sql(
        f"DELETE FROM graph_templates_item WHERE local_graph_id >= {REMOVE_FIRST}; "
        f"DELETE FROM graph_templates_graph WHERE title_cache LIKE '{PREFIX}rm-%'; "
        f'DELETE FROM graph_local WHERE id >= {REMOVE_FIRST}; '
        f'DELETE FROM data_template_rrd WHERE local_data_id >= {REMOVE_FIRST}; '
        f'DELETE FROM data_template_data WHERE local_data_id >= {REMOVE_FIRST}; '
        f'DELETE FROM data_local WHERE id >= {REMOVE_FIRST}; '
        f'DELETE FROM host_graph WHERE host_id >= {REMOVE_FIRST}; '
        f'DELETE FROM host_template_graph WHERE host_template_id >= {REMOVE_FIRST}; '
        f'DELETE FROM host WHERE id >= {REMOVE_FIRST}; '
        f'DELETE FROM host_template WHERE id >= {REMOVE_FIRST}; '
        f'DELETE FROM graph_templates WHERE id >= {REMOVE_FIRST}')


def remove_rows(harness):
    """The graphs and data sources the removal cases may leave behind."""
    return harness.sql(f'SELECT (SELECT GROUP_CONCAT(id ORDER BY id) FROM graph_local WHERE id >= {REMOVE_FIRST}), '
                       f'(SELECT COUNT(*) FROM data_local WHERE id >= {REMOVE_FIRST})')


def remove_cases():
    a, b = REMOVE_FIRST, REMOVE_FIRST + 1
    mine = f'--graph-regex=^{PREFIX}rm-'
    return [
        ('remove count by device', [f'--host-id={a}']),
        ('remove count by two devices', [f'--host-id={a}', f'--host-id={b}']),
        ('remove count by device template', [f'--host-template-id={a}']),
        ('remove count by graph template', [f'--graph-template-id={b}']),
        ('remove count by expression', [mine]),
        ('remove count by two expressions', [f'--graph-regex=^{PREFIX}rm-core', f'--graph-regex=^{PREFIX}rm-edge']),
        ('remove count narrows together', [f'--host-id={a}', f'--graph-template-id={a}', mine]),
        ('remove count with an alternation is cleaned', [f'--graph-regex=^{PREFIX}rm-(core|far)']),
        ('remove count with nothing selected', [f'--host-id={a + 99}']),
        ('remove count ignores a device that has no graphs on this template', [f'--host-id={a + 2}', f'--graph-template-id={b}']),
        ('remove list by device', [f'--host-id={a}', '--list']),
        ('remove list by expression', [mine, '--list']),
        ('remove list without a selector', ['--list', '--host-id=' + str(a + 99)]),
        ('remove list nothing', [mine + 'nomatch', '--list']),
        ('remove force by expression', [mine, '--force']),
        ('remove force by expression keeps the data sources', [mine, '--force', '--preserve']),
        ('remove force one device', [f'--host-id={a + 2}', '--force']),
        ('remove force one graph template', [f'--graph-template-id={b}', '--force', '--preserve']),
        ('remove force nothing matching', [f'--host-id={a + 99}', '--force']),
        ('remove with no selector is refused', ['--force']),
        ('remove count with no selector is refused', ['--preserve']),
        ('remove list and force are exclusive', ['--list', '--force']),
        ('remove bad device id', ['--host-id=abc']),
        ('remove zero device id', ['--host-id=0']),
        ('remove negative device template id', [f'--host-template-id=-{a}']),
        ('remove bad graph template id', ['--graph-template-id=x']),
        ('remove device template error comes first', ['--host-id=x', '--graph-template-id=y', '--host-template-id=z']),
        ('remove graph template error comes before device', ['--host-id=x', '--graph-template-id=y']),
        ('remove exponent id is numeric', [f'--host-id={a}e0']),
        ('remove bare device id', ['--host-id']),
        ('remove empty device id is not seen', ['--host-id=']),
        ('remove empty expression is not seen', ['--graph-regex=', f'--host-id={a}']),
        ('remove quiet is named invalid and ignored', ['--quiet', f'--host-id={a}']),
        ('remove graph type is named invalid and ignored', ['--graph-type=line', f'--host-id={a}']),
        ('remove graph type without a value', ['--graph-type', f'--host-id={a}']),
        ('remove list devices', ['--list-hosts']),
        ('remove list devices of a template', ['--list-hosts', f'--host-template-id={a}']),
        ('remove list devices of two templates', ['--list-hosts', f'--host-template-id={a}', f'--host-template-id={b}']),
        ('remove list devices with a bad template', ['--list-hosts', '--host-template-id=x']),
        ('remove list device templates', ['--list-host-templates']),
        ('remove list graph templates', ['--list-graph-templates']),
        ('remove list graph templates of a template', ['--list-graph-templates', f'--host-template-id={b}']),
        ('remove list modes: devices win', ['--list-graph-templates', '--list-host-templates', '--list-hosts']),
        ('remove list modes: device templates beat graph templates', ['--list-graph-templates', '--list-host-templates']),
        ('remove list modes ignore force', ['--list-hosts', '--force']),
        ('remove help', ['-h']),
        ('remove upper help', ['-H']),
        ('remove long help', ['--help']),
        ('remove version', ['-v']),
        ('remove upper version', ['-V']),
        ('remove long version', ['--version']),
        ('remove no arguments', []),
    ]


def verify_remove_cases(harness, check):
    for label, arguments in remove_cases():
        remove_seed(harness)
        original = run(harness, QUIET_PHP + REMOVE_ORIGINAL, arguments)
        original_rows = remove_rows(harness)
        remove_seed(harness)
        native = run(harness, QUIET_PHP + REMOVE_SHIM, arguments)
        native_rows = remove_rows(harness)
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(normalise(original['stdout']) == normalise(native['stdout']), f'{label} prints the same lines')
        check(STTY.sub('', normalise(original['stderr'])) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same graphs and data sources')
    remove_reset(harness)


def verify_remove_differences(harness, check):
    a = REMOVE_FIRST
    mine = f'^{PREFIX}rm-'
    remove_seed(harness)
    # validate_is_regex() was meant to refuse these, but its caller tested for false, which it never returns.
    for label, expression in (('an expression over 50 bytes', mine + 'x' * 50), ('an expression with a semicolon', mine + ';x'),
                              ('an expression with nothing after the flag', None), ('an expression of only dropped characters', '||')):
        # A bare --graph-regex gave RLIKE '', which matches every title; "--graph-regex=" was not seen at all.
        refused = run(harness, REMOVE_SHIM, ['--graph-regex' if expression is None else f'--graph-regex={expression}', '--force'])
        check(refused['exit'] == 1 and 'is not a valid Regex!' in refused['stdout'], f'{label} is refused')
    check(remove_rows(harness).strip().split('\t')[0].count(',') == 4, 'a refused expression removes no graph')
    # getopt() skipped a flag it did not know; an unknown flag is refused here as in the other tools.
    unknown = run(harness, REMOVE_SHIM, ['--bogus', f'--host-id={a}'])
    check(unknown['exit'] == 1 and unknown['stdout'].startswith('ERROR: Invalid Parameter --bogus\n'), 'an unknown flag is an error with the help')
    # Without graph management nothing is read or removed.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 5')
    try:
        for arguments in (['--all', '--force'], ['--list'], ['--list-hosts'], ['--list-host-templates']):
            denied = run(harness, REMOVE_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without graph management is refused")
        check(remove_rows(harness).strip().split('\t')[0].count(',') == 4, 'a refused removal removes no graph')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 5)')
    json_report = run(harness, 'bin/console', ['kadupul:graph:remove', f'--host-id={a}', '--json'])
    check(json_report['exit'] == 0 and '"matched":2' in json_report['stdout'] and '"mode":"count"' in json_report['stdout'], 'bin/console counts as json without removing')
    both = run(harness, 'bin/console', ['kadupul:graph:remove', '--list', '--force'])
    check(both['exit'] == 2, 'bin/console treats --list with --force as invalid input')
    remove_reset(harness)


def add_template_seed(harness):
    """The removal seed plus one association, device 1 with graph template 1."""
    remove_seed(harness)
    harness.sql(f'INSERT INTO host_graph (host_id, graph_template_id) VALUES ({REMOVE_FIRST}, {REMOVE_FIRST})')


def add_template_rows(harness):
    """The associations of the seeded devices and how many graphs they have, which automation may add to."""
    return harness.sql(f"SELECT (SELECT GROUP_CONCAT(CONCAT(host_id, '-', graph_template_id) ORDER BY host_id, graph_template_id) FROM host_graph WHERE host_id >= {REMOVE_FIRST}), "
                       f'(SELECT COUNT(*) FROM graph_local WHERE host_id >= {REMOVE_FIRST})')


def add_template_cases():
    a, b, c = REMOVE_FIRST, REMOVE_FIRST + 1, REMOVE_FIRST + 2
    return [
        ('add template to a device', [f'--host-id={a}', f'--graph-template-id={b}']),
        ('add template to another device', [f'--host-id={c}', f'--graph-template-id={a}']),
        ('add template twice is refused', [f'--host-id={a}', f'--graph-template-id={a}']),
        ('add template with the debug flag', ['-d', f'--host-id={b}', f'--graph-template-id={a}']),
        ('add template with float-written ids', [f'--host-id={b}e0', f'--graph-template-id={a}.0']),
        ('add template with a padded template id', [f'--host-id={b}', f'--graph-template-id= {a}']),
        ('add template last id wins', [f'--host-id=x', f'--host-id={b}', f'--graph-template-id={a}']),
        ('add template quiet changes nothing for an add', [f'--host-id={b}', f'--graph-template-id={b}', '--quiet']),
        ('add template bad host id', ['--host-id=abc', f'--graph-template-id={a}']),
        ('add template bare host id', ['--host-id', f'--graph-template-id={a}']),
        ('add template empty host id', ['--host-id=', f'--graph-template-id={a}']),
        ('add template bad graph template id', [f'--host-id={a}', '--graph-template-id=abc']),
        ('add template bad id stops the run', ['--host-id=abc', '--bogus']),
        ('add template bad id stops before help', ['--graph-template-id=abc', '--help']),
        ('add template missing host id', [f'--graph-template-id={a}']),
        ('add template missing graph template id', [f'--host-id={a}']),
        ('add template unknown argument', ['--bogus']),
        ('add template unknown argument with a value', ['--bogus=1', f'--host-id={a}']),
        ('add template a leading = is no separator', ['=1']),
        ('add template list devices', ['--list-hosts']),
        ('add template list devices quietly', ['--list-hosts', '--quiet']),
        ('add template list graph templates', ['--list-graph-templates']),
        ('add template list graph templates quietly', ['--quiet', '--list-graph-templates']),
        ('add template list devices win', ['--list-graph-templates', '--list-hosts']),
        ('add template list ignores a missing id', ['--list-hosts', f'--host-id={a}']),
        ('add template list takes a value it ignores', ['--list-hosts=yes']),
        ('add template list with a bad id', ['--list-hosts', '--host-id=abc']),
        ('add template help', ['-h']),
        ('add template upper help', ['-H']),
        ('add template long help', ['--help']),
        ('add template version', ['-v']),
        ('add template upper version', ['-V']),
        ('add template long version', ['--version']),
        ('add template version takes a value it ignores', ['--version=3']),
        ('add template no arguments', []),
    ]


def verify_add_template_cases(harness, check):
    for label, arguments in add_template_cases():
        add_template_seed(harness)
        original = run(harness, QUIET_PHP + ADD_TEMPLATE_ORIGINAL, arguments)
        original_rows = add_template_rows(harness)
        add_template_seed(harness)
        native = run(harness, QUIET_PHP + ADD_TEMPLATE_SHIM, arguments)
        native_rows = add_template_rows(harness)
        # Only an argument error loses the version line above the help; the rest must match byte for byte.
        expected = without_utility(normalise(original['stdout']), ADD_TEMPLATE_UTILITY) if native['stdout'].startswith('ERROR: Invalid Argument') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(STTY.sub('', normalise(original['stderr'])) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same associations and graphs')
    remove_reset(harness)


def verify_add_template_differences(harness, check):
    a, b = REMOVE_FIRST, REMOVE_FIRST + 1
    unknown = REMOVE_FIRST + 900
    add_template_seed(harness)
    # db_fetch_cell() returns false for no row and the script tested isset(), so an unknown id passed and the script wrote an orphan row.
    original = run(harness, QUIET_PHP + ADD_TEMPLATE_ORIGINAL, [f'--host-id={unknown}', f'--graph-template-id={a}'])
    check(original['exit'] == 0 and original['stdout'].startswith('Success: Graph Template associated'), 'the original accepted an unknown device')
    check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id = {unknown}').strip() == '1', 'the original wrote a row for an unknown device')
    harness.sql(f'DELETE FROM host_graph WHERE host_id = {unknown}')
    for label, arguments, expected in (('an unknown device', [f'--host-id={unknown}', f'--graph-template-id={a}'], f'ERROR: Unknown Host Id ({unknown})\n'),
                                       ('an unknown graph template', [f'--host-id={a}', f'--graph-template-id={unknown}'], f'ERROR: Unknown Graph Template Id ({unknown})\n')):
        refused = run(harness, ADD_TEMPLATE_SHIM, arguments)
        check(refused['exit'] == 1 and refused['stdout'] == expected, f'{label} is refused')
    check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id = {unknown} OR graph_template_id = {unknown}').strip() == '0', 'a refused association writes nothing')
    # The web page for this is host.php, realm 3; graph management alone is not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    try:
        for arguments in ([f'--host-id={b}', f'--graph-template-id={b}'], ['--list-hosts'], ['--list-graph-templates']):
            denied = run(harness, ADD_TEMPLATE_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without device management is refused")
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id = {b}').strip() == '0', 'a refused association writes nothing for the device')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    report = run(harness, 'bin/console', ['kadupul:device:add-graph-template', f'--host-id={b}', f'--graph-template-id={b}', '--json'])
    check(report['exit'] == 0 and '"status":"attached"' in report['stdout'], 'bin/console associates and prints json')
    invalid = run(harness, 'bin/console', ['kadupul:device:add-graph-template', '--host-id=abc'])
    check(invalid['exit'] == 2, 'bin/console treats a bad id as invalid input')
    remove_reset(harness)


def add_query_seed(harness):
    """The removal seed plus two data queries and one association, device 1 with query 1 and the uptime method.

    Devices 1 and 2 are disabled and device 3 is down, so run_data_query() returns at once for them; device 4 is up
    without SNMP, so it runs the query to its end.
    """
    add_query_reset(harness)
    remove_seed(harness)
    a, b, c, d = REMOVE_FIRST, REMOVE_FIRST + 1, REMOVE_FIRST + 2, REMOVE_FIRST + 3
    harness.sql(f"UPDATE host SET disabled = 'on' WHERE id IN ({a}, {b}); UPDATE host SET status = 1 WHERE id = {c}; "
                f"INSERT INTO host (id, description, hostname, host_template_id, status, snmp_version) VALUES ({d}, '{PREFIX}dqup', '{PREFIX}dqup.example', {a}, 3, 0); "
                f"INSERT INTO snmp_query (id, hash, name) VALUES ({a}, '{'0' * 30}71', '{PREFIX}Interfaces'), ({b}, '{'0' * 30}72', '{PREFIX}Disks'); "
                f'INSERT INTO host_snmp_query (host_id, snmp_query_id, reindex_method) VALUES ({a}, {a}, 1)')


def add_query_reset(harness):
    harness.sql(f'DELETE FROM host_snmp_cache WHERE host_id >= {REMOVE_FIRST}; DELETE FROM host_snmp_query WHERE host_id >= {REMOVE_FIRST}; '
                f'DELETE FROM snmp_query WHERE id >= {REMOVE_FIRST}')
    remove_reset(harness)


def add_query_rows(harness):
    """The associations of the seeded devices, with their stored method, and how many cache rows the query run left."""
    return harness.sql(f"SELECT (SELECT GROUP_CONCAT(CONCAT(host_id, '-', snmp_query_id, '-', reindex_method, '-', sort_field, '-', title_format) ORDER BY host_id, snmp_query_id) FROM host_snmp_query WHERE host_id >= {REMOVE_FIRST}), "
                       f'(SELECT COUNT(*) FROM host_snmp_cache WHERE host_id >= {REMOVE_FIRST})')


def add_query_cases():
    a, b, c, d = REMOVE_FIRST, REMOVE_FIRST + 1, REMOVE_FIRST + 2, REMOVE_FIRST + 3
    cases = [
        ('add query to a device', [f'--host-id={b}', f'--data-query-id={a}', '--reindex-method=0']),
        ('add query to a down device', [f'--host-id={c}', f'--data-query-id={b}', '--reindex-method=3']),
        ('add query to a device that is up', [f'--host-id={d}', f'--data-query-id={a}', '--reindex-method=2']),
        ('add query twice is refused', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=1']),
        ('add query again with another method replaces it', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=2']),
        ('add query with the debug flag', ['-d', f'--host-id={b}', f'--data-query-id={b}', '--reindex-method=1']),
        ('add query with float-written ids', [f'--host-id={b}e0', f'--data-query-id={b}.0', '--reindex-method=1']),
        ('add query with a padded query id', [f'--host-id={b}', f'--data-query-id= {b}', '--reindex-method=1']),
        ('add query with a padded device id', [f'--host-id= {b} ', f'--data-query-id={b}', '--reindex-method=1']),
        ('add query last id wins', ['--host-id=x', f'--host-id={b}', f'--data-query-id={a}', '--reindex-method=1']),
        ('add query quiet changes nothing for an add', [f'--host-id={b}', f'--data-query-id={a}', '--reindex-method=1', '--quiet']),
        ('add query takes the last of two methods', [f'--host-id={b}', f'--data-query-id={a}', '--reindex-method=0', '--reindex-method=3']),
        ('add query a bad device id', ['--host-id=abc', f'--data-query-id={a}', '--reindex-method=1']),
        ('add query a bare device id', ['--host-id', f'--data-query-id={a}', '--reindex-method=1']),
        ('add query an empty device id', ['--host-id=', f'--data-query-id={a}', '--reindex-method=1']),
        ('add query a bad query id', [f'--host-id={a}', '--data-query-id=abc', '--reindex-method=1']),
        ('add query a bare query id', [f'--host-id={a}', '--data-query-id', '--reindex-method=1']),
        ('add query a bad method', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=bogus']),
        ('add query a bare method', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method']),
        ('add query an empty method', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=']),
        ('add query a method above the range', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=4']),
        ('add query a method below the range', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=-1']),
        ('add query a fractional method above the range', [f'--host-id={a}', f'--data-query-id={a}', '--reindex-method=3.5']),
        ('add query the first bad value stops the run', ['--reindex-method=bogus', '--host-id=abc', '--bogus']),
        ('add query a bad value stops before help', ['--data-query-id=abc', '--help']),
        ('add query help stops before a bad value', ['--help', '--host-id=abc']),
        ('add query missing device id', [f'--data-query-id={a}', '--reindex-method=1']),
        ('add query missing query id', [f'--host-id={a}', '--reindex-method=1']),
        ('add query missing method', [f'--host-id={a}', f'--data-query-id={a}']),
        ('add query only quiet', ['--quiet']),
        ('add query unknown argument', ['--bogus']),
        ('add query unknown argument with a value', ['--bogus=1', f'--host-id={a}']),
        ('add query a leading = is no separator', ['=1']),
        ('add query list devices', ['--list-hosts']),
        ('add query list devices quietly', ['--list-hosts', '--quiet']),
        ('add query list data queries', ['--list-data-queries']),
        ('add query list data queries quietly', ['--quiet', '--list-data-queries']),
        ('add query list devices win', ['--list-data-queries', '--list-hosts']),
        ('add query list ignores a missing id', ['--list-hosts', f'--host-id={a}']),
        ('add query list takes a value it ignores', ['--list-hosts=yes']),
        ('add query list with a bad id', ['--list-hosts', '--host-id=abc']),
        ('add query list with a bad method', ['--list-data-queries', '--reindex-method=bogus']),
        ('add query help', ['-h']),
        ('add query upper help', ['-H']),
        ('add query long help', ['--help']),
        ('add query version', ['-v']),
        ('add query upper version', ['-V']),
        ('add query long version', ['--version']),
        ('add query version takes a value it ignores', ['--version=3']),
        ('add query no arguments', []),
    ]
    # The method is read as PHP reads a number, then used as typed.
    for name in ('none', 'Uptime', 'INDEX', 'fields', 'UpTime'):
        cases.append((f'add query method named {name}', [f'--host-id={b}', f'--data-query-id={b}', f'--reindex-method={name}']))
    for spelling in ('1.5', '01', '2.0', ' 1', '1 ', '1e0', '-0', '0.5', '.5', '3.0', '+2', '0x1'):
        cases.append((f'add query method written {spelling!r}', [f'--host-id={b}', f'--data-query-id={b}', f'--reindex-method={spelling}']))
    return cases


def verify_add_query_cases(harness, check):
    for label, arguments in add_query_cases():
        add_query_seed(harness)
        original = run(harness, QUIET_PHP + ADD_QUERY_ORIGINAL, arguments)
        original_rows = add_query_rows(harness)
        add_query_seed(harness)
        native = run(harness, QUIET_PHP + ADD_QUERY_SHIM, arguments)
        native_rows = add_query_rows(harness)
        # Only an argument error loses the version line above the help; the rest must match byte for byte.
        expected = without_utility(normalise(original['stdout']), ADD_QUERY_UTILITY) if native['stdout'].startswith('ERROR: Invalid Argument') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same associations and cache')
    add_query_reset(harness)


def verify_add_query_differences(harness, check):
    a, b = REMOVE_FIRST, REMOVE_FIRST + 1
    unknown = REMOVE_FIRST + 900
    add_query_seed(harness)
    # db_fetch_cell() returns false for no row and the script tested isset(), so an unknown id passed and the script wrote an orphan row.
    original = run(harness, QUIET_PHP + ADD_QUERY_ORIGINAL, [f'--host-id={unknown}', f'--data-query-id={a}', '--reindex-method=1'])
    check(original['exit'] == 0 and original['stdout'].startswith('Success - Host'), 'the original accepted an unknown device')
    check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id = {unknown}').strip() == '1', 'the original wrote a row for an unknown device')
    harness.sql(f'DELETE FROM host_snmp_query WHERE host_id = {unknown}')
    for label, arguments, expected in (('an unknown device', [f'--host-id={unknown}', f'--data-query-id={a}', '--reindex-method=1'], f'ERROR: Unknown Host Id ({unknown})\n'),
                                       ('an unknown data query', [f'--host-id={a}', f'--data-query-id={unknown}', '--reindex-method=1'], f'ERROR: Unknown Data Query Id ({unknown})\n')):
        refused = run(harness, ADD_QUERY_SHIM, arguments)
        check(refused['exit'] == 1 and refused['stdout'] == expected, f'{label} is refused')
    check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id = {unknown} OR snmp_query_id = {unknown}').strip() == '0', 'a refused association writes nothing')
    # The web page for this is host.php, realm 3; graph management alone is not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    try:
        for arguments in ([f'--host-id={b}', f'--data-query-id={b}', '--reindex-method=1'], ['--list-hosts'], ['--list-data-queries']):
            denied = run(harness, ADD_QUERY_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without device management is refused")
        check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id = {b}').strip() == '0', 'a refused association writes nothing for the device')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    report = run(harness, 'bin/console', ['kadupul:device:add-data-query', f'--host-id={b}', f'--data-query-id={b}', '--reindex-method=uptime', '--json'])
    check(report['exit'] == 0 and '"status":"associated"' in report['stdout'] and '"reindex_method":"1"' in report['stdout'], 'bin/console associates and prints json')
    check(harness.sql(f'SELECT reindex_method FROM host_snmp_query WHERE host_id = {b} AND snmp_query_id = {b}').strip() == '1', 'bin/console stores the method')
    invalid = run(harness, 'bin/console', ['kadupul:device:add-data-query', '--host-id=abc'])
    check(invalid['exit'] == 2, 'bin/console treats a bad id as invalid input')
    add_query_reset(harness)


def add_ds_seed(harness):
    """Two devices (one polled over SNMP, one not) and three data templates: an SNMP one, a plain one, and one on a template-less input.

    The next data source id is fixed, so that the original and the shim print the same one.
    """
    add_ds_reset(harness)
    a, b = ADD_DS_FIRST, ADD_DS_FIRST + 1
    harness.sql(
        f"INSERT INTO data_template (id, hash, name) VALUES ({a}, '{'0' * 30}a1', '{PREFIX}ds_Traffic'), ({b}, '{'0' * 30}a2', '{PREFIX}ds_Plain'); "
        f"INSERT INTO host (id, description, hostname, host_template_id, snmp_version, snmp_community, status) VALUES "
        f"({a}, '{PREFIX}ds_core', '{PREFIX}ds_core.example', 0, 2, 'public', 3), ({b}, '{PREFIX}ds_quiet', '{PREFIX}ds_quiet.example', 0, 0, '', 3); "
        f"INSERT INTO data_template_data (local_data_id, data_template_id, data_input_id, name, name_cache, active, rrd_step, data_source_profile_id) VALUES "
        f"(0, {a}, 1, '|host_description| - Traffic', '', 'on', 300, 1), (0, {b}, 0, 'Plain DS', '', 'on', 60, 1); "
        f"INSERT INTO data_template_rrd (local_data_id, data_template_id, data_source_name, rrd_heartbeat, rrd_maximum, data_source_type_id) VALUES "
        f"(0, {a}, 'traffic_in', 600, '0', 2), (0, {a}, 'traffic_out', 600, '0', 2), (0, {b}, 'plain', 120, '100', 1); "
        f"INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) "
        f"SELECT 6, id, '', '.1.3.6.1.2.1.2.2.1.10.1' FROM data_template_data WHERE data_template_id = {a} AND local_data_id = 0; "
        f'INSERT INTO data_local (id, data_template_id, host_id) VALUES ({ADD_DS_FIRST - 1}, 0, 0); '
        f'ALTER TABLE data_local AUTO_INCREMENT = {ADD_DS_NEXT}')


def add_ds_reset(harness):
    created = f'SELECT id FROM data_local WHERE id >= {ADD_DS_FIRST - 1}'
    harness.sql(
        f'DELETE FROM poller_item WHERE local_data_id IN ({created}); '
        f'DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id IN ({created}) OR data_template_id >= {ADD_DS_FIRST}); '
        f'DELETE FROM data_template_rrd WHERE local_data_id IN ({created}) OR data_template_id >= {ADD_DS_FIRST}; '
        f'DELETE FROM data_template_data WHERE local_data_id IN ({created}) OR data_template_id >= {ADD_DS_FIRST}; '
        f'DELETE FROM data_local WHERE id >= {ADD_DS_FIRST - 1}; '
        f'DELETE FROM host WHERE id >= {ADD_DS_FIRST}; '
        f'DELETE FROM data_template WHERE id >= {ADD_DS_FIRST}')


def add_ds_rows(harness):
    """Every data source the cases may add: its row, its data, its RRD items, its input data and its poller items."""
    created = f'(SELECT id FROM data_local WHERE id >= {ADD_DS_NEXT})'
    return harness.sql(
        f"SELECT 'local', id, host_id, data_template_id, snmp_query_id, snmp_index, orphan FROM data_local WHERE id >= {ADD_DS_NEXT} ORDER BY id; "
        f"SELECT 'data', local_data_id, data_template_id, data_input_id, name, name_cache, active, rrd_step, data_source_path, data_source_profile_id, local_data_template_data_id > 0 "
        f'FROM data_template_data WHERE local_data_id IN {created} ORDER BY local_data_id; '
        f"SELECT 'rrd', local_data_id, data_source_name, rrd_heartbeat, rrd_maximum, data_source_type_id, data_template_id FROM data_template_rrd WHERE local_data_id IN {created} ORDER BY local_data_id, data_source_name; "
        f"SELECT 'input', d.local_data_id, i.data_input_field_id, i.t_value, i.value FROM data_input_data i JOIN data_template_data d ON d.id = i.data_template_data_id "
        f'WHERE d.local_data_id IN {created} ORDER BY d.local_data_id, i.data_input_field_id; '
        f"SELECT 'poller', local_data_id, host_id, action, hostname, rrd_name, rrd_path, arg1, rrd_num FROM poller_item WHERE local_data_id IN {created} ORDER BY local_data_id, rrd_name")


def add_ds_cases():
    a, b = ADD_DS_FIRST, ADD_DS_FIRST + 1
    ids = [f'--host-id={a}', f'--data-template-id={a}']
    cases = [
        ('add ds on a polled device', [ids]),
        ('add ds on a device that is not polled', [[f'--host-id={b}', f'--data-template-id={a}']]),
        ('add ds from a plain template', [[f'--host-id={a}', f'--data-template-id={b}']]),
        ('add ds from a plain template on a quiet device', [[f'--host-id={b}', f'--data-template-id={b}']]),
        ('add ds on no device', [['--host-id=0', f'--data-template-id={b}']]),
        ('add ds on no device from an SNMP template', [['--host-id=0', f'--data-template-id={a}']]),
        ('add ds with no template', [[f'--host-id={a}', '--data-template-id=0']]),
        ('add ds on no device with no template', [['--host-id=0', '--data-template-id=0']]),
        ('add ds twice', [ids, ids]),
        ('add ds for two templates', [ids, [f'--host-id={a}', f'--data-template-id={b}']]),
        ('add ds arguments the other way round', [[f'--data-template-id={a}', f'--host-id={a}']]),
        ('add ds last device id wins', [['--host-id=x', f'--host-id={a}', f'--data-template-id={a}']]),
        ('add ds last of two numeric device ids wins', [[f'--host-id={b}', f'--host-id={a}', f'--data-template-id={a}']]),
        ('add ds last template id wins', [[f'--host-id={a}', f'--data-template-id={b}', f'--data-template-id={a}']]),
        ('add ds values given to flags that take none', [['--help=no']]),
        ('add ds float-written device id', [[f'--host-id={a}e0', f'--data-template-id={a}']]),
        ('add ds float-written template id', [[f'--host-id={a}', f'--data-template-id={a}.0']]),
        ('add ds float-written zero device id', [['--host-id=0.0', f'--data-template-id={b}']]),
        ('add ds negative zero device id', [['--host-id=-0', f'--data-template-id={b}']]),
        ('add ds float-written zero template id', [[f'--host-id={a}', '--data-template-id=0.0']]),
        ('add ds exponent-written zero template id', [[f'--host-id={a}', '--data-template-id=0e5']]),
        ('add ds padded device id', [[f'--host-id= {a} ', f'--data-template-id={a}']]),
        ('add ds padded template id', [[f'--host-id={a}', f'--data-template-id= {a}']]),
        ('add ds trailing-padded template id', [[f'--host-id={a}', f'--data-template-id={a} ']]),
        ('add ds padded zero device id', [['--host-id= 0 ', f'--data-template-id={b}']]),
        ('add ds a bad device id', [['--host-id=abc', f'--data-template-id={a}']]),
        ('add ds a bare device id', [['--host-id', f'--data-template-id={a}']]),
        ('add ds an empty device id', [['--host-id=', f'--data-template-id={a}']]),
        ('add ds a hex device id', [['--host-id=0x1', f'--data-template-id={a}']]),
        ('add ds a bad template id', [[f'--host-id={a}', '--data-template-id=abc']]),
        ('add ds a bare template id', [[f'--host-id={a}', '--data-template-id']]),
        ('add ds an empty template id', [[f'--host-id={a}', '--data-template-id=']]),
        ('add ds the first bad value stops the run', [['--data-template-id=abc', '--host-id=abc', '--bogus']]),
        ('add ds a bad value stops before help', [['--host-id=abc', '--help']]),
        ('add ds help stops before a bad value', [['--help', '--host-id=abc']]),
        ('add ds help stops before an unknown argument', [['--help', '--bogus']]),
        ('add ds an unknown argument stops before help', [['--bogus', '--help']]),
        ('add ds missing device id', [[f'--data-template-id={a}']]),
        ('add ds missing template id', [[f'--host-id={a}']]),
        ('add ds a bad device id wins over a missing template id', [['--host-id=abc']]),
        ('add ds only the debug flag', [['-d']]),
        ('add ds the quiet flag', [[*ids, '--quiet']]),
        ('add ds an unknown argument', [['--bogus']]),
        ('add ds an unknown argument with a value', [['--bogus=1', *ids]]),
        ('add ds an unknown argument after the ids', [[*ids, '--bogus']]),
        ('add ds a leading = is no separator', [['=1']]),
        ('add ds a list flag of another script', [['--list-hosts']]),
        ('add ds help', [['-h']]),
        ('add ds upper help', [['-H']]),
        ('add ds long help', [['--help']]),
        ('add ds version', [['-v']]),
        ('add ds upper version', [['-V']]),
        ('add ds long version', [['--version']]),
        ('add ds version takes a value it ignores', [['--version=3']]),
        ('add ds version after the ids', [[*ids, '--version']]),
        ('add ds no arguments', [[]]),
    ]
    return cases


def same_add_ds(check, label, original, native):
    # Only an argument error loses the version line above the help; the rest must match byte for byte.
    expected = without_utility(normalise(original['stdout']), ADD_DS_UTILITY) if native['stdout'].startswith('ERROR: Invalid Parameter') else normalise(original['stdout'])
    check(original['exit'] == native['exit'], f'{label} keeps its exit code')
    check(expected == normalise(native['stdout']), f'{label} prints the same lines')
    check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label} prints the same errors')


def verify_add_ds_cases(harness, check):
    for label, steps in add_ds_cases():
        outcomes = []
        for script in (ADD_DS_ORIGINAL, ADD_DS_SHIM):
            add_ds_seed(harness)
            outcomes.append([(run(harness, QUIET_PHP + script, arguments), add_ds_rows(harness)) for arguments in steps])
        for number, ((original, original_rows), (native, native_rows)) in enumerate(zip(*outcomes), start=1):
            same_add_ds(check, f'{label}, step {number}', original, native)
            check(original_rows == native_rows, f'{label}, step {number} leaves the same data sources, data and poller items')
    add_ds_reset(harness)


def verify_add_ds_differences(harness, check):
    a, b = ADD_DS_FIRST, ADD_DS_FIRST + 1
    unknown = ADD_DS_FIRST + 900
    add_ds_seed(harness)
    seeded = add_ds_rows(harness)
    # The original wrote a data source for any id, and the database clamped or rounded it: an unknown device or template left a junk row.
    # An unknown device made push_out_host() fail with a fatal error after the row was written, so that case printed nothing and exited 255.
    for label, arguments, exit_code in (('an unknown device', [f'--host-id={unknown}', f'--data-template-id={a}'], 255), ('an unknown template', [f'--host-id={a}', f'--data-template-id={unknown}'], 0),
                                        ('a negative template', [f'--host-id={a}', '--data-template-id=-3'], 0), ('a fractional device', [f'--host-id={a}.5', f'--data-template-id={a}'], 0)):
        add_ds_seed(harness)
        original = run(harness, QUIET_PHP + ADD_DS_ORIGINAL, arguments)
        check(original['exit'] == exit_code and original['stdout'].startswith('DS Added - DS[') == (exit_code == 0), f'the original went on with {label}')
        check(add_ds_rows(harness) != seeded, f'the original wrote a data source for {label}')
        add_ds_seed(harness)
        refused = run(harness, ADD_DS_SHIM, arguments)
        check(refused['exit'] == 1 and refused['stdout'].startswith('ERROR: Unknown '), f'{label} is refused')
        check(add_ds_rows(harness) == seeded, f'{label} is refused before anything is written')
    for arguments, expected in (([f'--host-id={unknown}', f'--data-template-id={a}'], f'ERROR: Unknown Host Id ({unknown})\n'),
                                ([f'--host-id={a}', f'--data-template-id={unknown}'], f'ERROR: Unknown Data Template Id ({unknown})\n'),
                                (['--host-id=-1', f'--data-template-id={a}'], 'ERROR: Unknown Host Id (-1)\n')):
        refused = run(harness, ADD_DS_SHIM, arguments)
        check(refused['exit'] == 1 and refused['stdout'] == expected, f'{expected.strip()} is the refusal line')
    # The id the command prints is the id of the row it wrote.
    add_ds_seed(harness)
    added = run(harness, ADD_DS_SHIM, [f'--host-id={a}', f'--data-template-id={a}'])
    check(added['stdout'] == f'DS Added - DS[{ADD_DS_NEXT}]\n' and harness.sql(f'SELECT COUNT(*) FROM data_local WHERE id = {ADD_DS_NEXT} AND host_id = {a}').strip() == '1', 'the printed id is the new data source')
    # The web pages for this are data_sources.php and host.php, realm 3; graph management alone is not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    add_ds_seed(harness)
    try:
        for arguments in ([f'--host-id={b}', f'--data-template-id={b}'], ['--host-id=0', '--data-template-id=0']):
            denied = run(harness, ADD_DS_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without device management is refused")
        check(add_ds_rows(harness) == seeded, 'a refused run writes nothing')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    report = run(harness, 'bin/console', ['kadupul:data-source:add', f'--host-id={a}', f'--data-template-id={b}', '--json'])
    check(report['exit'] == 0 and '"status":"added"' in report['stdout'] and f'"data_source_id":{ADD_DS_NEXT}' in report['stdout'], 'bin/console adds a data source and prints json')
    invalid = run(harness, 'bin/console', ['kadupul:data-source:add', '--host-id=abc'])
    check(invalid['exit'] == 2, 'bin/console treats a bad id as invalid input')
    add_ds_reset(harness)


def rebuild_ids():
    a = REBUILD_FIRST
    return {'polled': a, 'quiet': a + 1, 'disabled': a + 2, 'second': a + 3, 'template': a, 'other_template': a + 1, 'data_template': a}


def rebuild_setup(harness):
    """Four devices on two device templates, and an SNMP data source on three of them, made through add_datasource.php so the poller cache rows are the real ones.

    Returns the settings the cases change, to put back afterwards.
    """
    rebuild_teardown(harness)
    ids = rebuild_ids()
    a, t1, t2 = ids['polled'], ids['template'], ids['other_template']
    harness.sql(
        f"INSERT INTO data_template (id, hash, name) VALUES ({ids['data_template']}, '{'0' * 30}b1', '{PREFIX}rebuild_Traffic'); "
        f"INSERT INTO host (id, description, hostname, host_template_id, snmp_version, snmp_community, status, disabled) VALUES "
        f"({a}, '{PREFIX}rebuild_core', '{PREFIX}rebuild_core.example', {t1}, 2, 'public', 3, ''), "
        f"({a + 1}, '{PREFIX}rebuild_quiet', '{PREFIX}rebuild_quiet.example', {t2}, 0, '', 3, ''), "
        f"({a + 2}, '{PREFIX}rebuild_off', '{PREFIX}rebuild_off.example', {t1}, 2, 'public', 3, 'on'), "
        f"({a + 3}, '{PREFIX}rebuild_edge', '{PREFIX}rebuild_edge.example', {t1}, 2, 'private', 3, ''); "
        f"INSERT INTO data_template_data (local_data_id, data_template_id, data_input_id, name, name_cache, active, rrd_step, data_source_profile_id) VALUES "
        f"(0, {ids['data_template']}, 1, '|host_description| - Traffic', '', 'on', 300, 1); "
        f"INSERT INTO data_template_rrd (local_data_id, data_template_id, data_source_name, rrd_heartbeat, rrd_maximum, data_source_type_id) VALUES "
        f"(0, {ids['data_template']}, 'traffic_in', 600, '0', 2); "
        f"INSERT INTO data_input_data (data_input_field_id, data_template_data_id, t_value, value) "
        f"SELECT 6, id, '', '.1.3.6.1.2.1.2.2.1.10.1' FROM data_template_data WHERE data_template_id = {ids['data_template']} AND local_data_id = 0")
    for device in (ids['polled'], ids['disabled'], ids['second']):
        added = run(harness, ADD_DS_SHIM, [f'--host-id={device}', f"--data-template-id={ids['data_template']}"])
        if added['exit'] != 0:
            raise RuntimeError('rebuild_poller_cache seed failed: ' + added['stdout'] + added['stderr'])
    saved = harness.sql("SELECT name, value FROM settings WHERE name IN ('path_php_binary', 'commands_processes')")
    php = harness.command('sh', '-c', 'command -v php')['stdout'].strip()
    # The master starts its children through this binary; the setting is put back afterwards.
    harness.sql(f"REPLACE INTO settings (name, value) VALUES ('path_php_binary', '{php}'); DELETE FROM settings WHERE name = 'commands_processes'")
    return saved


def rebuild_teardown(harness, saved=None):
    """Removes the seed; with the settings rebuild_setup() saved, also puts them back."""
    ids = rebuild_ids()
    first, last = ids['polled'], ids['second']
    created = f'SELECT id FROM data_local WHERE host_id BETWEEN {first} AND {last}'
    harness.sql(
        "DELETE FROM processes WHERE tasktype = 'pushout'; "
        f'DELETE FROM poller_item WHERE host_id BETWEEN {first} AND {last} OR local_data_id IN ({created}); '
        f"DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id IN ({created}) OR data_template_id = {ids['data_template']}); "
        f"DELETE FROM data_template_rrd WHERE local_data_id IN ({created}) OR data_template_id = {ids['data_template']}; "
        f"DELETE FROM data_template_data WHERE local_data_id IN ({created}) OR data_template_id = {ids['data_template']}; "
        f'DELETE FROM data_local WHERE host_id BETWEEN {first} AND {last}; '
        f'DELETE FROM host WHERE id BETWEEN {first} AND {last}; '
        f"DELETE FROM data_template WHERE id = {ids['data_template']}")
    if saved is not None:
        harness.sql("DELETE FROM settings WHERE name IN ('path_php_binary', 'commands_processes')")
        for line in saved.splitlines():
            name, _, value = line.partition('\t')
            harness.sql(f"INSERT INTO settings (name, value) VALUES ('{name}', CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")


def rebuild_stale(harness):
    """The cache as a case finds it: no rows for the polled devices, a stray row for the device without data sources, a stale one for the disabled device."""
    ids = rebuild_ids()
    first, last = ids['polled'], ids['second']
    disabled = harness.sql(f"SELECT id FROM data_local WHERE host_id = {ids['disabled']}").strip()
    harness.sql(
        "DELETE FROM processes WHERE tasktype = 'pushout'; "
        f'DELETE FROM poller_item WHERE host_id BETWEEN {first} AND {last}; '
        f"INSERT INTO poller_item (local_data_id, host_id, action, hostname, rrd_name, arg1) VALUES "
        f"({REBUILD_FIRST + 900}, {ids['quiet']}, 1, 'stray', 'stray', 'echo stray'), ({disabled}, {ids['disabled']}, 0, 'stale', 'traffic_in', 'stale')")


def rebuild_settle(harness):
    """Waits for background children, which may outlive their master, before rows are compared."""
    for _ in range(60):
        if harness.sql("SELECT COUNT(*) FROM processes WHERE tasktype = 'pushout'").strip() == '0':
            return
        time.sleep(0.5)


def rebuild_rows(harness):
    ids = rebuild_ids()
    return harness.sql(
        'SELECT local_data_id, host_id, action, present, hostname, snmp_community, snmp_version, snmp_port, rrd_name, rrd_path, rrd_num, rrd_step, arg1 '
        f"FROM poller_item WHERE host_id BETWEEN {ids['polled']} AND {ids['second']} ORDER BY local_data_id, rrd_name; "
        "SELECT taskname, taskid FROM processes WHERE tasktype = 'pushout' ORDER BY taskname, taskid")


def rebuild_cases():
    ids = rebuild_ids()
    a, q, off, t1, dt = ids['polled'], ids['quiet'], ids['disabled'], ids['template'], ids['data_template']
    child = ['--type=child', '--child=1', '--threads=1']
    # (label, arguments, whether the run starts children in the background)
    return [
        ('rebuild a child for one device', [*child, f'--host-id={a}'], False),
        ('rebuild a child for a device template', [*child, f'--host-template-id={t1}'], False),
        ('rebuild the first of two child slices', ['--type=child', '--child=1', '--threads=2', f'--host-template-id={t1}'], False),
        ('rebuild the second of two child slices', ['--type=child', '--child=2', '--threads=2', f'--host-template-id={t1}'], False),
        ('rebuild a child in debug', [*child, f'--host-template-id={t1}', '--debug'], False),
        ('rebuild a child with the short debug flag', [*child, f'--host-id={a}', '-d'], False),
        ('rebuild a child for a data template', [*child, f'--host-template-id={t1}', f'--data-template-id={dt}'], False),
        ('rebuild a child for an unused data template', [*child, f'--host-id={a}', f'--data-template-id={dt + 5}'], False),
        ('rebuild a child for a device without data sources', [*child, f'--host-id={q}'], False),
        ('rebuild a child for a disabled device', [*child, f'--host-id={off}'], False),
        ('rebuild a forced child', [*child, f'--host-id={a}', '--force'], False),
        ('rebuild a child on the default thread count', ['--type=child', '--child=1', f'--host-id={a}'], False),
        ('rebuild a child with no thread number', ['--type=child', '--threads=1', f'--host-id={a}'], False),
        ('rebuild a child with a padded device id', [*child, f'--host-id= {a} '], False),
        ('rebuild a child with a zero-padded device id', [*child, f'--host-id=0{a}'], False),
        ('rebuild a child with a float-written template id', [*child, f'--host-template-id={t1}.0'], False),
        ('rebuild a child last device id wins', [*child, f'--host-id={q}', f'--host-id={a}'], False),
        ('rebuild an unknown type', ['--type=other', f'--host-id={a}'], False),
        ('rebuild an empty type', ['--type', f'--host-id={a}'], False),
        ('rebuild an unknown device', [f'--host-id={REBUILD_FIRST + 900}'], False),
        ('rebuild only a disabled device', [f'--host-id={off}'], False),
        ('rebuild an unknown device in debug', [f'--host-id={REBUILD_FIRST + 900}', '--debug'], False),
        ('rebuild one device', [f'--host-id={a}'], True),
        ('rebuild a device template on two threads', [f'--host-template-id={t1}', '--threads=2'], True),
        ('rebuild a device template forced in debug', [f'--host-template-id={t1}', '--force', '--debug'], True),
        ('rebuild a zero device template', ['--host-template-id=0', f'--host-id={a}'], True),
        ('rebuild values given to flags that take none', [f'--host-id={a}', '--type=child', '--child=1', '--threads=1', '--force=no', '--debug=no'], False),
        ('rebuild a zero device id', ['--host-id=0'], False),
        ('rebuild a negative device id', ['--host-id=-1'], False),
        ('rebuild a fractional device id', ['--host-id=1.5'], False),
        ('rebuild a bad device id', ['--host-id=abc'], False),
        ('rebuild a bare device id', ['--host-id'], False),
        ('rebuild a bad device template id', ['--host-template-id=abc'], False),
        ('rebuild an empty device template id', ['--host-template-id='], False),
        ('rebuild a bad data template id', ['--data-template-id=x1'], False),
        ('rebuild a bare data template id', ['--data-template-id'], False),
        ('rebuild zero threads', ['--threads=0'], False),
        ('rebuild negative threads', ['--threads=-1'], False),
        ('rebuild fractional threads', ['--threads=1.5'], False),
        ('rebuild bare threads', ['--threads'], False),
        ('rebuild bad threads after good ones', ['--threads=3', '--threads=x'], False),
        ('rebuild bad threads before good ones', ['--threads=x', '--threads=3'], False),
        ('rebuild bad threads quote the setting', ['--threads=x', f'--host-id={a}'], False),
        ('rebuild the first bad value stops the run', ['--host-id=abc', '--threads=x', '--bogus'], False),
        ('rebuild a bad value stops before help', ['--host-id=abc', '--help'], False),
        ('rebuild help stops before a bad value', ['--help', '--host-id=abc'], False),
        ('rebuild an unknown argument stops before help', ['--bogus', '--help'], False),
        ('rebuild an unknown argument', ['--bogus'], False),
        ('rebuild an unknown argument with a value', ['--bogus=1'], False),
        ('rebuild an unknown argument after an id', [f'--host-id={a}', '--bogus'], False),
        ('rebuild a leading = is no separator', ['=1'], False),
        ('rebuild the quiet flag of other scripts', ['--quiet'], False),
        ('rebuild a list flag of another script', ['--list-hosts'], False),
        ('rebuild help', ['-h'], False),
        ('rebuild upper help', ['-H'], False),
        ('rebuild long help', ['--help'], False),
        ('rebuild help with a value', ['--help=no'], False),
        ('rebuild version', ['-v'], False),
        ('rebuild upper version', ['-V'], False),
        ('rebuild long version', ['--version'], False),
        ('rebuild version after an id', [f'--host-id={a}', '--version'], False),
    ]


def rebuild_text(text):
    return REBUILD_TIMING.sub('', REBUILD_CLOCK.sub('HH:MM:SS - ', REBUILD_DISPLAYED.sub('', normalise(text))))


def same_rebuild(check, label, original, native):
    # Only an argument error loses the version line above the help; the rest must match byte for byte.
    expected = rebuild_text(original['stdout'])
    if native['stdout'].startswith('ERROR: Invalid Parameter'):
        expected = without_utility(expected, REBUILD_UTILITY)
    check(original['exit'] == native['exit'], f'{label} keeps its exit code')
    check(expected == rebuild_text(native['stdout']), f'{label} prints the same lines')
    check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label} prints the same errors')


def verify_rebuild_cases(harness, check):
    for label, arguments, background in rebuild_cases():
        outcomes = []
        for script in (REBUILD_ORIGINAL, REBUILD_SHIM):
            rebuild_stale(harness)
            outcome = run(harness, QUIET_PHP + script, arguments)
            if background:
                rebuild_settle(harness)
            outcomes.append((outcome, rebuild_rows(harness)))
        (original, original_rows), (native, native_rows) = outcomes
        same_rebuild(check, label, original, native)
        check(original_rows == native_rows, f'{label} leaves the same poller items and processes')


def verify_rebuild_differences(harness, check):
    ids = rebuild_ids()
    a = ids['polled']
    rebuild_stale(harness)
    stale = rebuild_rows(harness)
    # Another master is registered and alive: both end silently after the warning, and --force runs anyway.
    holder = harness.command('sh', '-c', "php -r 'sleep(30);' >/dev/null 2>&1 & echo $!")['stdout'].strip()
    try:
        for script in (REBUILD_ORIGINAL, REBUILD_SHIM):
            rebuild_stale(harness)
            harness.sql(f"INSERT INTO processes (pid, tasktype, taskname, taskid, timeout, started) VALUES ({holder}, 'pushout', 'child', 1, 86400, NOW())")
            busy = run(harness, QUIET_PHP + script, ['--type=child', '--child=1', '--threads=1', f'--host-id={a}'])
            check(busy['exit'] == 0 and busy['stdout'] == 'WARNING: Do not interrupt this script.  Rebuilding Poller Cache can take quite some time\n', f'{script} ends silently while a child is registered')
            check(rebuild_rows(harness).replace(f'child\t1\n', '', 1) == stale, f'{script} writes nothing while a child is registered')
            forced = run(harness, QUIET_PHP + script, ['--type=child', '--child=1', '--threads=1', f'--host-id={a}', '--force'])
            check(forced['exit'] == 0 and 'Child Started Process 1 with 1 hosts' in forced['stdout'], f'{script} --force runs while a child is registered')
    finally:
        harness.command('kill', holder)
    # SIGTERM to the master: the script's handler unregisters it and exits 1, after the lines it printed.
    for script in (REBUILD_ORIGINAL, REBUILD_SHIM):
        rebuild_stale(harness)
        stopped = harness.command('sh', '-c', f"cd {ROOT}; {QUIET} php {QUIET_PHP}{script} --host-template-id={ids['template']} --threads=2 & p=$!; sleep 3; kill -TERM $p; wait $p; echo \"exit=$?\"")
        check(stopped['stdout'].endswith('There are 2 hosts, 2 threads and 1 hosts to process per thread\nexit=1\n'), f'{script} stops on SIGTERM with exit 1')
        rebuild_settle(harness)
        check(harness.sql("SELECT COUNT(*) FROM processes WHERE tasktype = 'pushout' AND taskname = 'rmaster'").strip() == '0', f'{script} unregisters the master on SIGTERM')
    # A thread number that is not a number made the original fail with a PHP fatal error (exit 255); the shim reports the failure and exits 1.
    rebuild_stale(harness)
    original = run(harness, QUIET_PHP + REBUILD_ORIGINAL, ['--type=child', '--child=abc', '--threads=1', f'--host-id={a}'])
    check(original['exit'] == 255, 'the original died on a thread number that is not a number')
    rebuild_stale(harness)
    failed = run(harness, REBUILD_SHIM, ['--type=child', '--child=abc', '--threads=1', f'--host-id={a}'])
    check(failed['exit'] == 1 and failed['stdout'].endswith('ERROR: Rebuilding the poller cache failed\n'), 'the shim reports a failed run')
    # The web page for this is utilities.php, realm 15; device management alone is not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 15')
    rebuild_stale(harness)
    try:
        for arguments in ([f'--host-id={a}'], ['--type=child', '--child=1', '--threads=1', f'--host-id={a}'], ['--threads=x']):
            denied = run(harness, REBUILD_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without the Utilities realm is refused")
        check(rebuild_rows(harness) == stale, 'a refused run writes and registers nothing')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 15)')
    rebuild_stale(harness)
    report = run(harness, 'bin/console', ['kadupul:poller-cache:rebuild', '--type=child', '--child=1', '--threads=1', f'--host-id={a}', '--json'])
    check(report['exit'] == 0 and report['stdout'] == '{"status":"rebuilt","database":"local","type":"child","hosts":1}\n', 'bin/console rebuilds and prints json')
    invalid = run(harness, 'bin/console', ['kadupul:poller-cache:rebuild', '--threads=x'])
    check(invalid['exit'] == 2, 'bin/console treats a bad thread count as invalid input')


def tree_seed(harness):
    """The removal seed, a site, and one tree: two headers at the root, a graph and a device under the first, a site under the second."""
    remove_seed(harness)
    tree_reset(harness)
    a, t = REMOVE_FIRST, TREE_FIRST
    harness.sql(
        f"INSERT INTO sites (id, name) VALUES ({a}, '{PREFIX}tsite'), ({a + 1}, '{PREFIX}tsite-two'); "
        f"INSERT INTO graph_tree (id, sort_type, name) VALUES ({t}, 2, '{PREFIX}tree-one'); "
        f'INSERT INTO graph_tree_items (id, parent, position, graph_tree_id, local_graph_id, title, host_id, site_id, host_grouping_type, sort_children_type) VALUES '
        f"({a}, 0, 1, {t}, 0, '{PREFIX}h1', 0, 0, 1, 2), ({a + 1}, 0, 2, {t}, 0, '{PREFIX}h2', 0, 0, 1, 1), "
        f'({a + 2}, {a}, 1, {t}, {a}, NULL, 0, 0, 1, 0), ({a + 3}, {a}, 2, {t}, 0, NULL, {a}, 0, 2, 0), ({a + 4}, {a + 1}, 1, {t}, 0, NULL, 0, {a}, 1, 0); '
        # The next ids are fixed too, so that the original and the shim print the same ones.
        f'ALTER TABLE graph_tree AUTO_INCREMENT = {t + 10}; ALTER TABLE graph_tree_items AUTO_INCREMENT = {a + 10}')


def tree_reset(harness):
    harness.sql(f'DELETE FROM graph_tree_items WHERE graph_tree_id >= {TREE_FIRST} OR id >= {REMOVE_FIRST}; '
                f'DELETE FROM graph_tree WHERE id >= {TREE_FIRST}; '
                f'DELETE FROM sites WHERE id >= {REMOVE_FIRST}')


def tree_rows(harness):
    """Every tree and node the cases may add."""
    return harness.sql(
        "SELECT (SELECT GROUP_CONCAT(CONCAT_WS(',', id, name, sort_type) ORDER BY id SEPARATOR ';') FROM graph_tree WHERE id >= %d), "
        "(SELECT GROUP_CONCAT(CONCAT_WS(',', id, parent, position, graph_tree_id, local_graph_id, IFNULL(title, '~'), host_id, site_id, host_grouping_type, sort_children_type) "
        "ORDER BY id SEPARATOR ';') FROM graph_tree_items WHERE id >= %d)" % (TREE_FIRST, REMOVE_FIRST))


def tree_cases():
    a, b = REMOVE_FIRST, REMOVE_FIRST + 1
    h1, h2, graph_node, host_node, site_node = a, a + 1, a + 2, a + 3, a + 4
    t = TREE_FIRST
    new = f'--name={PREFIX}new'
    node = ['--type=node', f'--tree-id={t}']
    return [
        ('tree add', ['--type=tree', new]),
        ('tree add manual', ['--type=tree', new, '--sort-method=manual']),
        ('tree add natural', ['--type=tree', new, '--sort-method=natural']),
        ('tree add numeric', ['--type=tree', new, '--sort-method=numeric']),
        ('tree add alpha', ['--type=tree', new, '--sort-method=alpha']),
        ('tree add padded name', ['--type=tree', f'--name= {PREFIX}new ']),
        ('tree add name with a quote', ['--type=tree', f"--name={PREFIX}it's"]),
        ('tree add that exists', ['--type=tree', f'--name={PREFIX}tree-one']),
        ('tree add that exists in another case', ['--type=tree', f'--name={PREFIX.upper()}TREE-ONE']),
        ('tree add without a name', ['--type=tree']),
        ('tree add with a bare name', ['--type=tree', '--name']),
        ('tree add with the name 0', ['--type=tree', '--name=0']),
        ('tree add with a bad sort method', ['--type=tree', new, '--sort-method=random']),
        ('tree add with a bare sort method', ['--type=tree', new, '--sort-method']),
        ('tree add a name before a bad sort method', ['--type=tree', '--sort-method=random']),
        ('tree add ignores node options', ['--type=tree', new, '--tree-id=x', '--node-type=nonsense']),
        ('tree add last name wins', ['--type=tree', '--name=ignored', new]),
        ('tree add quiet', ['--type=tree', new, '--quiet']),
        ('node header at the root', [*node, '--node-type=header', f'--name={PREFIX}hx']),
        ('node header under a header', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={h1}']),
        ('node header manual', [*node, '--node-type=header', f'--name={PREFIX}hx', '--sort-method=manual']),
        ('node header numeric', [*node, '--node-type=header', f'--name={PREFIX}hx', '--sort-method=numeric']),
        ('node header natural', [*node, '--node-type=header', f'--name={PREFIX}hx', '--sort-method=natural']),
        ('node header sorts among siblings', [*node, '--node-type=header', f'--name={PREFIX}a-first', f'--parent-node={h1}']),
        ('node header with a bad sort method', [*node, '--node-type=header', f'--name={PREFIX}hx', '--sort-method=random']),
        ('node header ignores other ids', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--graph-id={a}', f'--host-id={a}', f'--site-id={a}', '--host-group-style=2']),
        ('node header without a name', [*node, '--node-type=header']),
        ('node header with the name 0', [*node, '--node-type=header', '--name=0']),
        ('node header twice with one name', [*node, '--node-type=header', f'--name={PREFIX}h1']),
        ('node header under a graph node', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={graph_node}']),
        ('node header under a device node', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={host_node}']),
        ('node header under a site node', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={site_node}']),
        ('node header under an unknown node', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={a + 800}']),
        ('node header under a bad parent', [*node, '--node-type=header', f'--name={PREFIX}hx', '--parent-node=abc']),
        ('node header under a negative parent', [*node, '--node-type=header', f'--name={PREFIX}hx', '--parent-node=-1']),
        ('node header under a decimal parent', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node={h1}.0']),
        ('node header under a bare parent', [*node, '--node-type=header', f'--name={PREFIX}hx', '--parent-node']),
        ('node header under parent 0', [*node, '--node-type=header', f'--name={PREFIX}hx', '--parent-node=0']),
        ('node header under a zero-padded parent', [*node, '--node-type=header', f'--name={PREFIX}hx', f'--parent-node=00{h2}']),
        ('node graph under a header', [*node, '--node-type=graph', f'--graph-id={a + 1}', f'--parent-node={h2}']),
        ('node graph at the root', [*node, '--node-type=graph', f'--graph-id={a + 1}']),
        ('node graph already there', [*node, '--node-type=graph', f'--graph-id={a}', f'--parent-node={h1}']),
        ('node graph with a name it ignores', [*node, '--node-type=graph', f'--graph-id={a + 1}', f'--parent-node={h2}', '--name=ignored']),
        ('node graph unknown', [*node, '--node-type=graph', f'--graph-id={a + 800}']),
        ('node graph bad id', [*node, '--node-type=graph', '--graph-id=abc']),
        ('node graph without an id', [*node, '--node-type=graph']),
        ('node graph with a bare id', [*node, '--node-type=graph', '--graph-id']),
        ('node graph with a float-written id', [*node, '--node-type=graph', f'--graph-id={a + 1}.0', f'--parent-node={h2}']),
        ('node device under a header', [*node, '--node-type=host', f'--host-id={b}', f'--parent-node={h2}']),
        ('node device with data query grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style=2', f'--parent-node={h2}']),
        ('node device with graph template grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style=1']),
        ('node device with a float-written grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style=2.0']),
        ('node device with a bad grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style=3']),
        ('node device with a text grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style=abc']),
        ('node device with a bare grouping', [*node, '--node-type=host', f'--host-id={b}', '--host-group-style']),
        ('node device already there', [*node, '--node-type=host', f'--host-id={a}', f'--parent-node={h1}']),
        ('node device unknown', [*node, '--node-type=host', f'--host-id={a + 800}']),
        ('node device zero-padded id', [*node, '--node-type=host', f'--host-id=0{b}']),
        ('node device float-written id', [*node, '--node-type=host', f'--host-id={b}.0']),
        ('node device bad id', [*node, '--node-type=host', '--host-id=abc']),
        ('node device without an id', [*node, '--node-type=host']),
        ('node device ignores other ids', [*node, '--node-type=host', f'--host-id={b}', f'--graph-id={a}', f'--site-id={a}', '--name=ignored']),
        ('node site under a header', [*node, '--node-type=site', f'--site-id={b}', f'--parent-node={h2}']),
        ('node site at the root', [*node, '--node-type=site', f'--site-id={a}']),
        ('node site already there', [*node, '--node-type=site', f'--site-id={a}', f'--parent-node={h2}']),
        ('node site unknown', [*node, '--node-type=site', f'--site-id={a + 800}']),
        ('node site zero-padded id', [*node, '--node-type=site', f'--site-id=0{b}']),
        ('node site bad id', [*node, '--node-type=site', '--site-id=abc']),
        ('node site without an id', [*node, '--node-type=site']),
        ('node site with a grouping it keeps', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style=2']),
        ('node site with a text grouping', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style=abc']),
        ('node site with a zero grouping', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style=0']),
        ('node site with a float-written grouping', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style=2.0']),
        ('node site with an oversized grouping', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style=300']),
        ('node site with a bare grouping', [*node, '--node-type=site', f'--site-id={b}', '--host-group-style']),
        ('node without a node type', ['--type=node', f'--tree-id={t}']),
        ('node with a bad node type', [*node, '--node-type=leaf']),
        ('node with a padded node type', [*node, '--node-type= header ', f'--name={PREFIX}hx']),
        ('node without a tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx']),
        ('node with tree 0', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', '--tree-id=0']),
        ('node with a bad tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', '--tree-id=abc']),
        ('node with a negative tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', '--tree-id=-1']),
        ('node with an unknown tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', f'--tree-id={t + 5}']),
        ('node with a zero-padded tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', f'--tree-id=00{t}']),
        ('node with the default tree', ['--type=node', '--node-type=header', f'--name={PREFIX}hx', '--tree-id=1']),
        ('node bad tree before bad parent', ['--type=node', '--node-type=header', '--tree-id=abc', '--parent-node=abc']),
        ('node bad node type before bad tree', ['--type=node', '--node-type=leaf', '--tree-id=abc']),
        ('no type', [new]),
        ('unknown type', ['--type=forest']),
        ('padded type', ['--type= tree ', new]),
        ('bare type', ['--type']),
        ('list sites', ['--list-sites']),
        ('list sites quietly', ['--list-sites', '--quiet']),
        ('list devices', ['--list-hosts']),
        ('list devices quietly', ['--quiet', '--list-hosts']),
        ('list trees', ['--list-trees']),
        ('list trees quietly', ['--list-trees', '--quiet']),
        ('list nodes', ['--list-nodes', f'--tree-id={t}']),
        ('list nodes quietly', ['--list-nodes', f'--tree-id={t}', '--quiet']),
        ('list nodes under a header', ['--list-nodes', f'--tree-id={t}', f'--parent-node={h1}']),
        ('list nodes under a header quietly', ['--list-nodes', f'--tree-id={t}', f'--parent-node={h1}', '--quiet']),
        ('list header nodes', ['--list-nodes', f'--tree-id={t}', '--node-type=header']),
        ('list graph nodes', ['--list-nodes', f'--tree-id={t}', '--node-type=graph']),
        ('list device nodes', ['--list-nodes', f'--tree-id={t}', '--node-type=host']),
        ('list site nodes', ['--list-nodes', f'--tree-id={t}', '--node-type=site']),
        ('list nodes of an unknown type', ['--list-nodes', f'--tree-id={t}', '--node-type=leaf']),
        ('list nodes of a graph node', ['--list-nodes', f'--tree-id={t}', f'--parent-node={graph_node}']),
        ('list nodes of the default tree', ['--list-nodes', '--tree-id=1']),
        ('list nodes of an unknown tree', ['--list-nodes', f'--tree-id={t + 5}']),
        ('list nodes without a tree', ['--list-nodes']),
        ('list nodes of a bad tree', ['--list-nodes', '--tree-id=abc']),
        ('list nodes under a bad parent', ['--list-nodes', f'--tree-id={t}', '--parent-node=abc']),
        ('list nodes under a bare parent', ['--list-nodes', f'--tree-id={t}', '--parent-node']),
        ('list nodes under a zero-written parent', ['--list-nodes', f'--tree-id={t}', '--parent-node=0.0']),
        ('list nodes with a bare tree', ['--list-nodes', '--tree-id']),
        ('list graphs', ['--list-graphs', f'--host-id={a}']),
        ('list graphs quietly', ['--list-graphs', f'--host-id={a}', '--quiet']),
        ('list graphs of a device without any', ['--list-graphs', f'--host-id={a + 800}']),
        ('list graphs without a device', ['--list-graphs']),
        ('list graphs of device 0', ['--list-graphs', '--host-id=0']),
        ('list graphs of a decimal zero device', ['--list-graphs', '--host-id=0.0']),
        ('list graphs of a bare device', ['--list-graphs', '--host-id']),
        ('list graphs of a bad device', ['--list-graphs', '--host-id=abc']),
        ('list sites beat devices', ['--list-hosts', '--list-sites']),
        ('list devices beat trees', ['--list-trees', '--list-hosts']),
        ('list trees beat nodes', ['--list-nodes', '--list-trees']),
        ('list nodes beat graphs', ['--list-graphs', '--list-nodes', f'--tree-id={t}']),
        ('list ignores the type', ['--type=tree', '--list-trees']),
        ('list takes a value it ignores', ['--list-trees=yes']),
        ('list stops at help', ['--list-trees', '--help']),
        ('list after a bad argument', ['--bogus', '--list-trees']),
        ('help', ['-h']),
        ('upper help', ['-H']),
        ('long help', ['--help']),
        ('version', ['-v']),
        ('upper version', ['-V']),
        ('long version', ['--version']),
        ('version takes a value it ignores', ['--version=3']),
        ('help after valid options', ['--type=tree', new, '--help']),
        ('unknown argument', ['--bogus']),
        ('unknown argument with a value', ['--bogus=1', '--type=tree']),
        ('unknown argument after a valid one', ['--type=tree', '--bogus']),
        ('a leading = is no separator', ['=1']),
        ('the debug flag is unknown here', ['-d', '--list-trees']),
        ('no arguments', []),
    ]


def verify_tree_cases(harness, check):
    for label, arguments in tree_cases():
        tree_seed(harness)
        original = run(harness, QUIET_PHP + TREE_ORIGINAL, arguments)
        original_rows = tree_rows(harness)
        tree_seed(harness)
        native = run(harness, QUIET_PHP + TREE_SHIM, arguments)
        native_rows = tree_rows(harness)
        # Only an argument error loses the version line above the help; the rest must match byte for byte.
        expected = without_utility(normalise(original['stdout']), TREE_UTILITY) if native['stdout'].startswith('ERROR: Invalid Argument') else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same trees and nodes')
    tree_reset(harness)
    remove_reset(harness)


def verify_tree_differences(harness, check):
    a, t = REMOVE_FIRST, TREE_FIRST
    tree_seed(harness)
    seeded = tree_rows(harness)
    # The original concatenated --graph-id into SQL, so a clause after the number widened the lookup.
    injected = run(harness, QUIET_PHP + TREE_ORIGINAL, ['--type=node', f'--tree-id={t}', '--node-type=graph', f'--graph-id={a} OR 1=1'])
    check(injected['exit'] != 0 or 'Added Node' in injected['stdout'], 'the original read the graph id as SQL')
    tree_seed(harness)
    refused = run(harness, TREE_SHIM, ['--type=node', f'--tree-id={t}', '--node-type=graph', f'--graph-id={a + 1} OR 1=1'])
    check(refused['exit'] == 1 and 'No such graph-id' in refused['stdout'], 'a graph id with SQL after the number is refused')
    check(tree_rows(harness) == seeded, 'a refused graph id writes nothing')
    # Tree management is realm 4 (tree.php); graphs and devices alone are not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 4')
    try:
        for arguments in (['--type=tree', f'--name={PREFIX}denied'], ['--type=node', f'--tree-id={t}', '--node-type=header', f'--name={PREFIX}denied'],
                          ['--list-sites'], ['--list-hosts'], ['--list-trees'], ['--list-nodes', f'--tree-id={t}'], ['--list-graphs', f'--host-id={a}']):
            denied = run(harness, TREE_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without tree management is refused")
        check(tree_rows(harness) == seeded, 'a refused run writes nothing')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 4)')
    report = run(harness, 'bin/console', ['kadupul:tree:add', '--type=tree', f'--name={PREFIX}json', '--json'])
    check(report['exit'] == 0 and '"status":"created"' in report['stdout'] and f'"tree_id":{t + 10}' in report['stdout'], 'bin/console creates a tree and prints json')
    node = run(harness, 'bin/console', ['kadupul:tree:add', '--type=node', f'--tree-id={t}', '--node-type=host', f'--host-id={a + 1}', '--json'])
    check(node['exit'] == 0 and '"status":"created"' in node['stdout'], 'bin/console adds a device node and prints json')
    duplicate = run(harness, 'bin/console', ['kadupul:tree:add', '--type=node', f'--tree-id={t}', '--node-type=host', f'--host-id={a + 1}', '--json'])
    check(duplicate['exit'] == 1 and '"status":"failed"' in duplicate['stdout'], 'bin/console reports a device that is already in the branch as a failure')
    invalid = run(harness, 'bin/console', ['kadupul:tree:add', '--type=forest'])
    check(invalid['exit'] == 2, 'bin/console treats an unknown type as invalid input')
    tree_reset(harness)
    remove_reset(harness)


def add_graphs_seed(harness):
    """The removal seed, two devices to add graphs to, and the interface data query cached on the first with three interfaces."""
    remove_seed(harness)
    add_graphs_reset(harness)
    a, b = ADD_GRAPHS_DEVICE, ADD_GRAPHS_DEVICE + 1
    harness.sql(
        f"INSERT INTO host (id, description, hostname, host_template_id, snmp_version) VALUES ({a}, '{PREFIX}gcore', '{PREFIX}gcore.example', 0, 0), ({b}, '{PREFIX}gedge', '{PREFIX}gedge.example', 0, 0); "
        f'INSERT INTO host_snmp_query (host_id, snmp_query_id, reindex_method) VALUES ({a}, 1, 1)')
    cache = []
    for index, (name, description, status) in enumerate((('eth0', 'Ethernet 0', 'Up'), ('eth1', 'Ethernet 1', 'Down'), ('lo', 'Loopback', 'Up')), start=1):
        for field, value in (('ifIndex', str(index)), ('ifName', name), ('ifDescr', description), ('ifOperStatus', status), ('ifType', '6'), ('ifHighSpeed', '1000'), ('ifAlias', ''), ('ifHwAddress', f'00:00:00:00:00:0{index}')):
            cache.append(f"({a}, 1, '{field}', '{value}', '{index}', '.1.{index}')")
    harness.sql('INSERT INTO host_snmp_cache (host_id, snmp_query_id, field_name, field_value, snmp_index, oid) VALUES ' + ', '.join(cache))
    harness.sql(' '.join(f'ALTER TABLE {table} AUTO_INCREMENT = {ADD_GRAPHS_NEXT};' for table in (
        'graph_local', 'graph_templates_graph', 'graph_templates_item', 'data_local', 'data_template_data', 'data_template_rrd')))


def add_graphs_reset(harness):
    first = REMOVE_FIRST
    harness.sql(
        f'DELETE FROM data_input_data WHERE data_template_data_id IN (SELECT id FROM data_template_data WHERE local_data_id >= {first}); '
        f'DELETE FROM graph_templates_item WHERE local_graph_id >= {first}; DELETE FROM graph_templates_graph WHERE local_graph_id >= {first}; '
        f'DELETE FROM graph_local WHERE id >= {first}; DELETE FROM data_template_rrd WHERE local_data_id >= {first}; '
        f'DELETE FROM data_template_data WHERE local_data_id >= {first}; DELETE FROM data_local WHERE id >= {first}; '
        f'DELETE FROM poller_item WHERE host_id >= {first}; DELETE FROM host_snmp_cache WHERE host_id >= {first}; '
        f'DELETE FROM host_snmp_query WHERE host_id >= {first}; DELETE FROM host_graph WHERE host_id >= {first}')


def add_graphs_rows(harness):
    """The graphs, titles, data sources and associations the cases may leave behind."""
    first = REMOVE_FIRST
    return harness.sql(
        f"SELECT (SELECT GROUP_CONCAT(CONCAT_WS(',', id, graph_template_id, host_id, snmp_query_id, snmp_index) ORDER BY id SEPARATOR ';') FROM graph_local WHERE id >= {ADD_GRAPHS_NEXT}), "
        f"(SELECT GROUP_CONCAT(CONCAT_WS(',', local_graph_id, title, title_cache) ORDER BY local_graph_id SEPARATOR ';') FROM graph_templates_graph WHERE local_graph_id >= {first}), "
        f"(SELECT GROUP_CONCAT(CONCAT_WS(',', id, host_id, data_template_id, snmp_query_id, snmp_index) ORDER BY id SEPARATOR ';') FROM data_local WHERE id >= {ADD_GRAPHS_NEXT}), "
        f"(SELECT GROUP_CONCAT(CONCAT_WS(',', host_id, graph_template_id) ORDER BY host_id, graph_template_id SEPARATOR ';') FROM host_graph WHERE host_id >= {first}), "
        f"(SELECT GROUP_CONCAT(CONCAT_WS(',', host_id, snmp_query_id, reindex_method) ORDER BY host_id, snmp_query_id SEPARATOR ';') FROM host_snmp_query WHERE host_id >= {first}), "
        f"(SELECT COUNT(*) FROM poller_item WHERE host_id >= {first})")


def add_graphs_cases():
    a, b = ADD_GRAPHS_DEVICE, ADD_GRAPHS_DEVICE + 1
    host, unattached = f'--host-id={a}', f'--host-id={b}'
    cg = ['--graph-type=cg', '--graph-template-id=3']
    query = ['--snmp-query-id=1', '--snmp-query-type-id=1']
    ds = [host, '--graph-type=ds', '--graph-template-id=13', *query]
    return [
        # lists
        ('graphs list templates', ['--list-graph-templates']),
        ('graphs list templates quietly', ['--list-graph-templates', '--quiet']),
        ('graphs list templates of a device template', ['--list-graph-templates', f'--host-template-id={REMOVE_FIRST}']),
        ('graphs list templates of another device template', ['--list-graph-templates', f'--host-template-id={REMOVE_FIRST + 1}']),
        ('graphs list templates of a device template written as a float', ['--list-graph-templates', f'--host-template-id={REMOVE_FIRST}.0']),
        ('graphs list templates of an unknown device template', ['--list-graph-templates', '--host-template-id=999999']),
        ('graphs list templates of a device template that is not a number', ['--list-graph-templates', '--host-template-id=abc']),
        ('graphs list templates of device template zero', ['--list-graph-templates', '--host-template-id=0']),
        ('graphs list templates of a bare device template', ['--list-graph-templates', '--host-template-id']),
        ('graphs list input fields', ['--list-input-fields', '--graph-template-id=23']),
        ('graphs list input fields quietly', ['--list-input-fields', '--graph-template-id=23', '--quiet']),
        ('graphs list input fields of a template without any', ['--list-input-fields', '--graph-template-id=3']),
        ('graphs list input fields of an unknown template', ['--list-input-fields', '--graph-template-id=999999']),
        ('graphs list input fields without a template', ['--list-input-fields']),
        ('graphs list input fields of template zero', ['--list-input-fields', '--graph-template-id=0']),
        ('graphs list input fields of a template that is not a number', ['--list-input-fields', '--graph-template-id=abc']),
        ('graphs list devices', ['--list-hosts']),
        ('graphs list devices quietly', ['--list-hosts', '--quiet']),
        ('graphs list queries', ['--list-snmp-queries']),
        ('graphs list queries quietly', ['--list-snmp-queries', '--quiet']),
        ('graphs list query types', ['--list-query-types', '--snmp-query-id=1']),
        ('graphs list query types quietly', ['--list-query-types', '--snmp-query-id=1', '--quiet']),
        ('graphs list query types of an unknown query', ['--list-query-types', '--snmp-query-id=999999']),
        ('graphs list query types of a query written as a float', ['--list-query-types', '--snmp-query-id=1.0']),
        ('graphs list query types without a query', ['--list-query-types']),
        ('graphs list fields', ['--list-snmp-fields', host]),
        ('graphs list fields of a query', ['--list-snmp-fields', host, '--snmp-query-id=1']),
        ('graphs list fields of a device without a cache', ['--list-snmp-fields', unattached]),
        ('graphs list fields quietly', ['--list-snmp-fields', host, '--quiet']),
        ('graphs list fields of an unknown device', ['--list-snmp-fields', '--host-id=999999']),
        ('graphs list fields without a device', ['--list-snmp-fields']),
        ('graphs list fields does not associate a query', ['--list-snmp-fields', unattached, '--snmp-query-id=1']),
        ('graphs list values', ['--list-snmp-values', host, '--snmp-field=ifName']),
        ('graphs list values quietly', ['--list-snmp-values', host, '--snmp-field=ifName', '--quiet']),
        ('graphs list values of two fields', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-field=ifOperStatus']),
        ('graphs list values of a query', ['--list-snmp-values', host, '--snmp-query-id=1', '--snmp-field=ifOperStatus']),
        ('graphs list values of an unknown field', ['--list-snmp-values', host, '--snmp-field=nothing']),
        ('graphs list values of an empty field', ['--list-snmp-values', host, '--snmp-field=']),
        ('graphs list values without a field', ['--list-snmp-values', host]),
        ('graphs list values with a value that matches', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-value=eth0']),
        ('graphs list values with a value that does not', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-value=nothing']),
        ('graphs list values with an expression that matches', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-value-regex=^eth']),
        ('graphs list values with an expression that does not', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-value-regex=^zz']),
        ('graphs list values with a value and an expression', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-value=eth0', '--snmp-value-regex=^eth']),
        ('graphs list values with one value for two fields', ['--list-snmp-values', host, '--snmp-field=ifName', '--snmp-field=ifOperStatus', '--snmp-value=eth0']),
        ('graphs list values of a device without a cache', ['--list-snmp-values', unattached, '--snmp-field=ifName']),
        ('graphs lists run in the script\'s order', ['--list-hosts', '--list-graph-templates']),
        ('graphs lists run in the script\'s order, queries last', ['--list-snmp-queries', '--list-hosts']),
        ('graphs list needs no device', ['--list-snmp-queries', '--host-id=abc']),
        # errors before anything is made
        ('graphs create without a device', ['--graph-type=cg', '--graph-template-id=3']),
        ('graphs create for an unknown device', ['--host-id=999999', *cg]),
        ('graphs create for device zero', ['--host-id=0', *cg]),
        ('graphs create for a device written as a float', [f'--host-id={a}.0', *cg]),
        ('graphs create for a padded device', [f'--host-id= {a}', *cg]),
        ('graphs create without a template', [host, '--graph-type=cg']),
        ('graphs create for an unknown template', [host, '--graph-type=cg', '--graph-template-id=999999']),
        ('graphs create for template zero', [host, '--graph-type=cg', '--graph-template-id=0']),
        ('graphs create for a template that is not a number', [host, '--graph-type=cg', '--graph-template-id=abc']),
        ('graphs create without a type', [host, '--graph-template-id=3']),
        ('graphs create of another type', [host, '--graph-type=dq', '--graph-template-id=3', '--snmp-field=ifName', '--snmp-value=eth0']),
        ('graphs create of a bare type', [host, '--graph-type', '--graph-template-id=3']),
        ('graphs create for an unknown query', [host, '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=999999']),
        ('graphs create for an unknown query type', [host, '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=1', '--snmp-query-type-id=999999']),
        ('graphs create a data query graph without options', [host, '--graph-type=ds', '--graph-template-id=13']),
        ('graphs create a data query graph without a field', [host, '--graph-type=ds', '--graph-template-id=13', *query]),
        ('graphs create a data query graph without a query type', [host, '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=1', '--snmp-field=ifName', '--snmp-value=eth0']),
        ('graphs create a data query graph that matches nothing', [*ds, '--snmp-field=ifName', '--snmp-value=nothing']),
        ('graphs create a data query graph with a field it lacks', [*ds, '--snmp-field=ifName']),
        ('graphs create a data query graph that matches nothing by expression', [*ds, '--snmp-field=ifName', '--snmp-value-regex=^zz']),
        ('graphs create with a bad reindex method', [*ds, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method=sideways']),
        ('graphs create with a reindex method out of range', [*ds, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method=9']),
        ('graphs create with a bare reindex method', [*ds, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method']),
        ('graphs create with an unknown input field', [host, *cg, '--input-fields=nothing=1']),
        # option handling
        ('graphs one value for a single option', [host, host, *cg]),
        ('graphs one flag twice', [host, *cg, '--force', '--force']),
        ('graphs one title twice', [host, *cg, '--graph-title=a', '--graph-title=b']),
        ('graphs help', ['-h']),
        ('graphs upper help', ['-H']),
        ('graphs long help', ['--help']),
        ('graphs version', ['-v']),
        ('graphs upper version', ['-V']),
        ('graphs long version', ['--version']),
        ('graphs no arguments', []),
        # creation
        ('graphs create from a template', [host, *cg]),
        ('graphs create from a template with a title', [unattached, *cg, '--graph-title=Titled']),
        ('graphs create from a template with a title written with a quote', [unattached, '--graph-type=cg', '--graph-template-id=3', "--graph-title=It's", '--force']),
        ('graphs create from a template on the other device quietly', [unattached, *cg, '--quiet', '--force']),
        ('graphs create with input fields', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=index_type=ifIndex index_value=ifName output_type=1']),
        ('graphs create with a compound input field', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=24:index_type=ifIndex']),
        ('graphs create with an input field written in part', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=index_t=ifIndex']),
        ('graphs create with an input field without a value', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=index_type']),
        ('graphs create with an input field with an equals sign in the value', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=index_type=a=b']),
        ('graphs create with input fields on a template without any', [host, *cg, '--input-fields=index_type=1']),
        ('graphs create with input fields that name nothing', [host, '--graph-type=cg', '--graph-template-id=23', '--input-fields=nothing=1']),
        ('graphs create with an empty input fields option', [host, *cg, '--input-fields=']),
        ('graphs create a data query graph', [*ds, '--snmp-field=ifName', '--snmp-value=eth0']),
        ('graphs create a data query graph by expression', [*ds, '--snmp-field=ifName', '--snmp-value-regex=^eth']),
        ('graphs create a data query graph by two fields', [*ds, '--snmp-field=ifName', '--snmp-field=ifOperStatus', '--snmp-value=lo', '--snmp-value=Up']),
        ('graphs create a data query graph by a value and an expression', [*ds, '--snmp-field=ifName', '--snmp-field=ifOperStatus', '--snmp-value=lo', '--snmp-value-regex=Up']),
        ('graphs create a data query graph with a title', [*ds, '--snmp-field=ifName', '--snmp-value=lo', '--graph-title=Loopback']),
        ('graphs create a data query graph for every interface', [*ds, '--snmp-field=ifOperStatus', '--snmp-value=Up']),
        ('graphs create a data query graph quietly', [*ds, '--snmp-field=ifName', '--snmp-value=eth1', '--quiet']),
        ('graphs create a data query graph with a numeric value that matches loosely', [*ds, '--snmp-field=ifIndex', '--snmp-value=1.0']),
        ('graphs create a data query graph on a device without the query', [unattached, '--graph-type=ds', '--graph-template-id=13', *query, '--snmp-field=ifName', '--snmp-value=eth0']),
        ('graphs create a data query graph on a device without the query, reindexed by fields', [unattached, '--graph-type=ds', '--graph-template-id=13', *query, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method=fields']),
        ('graphs create a data query graph on a device without the query, reindexed by number', [unattached, '--graph-type=ds', '--graph-template-id=13', *query, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method=2']),
        ('graphs create a data query graph on a device without the query, reindexed by none', [unattached, '--graph-type=ds', '--graph-template-id=13', *query, '--snmp-field=ifName', '--snmp-value=eth0', '--reindex-method=NONE']),
    ]


def add_graphs_sequences():
    """Commands that depend on those before them, run one after another from one seed."""
    a = ADD_GRAPHS_DEVICE
    host = f'--host-id={a}'
    ds = [host, '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=1', '--snmp-query-type-id=1']
    cg = [host, '--graph-type=cg', '--graph-template-id=3']
    return [
        ('graphs a template graph, again, then by force', [cg, cg, [*cg, '--force']]),
        ('graphs a template graph with a title, again with another', [[*cg, '--graph-title=First'], [*cg, '--graph-title=Second'], [*cg, '--graph-title=Third', '--force']]),
        ('graphs a data query graph, again, then retitled', [[*ds, '--snmp-field=ifName', '--snmp-value=eth0'], [*ds, '--snmp-field=ifName', '--snmp-value=eth0'],
                                                         [*ds, '--snmp-field=ifName', '--snmp-value=eth0', '--graph-title=Retitled']]),
        ('graphs data query graphs for some interfaces, then all', [[*ds, '--snmp-field=ifName', '--snmp-value=lo'], [*ds, '--snmp-field=ifOperStatus', '--snmp-value=Up'],
                                                                [*ds, '--snmp-field=ifType', '--snmp-value=6']]),
        ('graphs a data query graph on a device without the query, then again', [[f'--host-id={a + 1}', '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=1', '--snmp-query-type-id=1', '--snmp-field=ifName', '--snmp-value=eth0']] * 2),
    ]


def run_pair(harness, script, original_script, arguments):
    return run(harness, QUIET_PHP + original_script, arguments), run(harness, QUIET_PHP + script, arguments)


def same_add_graphs(check, label, original, native):
    # Only an argument error loses the version line above the help; the rest must match byte for byte.
    expected = without_utility(normalise(original['stdout']), ADD_GRAPHS_UTILITY) if native['stdout'].startswith('ERROR: Invalid Argument') else normalise(original['stdout'])
    check(original['exit'] == native['exit'], f'{label} keeps its exit code')
    check(expected == normalise(native['stdout']), f'{label} prints the same lines')
    check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label} prints the same errors')


def verify_add_graphs_cases(harness, check):
    for label, arguments in add_graphs_cases():
        add_graphs_seed(harness)
        original = run(harness, QUIET_PHP + ADD_GRAPHS_ORIGINAL, arguments)
        original_rows = add_graphs_rows(harness)
        add_graphs_seed(harness)
        native = run(harness, QUIET_PHP + ADD_GRAPHS_SHIM, arguments)
        native_rows = add_graphs_rows(harness)
        same_add_graphs(check, label, original, native)
        check(original_rows == native_rows, f'{label} leaves the same graphs, data sources and associations')
    for label, steps in add_graphs_sequences():
        outcomes = []
        for script in (ADD_GRAPHS_ORIGINAL, ADD_GRAPHS_SHIM):
            add_graphs_seed(harness)
            outcomes.append([(run(harness, QUIET_PHP + script, arguments), add_graphs_rows(harness)) for arguments in steps])
        for number, ((original, original_rows), (native, native_rows)) in enumerate(zip(*outcomes), start=1):
            same_add_graphs(check, f'{label}, step {number}', original, native)
            check(original_rows == native_rows, f'{label}, step {number} leaves the same graphs, data sources and associations')
    add_graphs_reset(harness)
    remove_reset(harness)


def verify_add_graphs_differences(harness, check):
    a = ADD_GRAPHS_DEVICE
    cg = [f'--host-id={a}', '--graph-type=cg', '--graph-template-id=3']
    add_graphs_seed(harness)
    seeded = add_graphs_rows(harness)
    # getopt() skipped an option it did not know and the script went on to the next check.
    original = run(harness, QUIET_PHP + ADD_GRAPHS_ORIGINAL, ['--bogus'])
    check(original['exit'] == 1 and original['stdout'].startswith('ERROR: Unknown Host ID (0)'), 'the original ignored an unknown option')
    refused = run(harness, ADD_GRAPHS_SHIM, ['--bogus'])
    check(refused['exit'] == 1 and refused['stdout'].startswith('ERROR: Invalid Argument: (bogus)\n') and 'usage: add_graphs.php' in refused['stdout'], 'an unknown option prints the error and the help')
    # The original associated and ran a data query before it looked for the device, so an unknown device got a row.
    unknown = ['--host-id=999999', '--graph-type=ds', '--graph-template-id=13', '--snmp-query-id=1', '--snmp-query-type-id=1', '--snmp-field=ifName', '--snmp-value=eth0']
    original = run(harness, QUIET_PHP + ADD_GRAPHS_ORIGINAL, unknown)
    check(original['exit'] == 1 and harness.sql('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = 999999').strip() == '1', 'the original associated a data query with an unknown device')
    harness.sql('DELETE FROM host_snmp_query WHERE host_id = 999999')
    refused = run(harness, ADD_GRAPHS_SHIM, unknown)
    check(refused['exit'] == 1 and refused['stdout'].startswith('ERROR: Unknown Host ID (999999)'), 'an unknown device is refused')
    check(harness.sql('SELECT COUNT(*) FROM host_snmp_query WHERE host_id = 999999').strip() == '0' and add_graphs_rows(harness) == seeded, 'an unknown device is refused before anything is written')
    # Graph management is realm 5 (graphs_new.php); device and tree management alone are not enough.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 5')
    try:
        for arguments in (cg, ['--list-hosts'], ['--list-graph-templates'], ['--list-snmp-queries'], ['--list-snmp-fields', f'--host-id={a}']):
            denied = run(harness, ADD_GRAPHS_SHIM, arguments)
            check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', f"{' '.join(arguments)} without graph management is refused")
        check(add_graphs_rows(harness) == seeded, 'a refused run writes nothing')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 5)')
    report = run(harness, 'bin/console', ['kadupul:graph:add', *cg, '--json'])
    check(report['exit'] == 0 and '"status":"ok"' in report['stdout'] and '"result":"created"' in report['stdout'], 'bin/console creates a graph and prints json')
    listing = run(harness, 'bin/console', ['kadupul:graph:add', '--list-graph-templates', '--json'])
    check(listing['exit'] == 0 and '"graph_templates"' in listing['stdout'], 'bin/console lists the graph templates as json')
    invalid = run(harness, 'bin/console', ['kadupul:graph:add', *cg[:1], '--graph-type=bogus', '--graph-template-id=3'])
    check(invalid['exit'] == 2, 'bin/console treats an unknown graph type as invalid input')
    add_graphs_reset(harness)
    remove_reset(harness)


POE_ORIGINAL = 'tests/Fixtures/legacy-cli/poller_output_empty.php'
POE_SHIM = 'cli/poller_output_empty.php'
POE_UTILITY = 'Kadupul Process Poller Output Utility'
# Data sources of the poller_output_empty.php cases: each has a poller item, an RRD file and, when a case asks, queued samples.
POE_FIRST = 9400001
POE_START = 1700000000
# rrdtool prints its own timings after each acknowledged update.
PHP_WARNING = re.compile(r'^\nWarning: .*\n', re.MULTILINE)
RRD_TIMES = re.compile(r'u:\d+\.\d+ s:\d+\.\d+ r:\d+\.\d+')
POE_STEP = 300


def poe_path(number):
    return f'{ROOT}/rra/kadupul_parity_poe_{number}.rrd'


def poe_rrdtool(harness):
    """The rrdtool the installation uses; the cases that need a working one point path_rrdtool at it."""
    return harness.command('sh', '-c', 'command -v rrdtool', check=True)['stdout'].strip()


def poe_reset(harness):
    harness.sql(f'DELETE FROM poller_output WHERE local_data_id >= {POE_FIRST}; DELETE FROM poller_output_rejected WHERE local_data_id >= {POE_FIRST}; '
                f'DELETE FROM poller_item WHERE local_data_id >= {POE_FIRST}; DELETE FROM data_local WHERE id >= {POE_FIRST}')
    for number in (1, 2, 3):
        harness.command('rm', '-f', poe_path(number), check=True)


def poe_seed(harness, sources=1, queued=(1,), orphan=False):
    """`sources` data sources with an RRD file each; `queued` is the sample counts queued for each. An orphan sample has no poller item."""
    poe_reset(harness)
    rrdtool = poe_rrdtool(harness)
    harness.sql(f"REPLACE INTO settings (name, value) VALUES ('path_rrdtool', '{rrdtool}')")
    for number in range(1, sources + 1):
        local_data_id = POE_FIRST + number - 1
        path = poe_path(number)
        harness.command(rrdtool, 'create', path, '--step', str(POE_STEP), '--start', str(POE_START), 'DS:traffic_in:GAUGE:600:U:U', 'RRA:AVERAGE:0.5:1:100', check=True)
        harness.sql(f"INSERT INTO data_local (id, data_template_id, host_id) VALUES ({local_data_id}, 0, 0); "
                    f"INSERT INTO poller_item (local_data_id, host_id, action, rrd_name, rrd_path, rrd_num, rrd_step) VALUES ({local_data_id}, 0, 1, 'traffic_in', '{path}', 1, {POE_STEP})")
        for sample in range(1, (queued[number - 1] if number <= len(queued) else 0) + 1):
            harness.sql(f"INSERT INTO poller_output (local_data_id, rrd_name, time, output) VALUES ({local_data_id}, 'traffic_in', FROM_UNIXTIME({POE_START + sample * POE_STEP}), '{sample * 10}')")
    if orphan:
        harness.sql(f"INSERT INTO poller_output (local_data_id, rrd_name, time, output) VALUES ({POE_FIRST + 50}, 'traffic_in', FROM_UNIXTIME({POE_START + POE_STEP}), '7')")


def poe_rows(harness, sources=3):
    """What a run may change: the queue, the dead-letter table and every RRD file's last update."""
    rows = harness.sql(f"SELECT 'output', local_data_id, rrd_name, UNIX_TIMESTAMP(time), output FROM poller_output WHERE local_data_id >= {POE_FIRST} ORDER BY local_data_id, time, rrd_name; "
                       f"SELECT 'rejected', local_data_id, rrd_name, UNIX_TIMESTAMP(time), output, reason FROM poller_output_rejected WHERE local_data_id >= {POE_FIRST} ORDER BY local_data_id, time, rrd_name")
    last = [harness.command('rrdtool', 'lastupdate', poe_path(number))['stdout'] for number in range(1, sources + 1)]

    return rows, last


def poe_cases():
    # (label, seed arguments, argument sets run one after another)
    return [
        ('poe empty queue', {'queued': ()}, [[]]),
        ('poe one sample', {}, [[]]),
        ('poe samples for one data source', {'queued': (4,)}, [[]]),
        ('poe samples for three data sources', {'sources': 3, 'queued': (2, 3, 1)}, [[]]),
        ('poe a second pass finds nothing', {'queued': (2,)}, [[], []]),
        ('poe an orphan sample is never written', {'queued': ()}, [[]]),
        ('poe an orphan sample among others', {'sources': 2, 'queued': (2, 2)}, [[]]),
        ('poe version', {}, [['-v']]),
        ('poe upper version', {}, [['-V']]),
        ('poe long version', {}, [['--version']]),
        ('poe version takes a value it ignores', {}, [['--version=3']]),
        ('poe help', {}, [['-h']]),
        ('poe upper help', {}, [['-H']]),
        ('poe long help', {}, [['--help']]),
        ('poe help takes a value it ignores', {}, [['--help=yes']]),
        ('poe help stops before an unknown argument', {}, [['--help', '--bogus']]),
        ('poe an unknown argument stops before help', {}, [['--bogus', '--help']]),
        ('poe version stops before the run', {}, [['-v']]),
        ('poe unknown argument', {}, [['--bogus']]),
        ('poe unknown argument with a value', {}, [['--bogus=1']]),
        ('poe a leading = is no separator', {}, [['=1']]),
        ('poe an empty argument', {}, [['']]),
        ('poe the debug flag of other scripts', {}, [['-d']]),
        ('poe the quiet flag of other scripts', {}, [['--quiet']]),
        ('poe a positional argument', {}, [['1']]),
        ('poe a host id of other scripts', {}, [['--host-id=1']]),
    ]


def verify_poe_cases(harness, check):
    for label, seed, steps in poe_cases():
        outcomes = []
        for script in (POE_ORIGINAL, POE_SHIM):
            poe_seed(harness, orphan=label.startswith('poe an orphan'), **seed)
            outcomes.append([(run(harness, QUIET_PHP + script, arguments), poe_rows(harness)) for arguments in steps])
        for number, ((original, original_rows), (native, native_rows)) in enumerate(zip(*outcomes), start=1):
            # Only an argument error loses the version line above the help.
            expected = without_utility(normalise(original['stdout']), POE_UTILITY) if native['stdout'].startswith('ERROR: Invalid Parameter') else normalise(original['stdout'])
            check(original['exit'] == native['exit'], f'{label}, step {number} keeps its exit code')
            check(RRD_TIMES.sub('', expected) == RRD_TIMES.sub('', normalise(native['stdout'])), f'{label}, step {number} prints the same lines')
            check(PHP_LOG.sub('', STTY.sub('', normalise(original['stderr']))) == normalise(native['stderr']), f'{label}, step {number} prints the same errors')
            check(original_rows == native_rows, f'{label}, step {number} leaves the same queue, dead letters and RRD updates')
    poe_reset(harness)


def verify_poe_differences(harness, check):
    rrdtool = poe_rrdtool(harness)
    saved = harness.sql("SELECT value FROM settings WHERE name = 'path_rrdtool'")
    try:
        # Without an rrdtool path the original died with an uncaught ValueError (exit 255) and printed nothing useful; the worker reports it.
        harness.sql("REPLACE INTO settings (name, value) VALUES ('path_rrdtool', '')")
        poe_seed(harness)
        harness.sql("REPLACE INTO settings (name, value) VALUES ('path_rrdtool', '')")
        original = run(harness, QUIET_PHP + POE_ORIGINAL, [])
        original_rows = poe_rows(harness)
        poe_seed(harness)
        harness.sql("REPLACE INTO settings (name, value) VALUES ('path_rrdtool', '')")
        refused = run(harness, QUIET_PHP + POE_SHIM, [])
        check(original['exit'] == 255, 'the original died on an empty rrdtool path')
        check(refused['exit'] == 1 and refused['stdout'] == '' and refused['stderr'] == 'ERROR: Poller output could not be processed; unprocessed samples remain queued.\n', 'an empty rrdtool path is reported on stderr')
        check(poe_rows(harness) == original_rows, 'a refused pass leaves the queue and the RRD files as the original did')
        # An rrdtool that cannot start is the original's own refusal, on both sides.
        poe_seed(harness)
        harness.sql("REPLACE INTO settings (name, value) VALUES ('path_rrdtool', '/nonexistent/rrdtool')")
        original = run(harness, QUIET_PHP + POE_ORIGINAL, [])
        native = run(harness, QUIET_PHP + POE_SHIM, [])
        # The worker does not inherit this run's display_errors=0, so PHP's own warning about the missing binary reaches stdout, as it does for the original on a default ini.
        check(original['exit'] == native['exit'] == 1 and original['stderr'] == native['stderr'] and PHP_WARNING.sub('', native['stdout']) == original['stdout'], 'an rrdtool that cannot start fails the same way')
    finally:
        harness.sql("DELETE FROM settings WHERE name = 'path_rrdtool'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name, value) VALUES ('path_rrdtool', CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
    # The web has no page for this; data_sources.php, realm 3, is the nearest, and graph management alone is not enough.
    poe_seed(harness, queued=(2,), orphan=True)
    seeded = poe_rows(harness)
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 3')
    try:
        denied = run(harness, POE_SHIM, [])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'without device management the run is refused')
        check(poe_rows(harness) == seeded, 'a refused run writes nothing')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 3)')
    # A failed pass writes to stderr only, so a script that parses stdout sees nothing; the new refusal is audited like the others.
    failed = run(harness, POE_SHIM, [])
    check(failed['exit'] == 1 and failed['stderr'] == 'ERROR: Poller output made no progress; remaining samples retained for retry.\n' and 'There were' not in failed['stdout'], 'an orphan sample ends the pass on stderr')
    poe_seed(harness, queued=(2,))
    report = run(harness, 'bin/console', ['kadupul:poller-output:empty', '--json'])
    check(report['exit'] == 0 and report['stdout'] == '{"status":"ok","database":"local","updates":2}\n', 'bin/console empties the queue and prints json')
    poe_seed(harness, queued=(1,), orphan=True)
    report = run(harness, 'bin/console', ['kadupul:poller-output:empty', '--json'])
    check(report['exit'] == 1 and report['stdout'] == '{"status":"no_progress","database":"local","updates":null}\n', 'bin/console reports a pass without progress as json')
    poe_reset(harness)


def verify_graph_parity(harness, check):
    install_original(harness, POE_ORIGINAL)
    install_original(harness, TITLES_ORIGINAL)
    install_original(harness, SOURCES_ORIGINAL)
    install_original(harness, REORDER_ORIGINAL)
    install_original(harness, REPAIR_ORIGINAL)
    install_original(harness, BROKEN_ORIGINAL)
    install_original(harness, REMOVE_ORIGINAL)
    install_original(harness, ADD_TEMPLATE_ORIGINAL)
    install_original(harness, ADD_QUERY_ORIGINAL)
    install_original(harness, ADD_DS_ORIGINAL)
    install_original(harness, REBUILD_ORIGINAL)
    install_original(harness, TREE_ORIGINAL)
    install_original(harness, ADD_GRAPHS_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    rebuild_saved = None
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and Graphs (5).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},5),({admin},4),({admin},3),({admin},15)')
        verify_titles_cases(harness, check)
        verify_titles_differences(harness, check)
        verify_sources_cases(harness, check)
        verify_sources_differences(harness, check)
        verify_reorder_cases(harness, check)
        verify_reorder_differences(harness, check)
        verify_repair_cases(harness, check)
        verify_repair_differences(harness, check)
        verify_broken_cases(harness, check)
        verify_broken_differences(harness, check)
        verify_remove_cases(harness, check)
        verify_remove_differences(harness, check)
        verify_add_template_cases(harness, check)
        verify_add_template_differences(harness, check)
        verify_add_query_cases(harness, check)
        verify_add_query_differences(harness, check)
        verify_add_ds_cases(harness, check)
        verify_add_ds_differences(harness, check)
        rebuild_saved = rebuild_setup(harness)
        verify_rebuild_cases(harness, check)
        verify_rebuild_differences(harness, check)
        # The seed goes before the next tools run, so they never see its devices.
        rebuild_teardown(harness, rebuild_saved)
        rebuild_saved = None
        verify_tree_cases(harness, check)
        verify_tree_differences(harness, check)
        verify_add_graphs_cases(harness, check)
        verify_add_graphs_differences(harness, check)
        verify_poe_cases(harness, check)
        verify_poe_differences(harness, check)
    finally:
        poe_reset(harness)
        titles_reset(harness)
        sources_reset(harness)
        reorder_reset(harness)
        repair_reset(harness)
        broken_reset(harness)
        tree_reset(harness)
        add_ds_reset(harness)
        rebuild_teardown(harness, rebuild_saved)
        add_graphs_reset(harness)
        add_query_reset(harness)
        remove_reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
