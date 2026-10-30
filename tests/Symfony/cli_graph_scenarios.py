# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for the cli/ tools that manage graphs.

Each case runs the frozen original and the shim from the same starting rows and
compares stdout, stderr, the exit code and the rows each leaves behind. A case
that cannot match, because the original misbehaved, is checked on its own in
the differences.
"""
from cli_parity_scenarios import install_original, normalise, run

PREFIX = 'kadupul_parity_graph_'
TITLES_ORIGINAL = 'tests/Fixtures/legacy-cli/poller_graphs_reapply_names.php'
TITLES_SHIM = 'cli/poller_graphs_reapply_names.php'
TITLES_UTILITY = 'Kadupul Reapply graph Names Utility'
STALE = 'stale title'


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


def verify_graph_parity(harness, check):
    install_original(harness, TITLES_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and Graphs (5).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},5)')
        verify_titles_cases(harness, check)
        verify_titles_differences(harness, check)
    finally:
        titles_reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
