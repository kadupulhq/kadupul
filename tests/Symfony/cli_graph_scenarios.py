# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for the cli/ tools that manage graphs.

Each case runs the frozen original and the shim from the same starting rows and
compares stdout, stderr, the exit code and the rows each leaves behind. A case
that cannot match, because the original misbehaved, is checked on its own in
the differences.
"""
import re

from cli_parity_scenarios import install_original, normalise, run

PREFIX = 'kadupul_parity_graph_'
TITLES_ORIGINAL = 'tests/Fixtures/legacy-cli/poller_graphs_reapply_names.php'
TITLES_SHIM = 'cli/poller_graphs_reapply_names.php'
TITLES_UTILITY = 'Kadupul Reapply graph Names Utility'
STALE = 'stale title'
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
TREE_ORIGINAL = 'tests/Fixtures/legacy-cli/add_tree.php'
TREE_SHIM = 'cli/add_tree.php'
TREE_UTILITY = 'Kadupul Add Tree Utility'
# graph_tree.id is a smallint, so the seeded tree sits near the top of its range; the nodes reuse the removal ids.
TREE_FIRST = 60001
# Every id the removal cases print is fixed (below 16777215, the largest device template id), because the original and the shim each start from a fresh seed.
REMOVE_FIRST = 9000001
# BSD stty says "stdin isn't a terminal", GNU stty "'standard input': Inappropriate ioctl for device".
STTY = re.compile(r'^stty: .*\n', re.MULTILINE)
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


def without_version(text):
    """The original's help began with its version line even after an argument error; the shim reports the error before it can read one."""
    return ''.join(line for line in text.splitlines(keepends=True) if not line.startswith(TITLES_UTILITY))


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
        check(STTY.sub('', normalise(original['stderr'])) == normalise(native['stderr']), f'{label} prints the same errors')
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


def verify_graph_parity(harness, check):
    install_original(harness, TITLES_ORIGINAL)
    install_original(harness, REPAIR_ORIGINAL)
    install_original(harness, BROKEN_ORIGINAL)
    install_original(harness, REMOVE_ORIGINAL)
    install_original(harness, ADD_TEMPLATE_ORIGINAL)
    install_original(harness, TREE_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and Graphs (5).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},5),({admin},4),({admin},3)')
        verify_titles_cases(harness, check)
        verify_titles_differences(harness, check)
        verify_repair_cases(harness, check)
        verify_repair_differences(harness, check)
        verify_broken_cases(harness, check)
        verify_broken_differences(harness, check)
        verify_remove_cases(harness, check)
        verify_remove_differences(harness, check)
        verify_add_template_cases(harness, check)
        verify_add_template_differences(harness, check)
        verify_tree_cases(harness, check)
        verify_tree_differences(harness, check)
    finally:
        titles_reset(harness)
        repair_reset(harness)
        broken_reset(harness)
        tree_reset(harness)
        remove_reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
