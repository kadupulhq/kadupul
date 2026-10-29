# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise shared authentication against an isolated real HTTP/database stack.

Run with: mise exec -- python tests/Symfony/session_bridge.py
"""
from pathlib import Path
from types import SimpleNamespace
import argparse
import json
import os
import re
import sys
from urllib.error import HTTPError
from urllib.request import Request

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session

CHECKS = []

def check(condition, message):
    if not condition:
        raise AssertionError(message)
    CHECKS.append(message)
    print('PASS ' + message, flush=True)


class Fixture:
    """State the steps share. A shard that skips the step that normally
    creates the session or reads the account ID creates it on first use."""

    def __init__(self, harness, database_sessions):
        self.harness = harness
        self.database_sessions = database_sessions
        self._session = None
        self._user_id = None

    def login(self):
        session = Session(self.harness.base)
        result = session.login('behavior-admin')
        check(not result['login_form'], 'existing login succeeds')
        return session

    @property
    def session(self):
        if self._session is None:
            self._session = self.login()
        return self._session

    @session.setter
    def session(self, session):
        self._session = session

    @property
    def user_id(self):
        if self._user_id is None:
            self._user_id = int(self.harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        return self._user_id

    def actor(self, session):
        return session.request('/app.php/session')

    def mirror(self, step):
        """Write the lasting state a skipped step leaves, so the next step sees
        the settings and grants a full run gives it."""
        statements = LASTING_STATE.get(step.__name__)
        if statements:
            self.harness.sql(statements.format(user_id=self.user_id))


def console_home(fixture):
    from console_home_http import verify_console_home
    verify_console_home(fixture.harness, check)


def console_collectors(fixture):
    from console_home_http import verify_console_collectors
    verify_console_collectors(fixture.harness, check)


def console_prefix(fixture):
    from console_home_http import verify_console_prefix
    verify_console_prefix(fixture.harness, check)


def permission_denied(fixture):
    from permission_denied_scenarios import verify_permission_denied
    verify_permission_denied(fixture.harness, check)


def anonymous_entry(fixture):
    anonymous = Session(fixture.harness.base)
    response = anonymous.request('/app.php/session')
    check(response['status'] == 401, 'Symfony entry requires an authenticated session')
    response = anonymous.request('/public/index.php/session')
    check(response['status'] == 401 and response.get('json') == {'error': 'authentication_required'},
          'anonymous Symfony public request is rejected')
    check(anonymous.request('/public/index.php/healthz').get('json') == {'status': 'ok'},
          'standalone health remains available')


def script_server(fixture):
    from script_server_scenarios import verify_script_server
    verify_script_server(fixture.harness, check)


def cli_parity(fixture):
    from cli_parity_scenarios import verify_cli_parity
    verify_cli_parity(fixture.harness, check)


def tree_cli(fixture):
    from cli_parity_scenarios import verify_tree_cli
    verify_tree_cli(fixture.harness, check)


def schema_parity(fixture):
    from cli_schema_scenarios import verify_schema_parity
    verify_schema_parity(fixture.harness, check)


def audit_parity(fixture):
    from cli_audit_scenarios import verify_audit_parity
    verify_audit_parity(fixture.harness, check)


def forged_session(fixture):
    harness = fixture.harness
    # A syntactically valid, attacker-selected ID must never be adopted.
    anonymous = Session(harness.base)
    forged = '0123456789abcdef' * 2
    for entry in ('/app.php', '/public/index.php'):
        request = Request(harness.base + entry + '/session', headers={'Cookie': 'Cacti=' + forged})
        try:
            response = anonymous.opener.open(request)
        except HTTPError as error:
            response = error
        with response:
            check(response.status == 401, 'unknown session cookie is rejected: ' + entry)
            check(all(forged not in value for value in response.headers.get_all('Set-Cookie', [])),
                  'unknown session ID is not adopted: ' + entry)
    if fixture.database_sessions:
        check(harness.sql(f"SELECT COUNT(*) FROM sessions WHERE id='{forged}'").strip() == '0',
              'unknown database session cannot be created')
    else:
        probe = harness.php('-r', '$path = ini_get("session.save_path") ?: sys_get_temp_dir(); echo is_file($path . "/sess_' + forged + '") ? "exists" : "absent";')
        check(probe['exit'] == 0 and probe['stdout'] == 'absent', 'unknown file session cannot be created')


def login_policy(fixture):
    harness = fixture.harness
    fixture.session = session = fixture.login()
    user_id = fixture.user_id
    expected = {'id': user_id, 'username': 'admin'}
    check(fixture.actor(session).get('json') == expected, 'existing session reaches Symfony identity query')
    cookies = next(handler.cookiejar for handler in session.opener.handlers if hasattr(handler, 'cookiejar'))
    credential = next(cookie.value for cookie in cookies if cookie.name == 'Cacti')
    check(Session(harness.base).request('/app.php/session?Cacti=' + credential)['status'] == 401,
          'query parameters cannot select an authenticated session')
    harness.sql("REPLACE INTO settings (name,value) VALUES ('force_https','on')")
    check(fixture.actor(session)['status'] == 403, 'HTTPS policy is enforced before session access')
    harness.sql("REPLACE INTO settings (name,value) VALUES ('force_https',''),('guest_user','admin')")
    check(fixture.actor(session)['status'] == 401, 'named guest identity cannot enter the console')
    harness.sql("REPLACE INTO settings (name,value) VALUES ('guest_user','0')")
    if fixture.database_sessions:
        check(int(harness.sql(f'SELECT COUNT(*) FROM sessions WHERE user_id={user_id}').strip()) > 0,
              'legacy database session handler owns the authenticated session')
    check(session.request('/public/index.php/session').get('json') == expected,
          'Symfony public entry owns authentication for the same session')


def cdef_legacy_pages(fixture):
    from cdef_legacy_page_scenarios import verify_cdef_legacy_pages
    verify_cdef_legacy_pages(fixture.harness, fixture.session, check)


def data_inputs(fixture):
    from data_input_scenarios import verify_data_inputs
    verify_data_inputs(fixture.harness, fixture.session, check)


def about_authentication(fixture):
    from about_authentication_scenarios import verify_about_authentication
    verify_about_authentication(fixture.harness, fixture.user_id, fixture.database_sessions, check)


def about(fixture):
    from about_scenarios import verify_about
    verify_about(fixture.harness, fixture.session, fixture.user_id, check)


def inventory(fixture):
    from inventory_scenarios import verify_inventory
    verify_inventory(fixture.harness, fixture.session, fixture.user_id, check)


def data_source_profile_deletion(fixture):
    from data_source_profile_scenarios import verify_data_source_profile_deletion
    verify_data_source_profile_deletion(fixture.harness, fixture.session, check)


def data_source_profile_heartbeat(fixture):
    from data_source_profile_heartbeat_scenarios import verify_data_source_profile_heartbeat
    verify_data_source_profile_heartbeat(fixture.harness, fixture.session, check)


def site_edit(fixture):
    from site_edit_scenarios import verify_site_edit
    verify_site_edit(fixture.harness, fixture.session, fixture.user_id, check)


def links(fixture):
    from link_scenarios import verify_links
    verify_links(fixture.harness, fixture.session, fixture.user_id, check)


def site_create(fixture):
    from site_create_scenarios import verify_site_create
    verify_site_create(fixture.harness, fixture.session, fixture.user_id, check)


def site_lifecycle(fixture):
    from site_lifecycle_scenarios import verify_site_lifecycle
    verify_site_lifecycle(fixture.harness, fixture.session, fixture.user_id, check)


def device_create(fixture):
    from device_create_scenarios import verify_device_create
    verify_device_create(fixture.harness, fixture.session, fixture.user_id, check)


def palette_colors(fixture):
    from palette_color_scenarios import verify_palette_colors
    verify_palette_colors(fixture.harness, fixture.session, fixture.user_id, check)


def gprint_presets(fixture):
    from gprint_preset_scenarios import verify_gprint_presets
    verify_gprint_presets(fixture.harness, fixture.session, fixture.user_id, check)


def color_templates(fixture):
    from color_template_scenarios import verify_color_templates
    verify_color_templates(fixture.harness, fixture.session, fixture.user_id, check)


def vdefs(fixture):
    from vdef_scenarios import verify_vdefs
    verify_vdefs(fixture.harness, fixture.session, fixture.user_id, check)


def rrdcheck(fixture):
    from rrdcheck_scenarios import verify_rrdcheck
    verify_rrdcheck(fixture.harness, fixture.session, fixture.user_id, check)


def cdefs(fixture):
    from cdef_scenarios import verify_cdefs
    verify_cdefs(fixture.harness, fixture.session, fixture.user_id, check)


def automation_templates(fixture):
    from automation_template_scenarios import verify_automation_templates
    verify_automation_templates(fixture.harness, fixture.session, fixture.user_id, check)


def account_lifecycle(fixture):
    harness = fixture.harness
    session = fixture.session
    user_id = fixture.user_id
    expected = {'id': user_id, 'username': 'admin'}

    def permission_denied(session):
        return fixture.actor(session)['status'] == 401

    response = session.opener.open(harness.base + '/app.php/session')
    check('no-store' in response.headers.get('Cache-Control', ''), 'identity response is never cached')
    response.close()

    harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=8')
    check(permission_denied(session), 'realm revocation takes effect on next request')
    harness.sql("INSERT INTO user_auth_group (name,enabled) VALUES ('symfony-test','on')")
    group_id = int(harness.sql("SELECT id FROM user_auth_group WHERE name='symfony-test'").strip())
    harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id}); '
                f'INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group_id},8)')
    check(fixture.actor(session).get('json') == expected, 'enabled group realm authorizes shared identity')
    harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group_id}")
    check(permission_denied(session), 'disabled group cannot authorize')
    harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)')

    for column in ('enabled', 'locked'):
        value = '' if column == 'enabled' else 'on'
        harness.sql(f"UPDATE user_auth SET {column}='{value}' WHERE id={user_id}")
        check(fixture.actor(session)['status'] == 401, column + ' change rejects persisted session')
        restore = 'on' if column == 'enabled' else ''
        harness.sql(f"UPDATE user_auth SET {column}='{restore}' WHERE id={user_id}")
        check(fixture.actor(session)['status'] == 401, 're-enabling does not resurrect rejected session')
        session = fixture.login()

    session.request('/logout.php')
    check(fixture.actor(session)['status'] == 401, 'legacy logout removes Symfony access')
    harness.sql(f"UPDATE user_auth SET must_change_password='on', password_change='on' WHERE id={user_id}")
    session = fixture.login()
    check(fixture.actor(session)['status'] == 401,
          'mandatory password change precedes Symfony access')
    harness.sql(f"UPDATE user_auth SET must_change_password='', password_change='' WHERE id={user_id}")
    session = fixture.login()
    harness.sql(f'DELETE FROM user_auth WHERE id={user_id}')
    check(fixture.actor(session)['status'] == 401, 'deleted account cannot use persisted session')


STEPS = (
    console_home, console_collectors, console_prefix, permission_denied, anonymous_entry,
    script_server, cli_parity, tree_cli, schema_parity, audit_parity, forged_session,
    login_policy, cdef_legacy_pages, data_inputs, about_authentication, about, inventory,
    data_source_profile_deletion, data_source_profile_heartbeat, site_edit, links,
    site_create, site_lifecycle, device_create, palette_colors, gprint_presets,
    color_templates, vdefs, rrdcheck, cdefs, automation_templates,
    # Revokes realms and finally deletes the admin account, so it runs last.
    account_lifecycle,
)

# Settings rows and admin grants each step leaves behind in a full run, written
# in its place when a shard skips it. Steps that snapshot and restore a row
# leave nothing. "INSERT IGNORE ... ''" mirrors a step that writes back a value
# it read, which turns an absent row into an empty one. A later step can depend
# on the row existing: device_create proves its row lock covers
# "UPDATE settings ... guest_user", which matches no row unless login_policy
# ran first. The time_last_change_* markers are left out because the
# application rewrites them on every save.
LASTING_STATE = {
    # Resets the CSP mode, console and help settings it toggles and restores
    # the PHP path its worker failure case breaks.
    'console_home': "REPLACE INTO settings (name,value) VALUES ('content_security_policy_script',''),('hide_console',''),"
                    "('path_php_binary','/usr/local/bin/php'),('local_documentation',''); "
                    'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)',
    'permission_denied': "REPLACE INTO settings (name,value) VALUES ('content_security_policy_script',''); "
                         "DELETE FROM settings WHERE name='compatibility_denied_reskin'",
    # Revoked realm checks reinsert the admin grants unconditionally.
    'schema_parity': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},15),({user_id},26)',
    # The policy checks end by writing both rows explicitly.
    'login_policy': "REPLACE INTO settings (name,value) VALUES ('force_https',''),('guest_user','0')",
    'data_inputs': "UPDATE settings SET value='1' WHERE name='auth_method'; "
                   "INSERT IGNORE INTO settings (name,value) VALUES ('i18n_language_support',''); "
                   "INSERT IGNORE INTO settings_user (user_id,name,value) VALUES ({user_id},'user_language',''); "
                   'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},2),({user_id},8)',
    # Leaves French forced while re-enabling detection.
    'about': "REPLACE INTO settings (name,value) VALUES ('i18n_force_language','fr'),('i18n_auto_detection','1'),"
             "('i18n_default_language','en-US'),('i18n_language_support','1'); "
             'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)',
    'inventory': "INSERT IGNORE INTO settings (name,value) VALUES ('enable_snmp_agent',''); "
                 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)',
    'data_source_profile_deletion': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},9)',
    'site_edit': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)',
    'links': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},15)',
    'site_create': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)',
    'site_lifecycle': 'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)',
    # Default SNMP credentials stay at the creation fixture's values.
    'device_create': "REPLACE INTO settings (name,value) VALUES ('snmp_community','create-test-community'),"
                     "('snmp_password','create-test-auth'),('snmp_priv_passphrase','create-test-privacy'); "
                     "INSERT IGNORE INTO settings (name,value) VALUES ('enable_snmp_agent',''),('rrd_autoclean',''),"
                     "('rrd_autoclean_method',''),('selective_device_debug',''),('automation_graphs_enabled',''); "
                     'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)',
}

# CI runs one stack per shard. The split follows measured hosted-runner step
# times: the schema and audit parity CLIs take about seven minutes together,
# device creation about four. A shard creates the session and account ID it
# needs through Fixture and mirrors LASTING_STATE for the steps it skips.
SHARDS = (
    ('schema_parity', 'audit_parity'),
    ('cdef_legacy_pages', 'inventory', 'device_create'),
    ('console_home', 'console_collectors', 'console_prefix', 'permission_denied', 'anonymous_entry',
     'script_server', 'cli_parity', 'tree_cli', 'forged_session', 'login_policy', 'data_inputs',
     'about_authentication', 'about', 'data_source_profile_deletion', 'data_source_profile_heartbeat',
     'site_edit', 'links', 'site_create', 'site_lifecycle', 'palette_colors', 'gprint_presets',
     'color_templates', 'vdefs', 'rrdcheck', 'cdefs', 'automation_templates', 'account_lifecycle'),
)


def shard_errors(shards=None):
    """Return why the shards fail to cover every step exactly once, in order."""
    shards = SHARDS if shards is None else shards
    names = [step.__name__ for step in STEPS]
    errors = []
    assigned = [name for shard in shards for name in shard]
    for name in sorted(set(assigned) - set(names)):
        errors.append('unknown step in a shard: ' + name)
    for name in sorted(set(LASTING_STATE) - set(names)):
        errors.append('lasting state names an unknown step: ' + name)
    for name in names:
        count = assigned.count(name)
        if count != 1:
            errors.append(f'step {name} is assigned to {count} shards')
    for number, shard in enumerate(shards, 1):
        known = [name for name in shard if name in names]
        if known != sorted(known, key=names.index):
            errors.append(f'shard {number} changes the step order')
    return errors


def selected_steps(selector):
    errors = shard_errors()
    if errors:
        raise SystemExit('Invalid session bridge shards: ' + '; '.join(errors))
    if selector is None:
        return STEPS
    match = re.fullmatch(r'([1-9][0-9]*)/([1-9][0-9]*)', selector)
    if not match or int(match[2]) != len(SHARDS) or int(match[1]) > len(SHARDS):
        raise SystemExit(f'--shard must be N/{len(SHARDS)} with N from 1 to {len(SHARDS)}')
    wanted = SHARDS[int(match[1]) - 1]
    return tuple(step for step in STEPS if step.__name__ in wanted)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    parser.add_argument('--tree-cli-only', action='store_true')
    parser.add_argument('--shard', metavar='N/M', help='run one CI shard of the steps instead of all of them')
    parser.add_argument('--project', default='kadupul-symfony-auth')
    args = parser.parse_args()
    # Coverage evidence lists every check of a complete run.
    if args.shard and (args.coverage_output or args.tree_cli_only):
        parser.error('--shard cannot be combined with --coverage-output or --tree-cli-only')
    steps = selected_steps(args.shard)
    database_sessions = args.database_sessions
    if os.environ.get('KADUPUL_BROWSER_COVERAGE'):
        os.environ['KADUPUL_BROWSER_COVERAGE_SESSION'] = 'database' if database_sessions else 'files'
    harness = Harness(SimpleNamespace(project=args.project, target='symfony-auth'))
    command = harness.command
    def installer_allowance(*arguments, check=False):
        if 'cli/install_cacti.php' in arguments:
            return harness.compose('exec', '-T', '-u', 'www-data', 'web', *arguments, check=check, timeout=600)
        return command(*arguments, check=check)
    harness.command = installer_allowance
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    try:
        harness.setup()
        if args.tree_cli_only:
            from cli_parity_scenarios import verify_tree_cli
            verify_tree_cli(harness, check)
            return
        if database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        fixture = Fixture(harness, database_sessions)
        for step in STEPS:
            if step in steps:
                step(fixture)
            else:
                fixture.mirror(step)
        if args.shard:
            print(f'Shared session bridge shard {args.shard} passed.', flush=True)
        else:
            print('Shared session bridge integration passed.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()
    if args.coverage_output:
        from coverage_support import publish_coverage
        publish_coverage(args.coverage_output, database_sessions, CHECKS)


if __name__ == '__main__':
    main()
