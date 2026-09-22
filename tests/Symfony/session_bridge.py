"""Exercise shared authentication against an isolated real HTTP/database stack.

Run with: mise exec -- python tests/Symfony/session_bridge.py
"""
from pathlib import Path
from types import SimpleNamespace
import argparse
import json
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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    args = parser.parse_args()
    database_sessions = args.database_sessions
    harness = Harness(SimpleNamespace(project='kadupul-symfony-auth', target='symfony-auth'))
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    try:
        harness.setup()
        if database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        anonymous = Session(harness.base)
        response = anonymous.request('/app.php/session')
        check(response['status'] == 401, 'Symfony entry requires an authenticated session')
        response = anonymous.request('/public/index.php/session')
        check(response['status'] == 401 and response.get('json') == {'error': 'authentication_required'},
              'anonymous Symfony public request is rejected')
        check(anonymous.request('/public/index.php/healthz').get('json') == {'status': 'ok'},
              'standalone health remains available')

        # A syntactically valid, attacker-selected ID must never be adopted.
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
        if database_sessions:
            check(harness.sql(f"SELECT COUNT(*) FROM sessions WHERE id='{forged}'").strip() == '0',
                  'unknown database session cannot be created')
        else:
            probe = harness.php('-r', '$path = ini_get("session.save_path") ?: sys_get_temp_dir(); echo is_file($path . "/sess_' + forged + '") ? "exists" : "absent";')
            check(probe['exit'] == 0 and probe['stdout'] == 'absent', 'unknown file session cannot be created')

        def login():
            session = Session(harness.base)
            result = session.login('behavior-admin')
            check(not result['login_form'], 'existing login succeeds')
            return session

        def actor(session):
            return session.request('/app.php/session')

        def permission_denied(session):
            response = actor(session)
            return response['status'] == 401

        session = login()
        user_id = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        expected = {'id': user_id, 'username': 'admin'}
        check(actor(session).get('json') == expected, 'existing session reaches Symfony identity query')
        cookies = next(handler.cookiejar for handler in session.opener.handlers if hasattr(handler, 'cookiejar'))
        credential = next(cookie.value for cookie in cookies if cookie.name == 'Cacti')
        check(Session(harness.base).request('/app.php/session?Cacti=' + credential)['status'] == 401,
              'query parameters cannot select an authenticated session')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('force_https','on')")
        check(actor(session)['status'] == 403, 'HTTPS policy is enforced before session access')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('force_https',''),('guest_user','admin')")
        check(actor(session)['status'] == 401, 'named guest identity cannot enter the console')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('guest_user','0')")
        if database_sessions:
            check(int(harness.sql(f'SELECT COUNT(*) FROM sessions WHERE user_id={user_id}').strip()) > 0,
                  'legacy database session handler owns the authenticated session')
        check(session.request('/public/index.php/session').get('json') == expected,
              'Symfony public entry owns authentication for the same session')
        from inventory_scenarios import verify_inventory
        verify_inventory(harness, session, user_id, check)
        from site_edit_scenarios import verify_site_edit
        verify_site_edit(harness, session, user_id, check)
        from site_create_scenarios import verify_site_create
        verify_site_create(harness, session, user_id, check)
        from device_create_scenarios import verify_device_create
        verify_device_create(harness, session, user_id, check)
        response = session.opener.open(harness.base + '/app.php/session')
        check('no-store' in response.headers.get('Cache-Control', ''), 'identity response is never cached')
        response.close()

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=8')
        check(permission_denied(session), 'realm revocation takes effect on next request')
        harness.sql("INSERT INTO user_auth_group (name,enabled) VALUES ('symfony-test','on')")
        group_id = int(harness.sql("SELECT id FROM user_auth_group WHERE name='symfony-test'").strip())
        harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group_id},{user_id}); '
                    f'INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group_id},8)')
        check(actor(session).get('json') == expected, 'enabled group realm authorizes shared identity')
        harness.sql(f"UPDATE user_auth_group SET enabled='' WHERE id={group_id}")
        check(permission_denied(session), 'disabled group cannot authorize')
        harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)')

        for column in ('enabled', 'locked'):
            value = '' if column == 'enabled' else 'on'
            harness.sql(f"UPDATE user_auth SET {column}='{value}' WHERE id={user_id}")
            check(actor(session)['status'] == 401, column + ' change rejects persisted session')
            restore = 'on' if column == 'enabled' else ''
            harness.sql(f"UPDATE user_auth SET {column}='{restore}' WHERE id={user_id}")
            check(actor(session)['status'] == 401, 're-enabling does not resurrect rejected session')
            session = login()

        session.request('/logout.php')
        check(actor(session)['status'] == 401, 'legacy logout removes Symfony access')
        harness.sql(f"UPDATE user_auth SET must_change_password='on', password_change='on' WHERE id={user_id}")
        session = login()
        check(actor(session)['status'] == 401,
              'mandatory password change precedes Symfony access')
        harness.sql(f"UPDATE user_auth SET must_change_password='', password_change='' WHERE id={user_id}")
        session = login()
        harness.sql(f'DELETE FROM user_auth WHERE id={user_id}')
        check(actor(session)['status'] == 401, 'deleted account cannot use persisted session')
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
