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


def verify_user_parity(harness, check):
    install_original(harness, COPY_ORIGINAL)
    admin = harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip()
    saved = harness.sql("SELECT value FROM settings WHERE name='admin_user'")
    try:
        # With no --as the shim acts as settings.admin_user, which needs
        # Console Access (8) and User Management (1).
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('admin_user','{admin}'); "
                    f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({admin},8),({admin},1)')
        verify_copy_cases(harness, check)
        verify_copy_differences(harness, check)
    finally:
        reset(harness)
        harness.sql("DELETE FROM settings WHERE name='admin_user'")
        for value in saved.splitlines():
            harness.sql(f"INSERT INTO settings (name,value) VALUES ('admin_user',CONVERT(UNHEX('{value.encode().hex()}') USING utf8mb4))")
