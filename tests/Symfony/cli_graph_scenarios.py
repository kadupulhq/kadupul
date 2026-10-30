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


def verify_graph_parity(harness, check):
    install_original(harness, TITLES_ORIGINAL)
    install_original(harness, REPAIR_ORIGINAL)
    install_original(harness, BROKEN_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and Graphs (5).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},5)')
        verify_titles_cases(harness, check)
        verify_titles_differences(harness, check)
        verify_repair_cases(harness, check)
        verify_repair_differences(harness, check)
        verify_broken_cases(harness, check)
        verify_broken_differences(harness, check)
    finally:
        titles_reset(harness)
        repair_reset(harness)
        broken_reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
