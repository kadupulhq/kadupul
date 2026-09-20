"""Exercise shared authentication against an isolated real HTTP/database stack.

Run with: mise exec -- python tests/Symfony/session_bridge.py
"""
from pathlib import Path
from types import SimpleNamespace
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session


def check(condition, message):
    if not condition:
        raise AssertionError(message)
    print('PASS ' + message, flush=True)


def main():
    harness = Harness(SimpleNamespace(project='kadupul-symfony-auth', target='symfony-auth'))
    try:
        harness.setup()
        database_sessions = '--database-sessions' in sys.argv
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


if __name__ == '__main__':
    main()
