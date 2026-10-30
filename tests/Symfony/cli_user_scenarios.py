# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Parity for the cli/ tools that manage users and their permissions.

Each case runs the frozen original and the shim from the same starting rows and
compares stdout, stderr, the exit code and the rows each leaves behind. A case
that cannot match, because the original misbehaved, is listed in
SHIM_ONLY with the reason.
"""
from cli_parity_scenarios import install_original, normalise, run

COPY_ORIGINAL = 'tests/Fixtures/legacy-cli/copy_user.php'
COPY_SHIM = 'cli/copy_user.php'
TEMPLATE = 'kadupul_parity_template'
COPY_TARGET = 'kadupul_parity_copy'
PREFIX = 'kadupul_parity_'
PERMS_ORIGINAL = 'tests/Fixtures/legacy-cli/add_perms.php'
PERMS_SHIM = 'cli/add_perms.php'
PERMS_UTILITY = 'Kadupul Add Permissions Utility'
PERMS_USER = PREFIX + 'grantee'
MISSING_ID = '999999'

# (label, arguments). Only argument sets the original accepts belong here.
COPY_CASES = [
    ('copy user', [TEMPLATE, COPY_TARGET]),
    ('copy user, a third argument is ignored', [TEMPLATE, COPY_TARGET, 'ignored']),
    ('copy over an existing account', [TEMPLATE, 'admin']),
    ('copy a missing template', [PREFIX + 'missing', COPY_TARGET]),
    ('copy with a name that needs quoting', [TEMPLATE, "kadupul_parity_o'brien"]),
    ('copy usage', []),
    ('copy zero as the first argument', ['0', COPY_TARGET]),
    ('copy help', ['-h']),
    ('copy long help', ['--help']),
    ('copy version', ['-V']),
    ('copy long version', ['--version']),
]


def seed(harness):
    reset(harness)
    harness.sql(
        f"INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('{TEMPLATE}', 'x', 0, 'Parity Template', 'on'); "
        f"SET @t = (SELECT id FROM user_auth WHERE username = '{TEMPLATE}' AND realm = 0); "
        'INSERT INTO user_auth_realm (user_id, realm_id) VALUES (@t, 8), (@t, 1), (@t, 7); '
        'INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (@t, 1, 1), (@t, 2, 2); '
        "INSERT INTO settings_user (user_id, name, value) VALUES (@t, 'default_view_mode', '2');")


def reset(harness):
    harness.sql(f"DELETE FROM user_auth WHERE username LIKE '{PREFIX}%'; "
                'DELETE FROM user_auth_realm WHERE user_id NOT IN (SELECT id FROM user_auth); '
                'DELETE FROM user_auth_perms WHERE user_id NOT IN (SELECT id FROM user_auth); '
                'DELETE FROM settings_user WHERE user_id NOT IN (SELECT id FROM user_auth) AND user_id > 0;')


def accounts(harness):
    """Every parity account's rows, with ids and password hashes left out: they differ per run."""
    return harness.sql(
        "SELECT a.username, a.full_name, a.enabled, a.realm, a.must_change_password, "
        "(SELECT GROUP_CONCAT(realm_id ORDER BY realm_id) FROM user_auth_realm WHERE user_id = a.id), "
        "(SELECT GROUP_CONCAT(CONCAT(item_id, ':', type) ORDER BY item_id, type) FROM user_auth_perms WHERE user_id = a.id), "
        "(SELECT GROUP_CONCAT(CONCAT(name, '=', value) ORDER BY name) FROM settings_user WHERE user_id = a.id) "
        f"FROM user_auth a WHERE a.username LIKE '{PREFIX}%' ORDER BY a.username")


def verify_copy_cases(harness, check):
    for label, arguments in COPY_CASES:
        seed(harness)
        original = run(harness, COPY_ORIGINAL, arguments)
        original_rows = accounts(harness)
        seed(harness)
        native = run(harness, COPY_SHIM, arguments)
        native_rows = accounts(harness)
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(normalise(original['stdout']) == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same rows')
    reset(harness)


def verify_copy_differences(harness, check):
    """Cases where the original misbehaved or the shim adds a check."""
    seed(harness)
    missing = run(harness, COPY_SHIM, [TEMPLATE])
    check(missing['exit'] == 1 and 'usage: copy_user.php' in missing['stdout'],
          'copy without a new user prints usage instead of copying to a null name')
    check(harness.sql(f"SELECT COUNT(*) FROM user_auth WHERE username LIKE '{PREFIX}%'").strip() == '1',
          'copy without a new user creates no account')

    empty = run(harness, COPY_SHIM, [TEMPLATE, ''])
    check(empty['exit'] == 1 and 'usage: copy_user.php' in empty['stdout'],
          'copy to an empty name prints usage instead of creating an unnamed account')
    check(harness.sql("SELECT COUNT(*) FROM user_auth WHERE username = ''").strip() == '0',
          'copy to an empty name creates no account')

    # The original needed no operator; the shim acts as admin_user and needs realm 1.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 1')
    try:
        denied = run(harness, COPY_SHIM, [TEMPLATE, COPY_TARGET])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n',
              'copy without user management is refused')
        check(harness.sql(f"SELECT COUNT(*) FROM user_auth WHERE username = '{COPY_TARGET}'").strip() == '0',
              'a refused copy creates no account')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 1)')
    reset(harness)


def perms_cases(ids):
    """(label, arguments). The user comes last where an error prints it, because the original printed the last value."""
    user, host, tree, template, graph = (str(ids[key]) for key in ('user', 'host', 'tree', 'template', 'graph'))
    grant = [f'--user-id={user}', '--item-type=host', f'--item-id={host}']
    return [
        ('grant a device', grant),
        ('grant a tree', [f'--user-id={user}', '--item-type=tree', f'--item-id={tree}']),
        ('grant a graph template', [f'--user-id={user}', '--item-type=graph_template', f'--item-id={template}']),
        ('grant a graph', [f'--user-id={user}', '--item-type=graph', f'--item-id={graph}']),
        ('grant with a quiet flag', [*grant, '--quiet']),
        ('grant with leading zeros in the item id', [f'--user-id={user}', '--item-type=host', f'--item-id=00{host}']),
        ('grant with no user names nobody', ['--item-type=host', f'--item-id={host}']),
        ('grant with a bare --user-id', ['--user-id', '--item-type=host', f'--item-id={host}']),
        ('grant with a zero user', ['--user-id=0', '--item-type=host', f'--item-id={host}']),
        ('grant to a user that does not exist', ['--item-type=host', f'--item-id={host}', f'--user-id={MISSING_ID}']),
        ('grant to a user that is not a number', ['--item-type=host', f'--item-id={host}', '--user-id=abc']),
        ('grant with no item type', [f'--user-id={user}', f'--item-id={host}']),
        ('grant with no item id', [f'--user-id={user}', '--item-type=host']),
        ('grant with a zero item id', [f'--user-id={user}', '--item-type=host', '--item-id=0']),
        ('grant an item that does not exist', [f'--user-id={user}', '--item-type=host', f'--item-id={MISSING_ID}']),
        ('grant an item that is not a number', [f'--user-id={user}', '--item-type=tree', '--item-id=abc']),
        ('grant an item of the wrong type', [f'--user-id={user}', '--item-type=tree', f'--item-id={host}']),
        ('grant with an unknown item type', [f'--user-id={user}', '--item-type=bogus', f'--item-id={host}']),
        ('grant with an empty item type', [f'--user-id={user}', '--item-type=', f'--item-id={host}']),
        ('perms usage', []),
        ('perms help', ['-h']),
        ('perms long help', ['--help']),
        ('perms version', ['-v']),
        ('perms long version', ['--version']),
        ('perms unknown flag', ['--bogus']),
        ('perms unknown flag with a value', ['--bogus=1']),
        ('list users', ['--list-users']),
        ('list users quietly', ['--list-users', '--quiet']),
        ('list trees', ['--list-trees']),
        ('list devices', ['--list-hosts']),
        ('list graph templates', ['--list-graph-templates']),
        ('list graphs of a device', ['--list-graphs', f'--host-id={host}']),
        ('list graphs quietly', ['--list-graphs', f'--host-id={host}', '--quiet']),
        ('list graphs without a device', ['--list-graphs']),
        ('list graphs of a device that does not exist', ['--list-graphs', f'--host-id={MISSING_ID}']),
        ('list graphs of a device that is not a number', ['--list-graphs', '--host-id=abc']),
        ('list groups', ['--list-groups']),
        ('list wins over a grant', ['--list-users', *grant]),
        ('the first listing in the original order wins', ['--list-trees', '--list-users']),
        ('an earlier error stops before a later listing', ['--item-type=bogus', '--list-users']),
        ('help stops before a later error', ['-h', '--bogus']),
    ]


def perms_seed(harness):
    """One user, a device with a graph, a tree and a graph template, under the parity prefix."""
    perms_reset(harness)
    harness.sql(
        f"INSERT INTO user_auth (username, password, realm, full_name, enabled) VALUES ('{PERMS_USER}', 'x', 0, 'Parity Grantee', 'on'); "
        f"INSERT INTO host (description, hostname) VALUES ('{PREFIX}device', '{PREFIX}device.example'), ('{PREFIX}adjacent', '{PREFIX}adjacent.example'); "
        f"INSERT INTO graph_tree (name, sort_type) VALUES ('{PREFIX}tree', 2), ('{PREFIX}tree-adjacent', 1); "
        f"INSERT INTO graph_templates (hash, name) VALUES ('{PREFIX}00000000000000000', '{PREFIX}template');")
    ids = {
        'user': int(harness.sql(f"SELECT id FROM user_auth WHERE username = '{PERMS_USER}'").strip()),
        'host': int(harness.sql(f"SELECT id FROM host WHERE description = '{PREFIX}device'").strip()),
        'tree': int(harness.sql(f"SELECT id FROM graph_tree WHERE name = '{PREFIX}tree'").strip()),
        'template': int(harness.sql(f"SELECT id FROM graph_templates WHERE name = '{PREFIX}template'").strip()),
    }
    ids['adjacent'] = int(harness.sql(f"SELECT id FROM host WHERE description = '{PREFIX}adjacent'").strip())
    harness.sql(f"INSERT INTO graph_local (graph_template_id, host_id) VALUES ({ids['template']}, {ids['host']})")
    ids['graph'] = int(harness.sql(f"SELECT MAX(id) FROM graph_local WHERE host_id = {ids['host']}").strip())
    harness.sql(f"INSERT INTO graph_local (graph_template_id, host_id) VALUES ({ids['template']}, {ids['adjacent']})")
    adjacent_graph = int(harness.sql(f"SELECT MAX(id) FROM graph_local WHERE host_id = {ids['adjacent']}").strip())
    harness.sql(f"INSERT INTO graph_templates_graph (local_graph_id, graph_template_id, title_cache) VALUES ({ids['graph']}, {ids['template']}, '{PREFIX}graph'), "
                f"({adjacent_graph}, {ids['template']}, '{PREFIX}graph-adjacent')")
    return ids


def perms_reset(harness):
    harness.sql(
        f"DELETE FROM user_auth_perms WHERE user_id IN (SELECT id FROM user_auth WHERE username = '{PERMS_USER}'); "
        f"DELETE FROM user_auth_cache WHERE user_id IN (SELECT id FROM user_auth WHERE username = '{PERMS_USER}'); "
        f"DELETE FROM graph_templates_graph WHERE title_cache LIKE '{PREFIX}%'; "
        f"DELETE FROM graph_local WHERE host_id IN (SELECT id FROM host WHERE description LIKE '{PREFIX}%'); "
        f"DELETE FROM host WHERE description LIKE '{PREFIX}%'; "
        f"DELETE FROM graph_tree WHERE name LIKE '{PREFIX}%'; "
        f"DELETE FROM graph_templates WHERE name LIKE '{PREFIX}%'; "
        f"DELETE FROM user_auth WHERE username = '{PERMS_USER}'")


def perms_rows(harness):
    return harness.sql(f"SELECT item_id, type FROM user_auth_perms WHERE user_id IN (SELECT id FROM user_auth WHERE username = '{PERMS_USER}') ORDER BY type, item_id")


def without_version(text):
    """The original's help began with its version line even after an argument error; the shim reports the error before it can read one."""
    return ''.join(line for line in text.splitlines(keepends=True) if not line.startswith(PERMS_UTILITY))


def clear_grants(harness, ids):
    harness.sql(f"DELETE FROM user_auth_perms WHERE user_id = {ids['user']}; DELETE FROM user_auth_cache WHERE user_id = {ids['user']}; "
                f"UPDATE user_auth SET reset_perms = 0 WHERE id = {ids['user']}")


def verify_perms_cases(harness, check):
    ids = perms_seed(harness)
    # Both runs see the same rows: nothing is deleted between them but the grants.
    for label, arguments in perms_cases(ids):
        clear_grants(harness, ids)
        original = run(harness, PERMS_ORIGINAL, arguments)
        original_rows = perms_rows(harness)
        clear_grants(harness, ids)
        native = run(harness, PERMS_SHIM, arguments)
        native_rows = perms_rows(harness)
        # Only these argument errors lose the version line; the rest must match byte for byte.
        stripped = label in ('perms unknown flag', 'perms unknown flag with a value', 'grant with an unknown item type', 'grant with an empty item type',
                             'an earlier error stops before a later listing')
        expected = without_version(normalise(original['stdout'])) if stripped else normalise(original['stdout'])
        check(original['exit'] == native['exit'], f'{label} keeps its exit code')
        check(expected == normalise(native['stdout']), f'{label} prints the same lines')
        check(normalise(original['stderr']) == normalise(native['stderr']), f'{label} prints the same errors')
        check(original_rows == native_rows, f'{label} leaves the same permissions')
    perms_reset(harness)


def verify_perms_scoping(harness, check):
    """A device's graph listing holds its own graphs only, and the other listings keep the adjacent rows in id order."""
    ids = perms_seed(harness)
    own = run(harness, PERMS_SHIM, ['--list-graphs', f"--host-id={ids['host']}"])['stdout']
    check(f'{PREFIX}graph\t' in own and f'{PREFIX}graph-adjacent' not in own, 'a graph listing is scoped to its own device')
    devices = run(harness, PERMS_SHIM, ['--list-hosts'])['stdout']
    check(devices.find(f'{PREFIX}device.example') != -1 and devices.find(f'{PREFIX}device.example') < devices.find(f'{PREFIX}adjacent.example'),
          'a device listing holds the adjacent device after its own, in id order')
    trees = run(harness, PERMS_SHIM, ['--list-trees'])['stdout']
    check(trees.find(f'{PREFIX}tree\n') != -1 and trees.find(f'{PREFIX}tree\n') < trees.find(f'{PREFIX}tree-adjacent'),
          'a tree listing holds the adjacent tree after its own, in id order')
    perms_reset(harness)


def verify_perms_differences(harness, check):
    ids = perms_seed(harness)
    host = str(ids['host'])
    # The original printed the last argument's value as the user id.
    original = run(harness, PERMS_ORIGINAL, [f'--user-id={MISSING_ID}', '--item-type=host', f'--item-id={host}'])
    native = run(harness, PERMS_SHIM, [f'--user-id={MISSING_ID}', '--item-type=host', f'--item-id={host}'])
    check(f'Invalid Userid: ({host})' in original['stdout'] and f'Invalid Userid: ({MISSING_ID})' in native['stdout'],
          'a missing user is reported by its own id, not the last argument')

    # The web's permission write raises the epoch and ends remembered logins; the original did neither.
    harness.sql(f"INSERT INTO user_auth_cache (user_id, hostname, token) VALUES ({ids['user']}, '{PREFIX}host', '{PREFIX}token')")
    run(harness, PERMS_ORIGINAL, [f'--user-id={ids["user"]}', '--item-type=host', f'--item-id={host}'])
    kept = harness.sql(f"SELECT reset_perms, (SELECT COUNT(*) FROM user_auth_cache WHERE user_id = {ids['user']}) FROM user_auth WHERE id = {ids['user']}").split()
    clear_grants(harness, ids)
    harness.sql(f"INSERT INTO user_auth_cache (user_id, hostname, token) VALUES ({ids['user']}, '{PREFIX}host', '{PREFIX}token')")
    run(harness, PERMS_SHIM, [f'--user-id={ids["user"]}', '--item-type=host', f'--item-id={host}'])
    raised = harness.sql(f"SELECT reset_perms, (SELECT COUNT(*) FROM user_auth_cache WHERE user_id = {ids['user']}) FROM user_auth WHERE id = {ids['user']}").split()
    check(kept == ['0', '1'] and raised == ['1', '0'], 'a grant raises the permission epoch and ends remembered logins')
    clear_grants(harness, ids)

    # The original granted to the user it was told, whoever the operator was; the shim needs user management.
    admin = harness.sql("SELECT id FROM user_auth WHERE username = 'admin'").strip()
    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id = {admin} AND realm_id = 1')
    try:
        denied = run(harness, PERMS_SHIM, [f'--user-id={ids["user"]}', '--item-type=host', f'--item-id={host}'])
        check(denied['exit'] == 1 and denied['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a grant without user management is refused')
        check(perms_rows(harness) == '', 'a refused grant writes nothing')
        listing = run(harness, PERMS_SHIM, ['--list-users'])
        check(listing['exit'] == 1 and listing['stdout'] == 'ERROR: Unknown or unauthorized operator\n', 'a listing without user management is refused')
    finally:
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id, realm_id) VALUES ({admin}, 1)')
    perms_reset(harness)


def verify_user_parity(harness, check):
    install_original(harness, COPY_ORIGINAL)
    install_original(harness, PERMS_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and User Management (1).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},1)')
        verify_copy_cases(harness, check)
        verify_copy_differences(harness, check)
        verify_perms_cases(harness, check)
        verify_perms_scoping(harness, check)
        verify_perms_differences(harness, check)
    finally:
        reset(harness)
        perms_reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
