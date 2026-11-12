# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Installed local-login contracts, using real HTTP, SQL and session storage."""
import copy
import hashlib
import secrets
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request

from harness import Session


def verify_local_authentication(harness, database_sessions, check):
    username = 'local-flow-' + secrets.token_hex(5)
    password = 'LocalFixture7!'
    changed_password = 'UpdatedLocalFixture8!'
    settings = {
        'auth_method': '1', 'guest_user': '0', 'auth_cache_enabled': 'on',
        'secpass_lockfailed': '3', 'secpass_unlocktime': '1',
        'secpass_forceold': '', 'secpass_minlen': '8', 'secpass_reqnum': 'on',
        'secpass_reqmixcase': 'on', 'secpass_reqspec': 'on', 'secpass_history': '0',
        'secpass_expirepass': '0', 'secpass_expireaccount': '0',
        'i18n_language_support': '', 'force_https': '',
    }

    def literal(value):
        return '0x' + str(value).encode().hex() if str(value) else "''"

    def setting(name, value):
        harness.sql(f'REPLACE INTO settings(name,value) VALUES({literal(name)},{literal(value)})')

    def scalar(sql):
        return harness.sql(sql).strip()

    saved_settings = {name: scalar(f'SELECT HEX(value) FROM settings WHERE name={literal(name)}')
                      if scalar(f'SELECT COUNT(*) FROM settings WHERE name={literal(name)}') == '1' else None
                      for name in settings}
    account_ids = []

    def jar(session):
        return next(handler.cookiejar for handler in session.opener.handlers if hasattr(handler, 'cookiejar'))

    def cookie(session, name):
        return next((item.value for item in jar(session) if item.name == name), None)

    def actor(session):
        return session.request('/app.php/session')

    def browser(session, path, site, fields=None):
        # Sec-Fetch-Site is what a browser reports for the whole redirect
        # chain; urllib carries it to the redirected GET as well.
        data = None if fields is None else urlencode(fields).encode()
        try:
            response = session.opener.open(Request(harness.base + path, data=data, headers={'Sec-Fetch-Site': site}), timeout=30)
        except HTTPError as error:
            response = error
        with response:
            response.read()
            return response.url.replace(harness.base, '<BASE>')

    def submit(session, supplied=password, name=username, remember=False, csrf=True):
        form = session.request('/index.php')
        check(form['login_form'] and bool(session.token), 'local login renders a form and native CSRF token')
        check(form['url'].startswith('<BASE>/'), 'local login form stays inside the installation prefix')
        session.before_login_cookie = cookie(session, 'Cacti')
        fields = {'action': 'login', 'login_username': name, 'login_password': supplied, 'realm': '0'}
        if csrf:
            fields['__csrf_magic'] = session.token
        if remember:
            fields['remember_me'] = 'on'
        return session.request(form['url'][len('<BASE>'):], fields)

    def admitted(supplied=password, remember=False):
        session = Session(harness.base)
        submit(session, supplied, remember=remember)
        check(actor(session).get('json') == {'id': user_id, 'username': username},
              'local credentials create a session accepted by the Symfony firewall')
        check(session.before_login_cookie is not None and cookie(session, 'Cacti') != session.before_login_cookie,
              'successful local login rotates the anonymous session identifier')
        return session

    try:
        for name, value in settings.items():
            setting(name, value)
        hashed = harness.php('-r', 'echo password_hash("LocalFixture7!", PASSWORD_DEFAULT);')['stdout'].strip()
        check(hashed.startswith('$'), 'fixture uses the installed runtime password hasher')
        harness.sql('INSERT INTO user_auth(username,password,realm,enabled,locked,password_change,must_change_password,login_opts,lastchange,lastlogin) '
                    f"VALUES({literal(username)},{literal(hashed)},0,'on','','on','',3,UNIX_TIMESTAMP(),UNIX_TIMESTAMP()),"
                    f"({literal(username)},{literal(hashed)},3,'on','','on','',3,UNIX_TIMESTAMP(),UNIX_TIMESTAMP())")
        account_ids = [int(value) for value in scalar(f'SELECT id FROM user_auth WHERE username={literal(username)} ORDER BY realm').splitlines()]
        check(len(account_ids) == 2, 'local and directory identities are separate persisted accounts')
        user_id, directory_id = account_ids
        harness.sql(f'INSERT INTO user_auth_realm(user_id,realm_id) VALUES({user_id},8)')
        directory_before = scalar(f'SELECT CONCAT(password,CHAR(58),lastlogin,CHAR(58),failed_attempts,CHAR(58),locked) FROM user_auth WHERE id={directory_id}')
        check(actor(Session(harness.base))['status'] == 401, 'anonymous request has no Symfony identity')

        missing_csrf = Session(harness.base)
        submit(missing_csrf, csrf=False)
        check(actor(missing_csrf)['status'] == 401, 'missing login CSRF cannot create a session')
        check(scalar(f'SELECT COUNT(*) FROM user_log WHERE user_id={user_id}') == '0', 'missing login CSRF precedes account audit writes')

        for field in ['login_username', 'login_password', 'realm']:
            malformed = Session(harness.base)
            form = malformed.request('/index.php')
            fields = {'action': 'login', 'login_username': username, 'login_password': password,
                      'realm': '0', '__csrf_magic': malformed.token}
            value = fields.pop(field)
            fields[field + '[]'] = value
            response = malformed.request(form['url'][len('<BASE>'):], fields)
            check(response['status'] == 400, 'nonscalar ' + field + ' receives a controlled client error')
            check(actor(malformed)['status'] == 401, 'malformed local login cannot create a session')
        check(scalar(f'SELECT COUNT(*) FROM user_log WHERE user_id={user_id}') == '0', 'malformed login is rejected before authentication audit writes')

        for name, supplied, policy in [(username, 'WrongFixture7!', ''), (username, '', ''),
                                      ('missing-' + username, password, ''), (username, password, 'disabled'),
                                      (username, password, 'locked')]:
            enabled = '' if policy == 'disabled' else 'on'
            locked_flag = 'on' if policy == 'locked' else ''
            harness.sql(f'UPDATE user_auth SET enabled={literal(enabled)},locked={literal(locked_flag)}, '
                        f'lastfail=UNIX_TIMESTAMP(),failed_attempts=0 WHERE id={user_id}')
            session = Session(harness.base)
            submit(session, supplied, name, remember=True)
            check(actor(session)['status'] == 401, 'invalid or ineligible local login cannot create a session')
            check(cookie(session, 'cacti_remembers') is None, 'denied local login publishes no remembered credential')
            check(scalar(f'SELECT COUNT(*) FROM user_log WHERE user_id={user_id} AND result=1') == '0',
                  'denied local login records no successful authentication')
        harness.sql(f"UPDATE user_auth SET enabled='on',locked='',lastfail=0,failed_attempts=0 WHERE id={user_id}")

        for attempt in range(1, 4):
            submit(Session(harness.base), 'WrongFixture7!')
            check(scalar(f'SELECT failed_attempts FROM user_auth WHERE id={user_id}') == str(attempt),
                  'each rejected password increments the persisted local failure count once')
        check(scalar(f'SELECT locked FROM user_auth WHERE id={user_id}') == 'on', 'failure threshold locks the local account')
        locked = Session(harness.base)
        submit(locked)
        check(actor(locked)['status'] == 401, 'correct password cannot pass an active lockout')
        harness.sql(f'UPDATE user_auth SET lastfail=UNIX_TIMESTAMP()-61 WHERE id={user_id}')
        recovered = admitted()
        check(scalar(f'SELECT CONCAT(locked,failed_attempts) FROM user_auth WHERE id={user_id}') == '0',
              'expired lockout clears the lock and failure count before admission')
        check(recovered.request('/index.php')['admin_layout'], 'local session survives navigation to the console')
        if database_sessions:
            check(int(scalar(f'SELECT COUNT(*) FROM sessions WHERE user_id={user_id}')) > 0,
                  'native database session handler persists the admitted identity')

        # A legacy hash must be accepted and upgraded by the real login path.
        harness.sql(f'UPDATE user_auth SET password={literal(hashlib.md5(password.encode()).hexdigest())} WHERE id={user_id}')
        legacy = admitted()
        check(int(scalar(f'SELECT LENGTH(password) FROM user_auth WHERE id={user_id}')) > 32,
              'local login transparently upgrades a legacy MD5 hash')
        check(actor(legacy).get('json', {}).get('id') == user_id, 'rehash leaves the newly issued credential binding usable')

        first = admitted(remember=True)
        second = admitted()
        old_remember = copy.copy(next(item for item in jar(first) if item.name == 'cacti_remembers'))
        first.request('/auth_changepassword.php')
        first.request('/auth_changepassword.php', {'action': 'changepassword', 'current_password': password,
                      'password': changed_password, 'password_confirm': changed_password, '__csrf_magic': first.token})
        check(actor(first)['status'] == 401 and actor(second)['status'] == 401,
              'a password change ends both the changing and the other active session')
        stale = Session(harness.base)
        jar(stale).set_cookie(old_remember)
        stale.request('/index.php')
        check(actor(stale)['status'] == 401, 'a password change revokes the earlier remembered credential')
        updated = admitted(changed_password, remember=True)

        # A remembered credential crosses native restoration into Symfony access.
        for item in list(jar(updated)):
            if item.name != 'cacti_remembers':
                jar(updated).clear(item.domain, item.path, item.name)
        remembered_before = cookie(updated, 'cacti_remembers')
        updated.request('/index.php')
        check(actor(updated).get('json', {}).get('id') == user_id, 'remember-me restores local access through the native entry')
        check(cookie(updated, 'cacti_remembers') != remembered_before, 'remember-me restoration rotates its credential')
        remembered_after = copy.copy(next(item for item in jar(updated) if item.name == 'cacti_remembers'))
        updated.request('/logout.php')
        check(actor(updated).get('json', {}).get('id') == user_id, 'GET logout only confirms and retains the authenticated session')
        updated.request('/logout.php', {'action': '', '__csrf_magic': 'invalid'})
        check(actor(updated).get('json', {}).get('id') == user_id, 'invalid logout CSRF retains the authenticated session')
        updated.request('/logout.php')
        updated.request('/logout.php', {'action': '', '__csrf_magic': updated.token})
        check(actor(updated)['status'] == 401, 'confirmed POST logout removes Symfony access')
        replay = Session(harness.base)
        jar(replay).set_cookie(remembered_after)
        replay.request('/index.php')
        check(actor(replay)['status'] == 401, 'confirmed logout revokes remembered restoration')

        # The idle timer posts with the page's token, which may be stale.
        stale_timeout = {'action': 'timeout', '__csrf_magic': 'expired'}
        idle = admitted(changed_password)
        browser(idle, '/logout.php?action=timeout', 'cross-site', stale_timeout)
        check(actor(idle).get('json', {}).get('id') == user_id, 'cross-site timeout logout with a stale token retains the session')
        browser(idle, '/logout.php?action=timeout', 'cross-site')
        check(actor(idle).get('json', {}).get('id') == user_id, 'cross-site timeout GET only confirms and retains the session')
        landed = browser(idle, '/logout.php?action=timeout', 'same-origin', stale_timeout)
        check(actor(idle)['status'] == 401 and landed == '<BASE>/logout.php',
              'idle timeout logout with a stale token ends the session and reports it')
        tokenless = admitted(changed_password)
        browser(tokenless, '/logout.php?action=timeout', 'same-origin')
        check(actor(tokenless)['status'] == 401, 'idle timeout logout without a token ends the session')
        anonymous = Session(harness.base)
        check('logout.php' not in anonymous.request('/logout.php')['url'], 'logout without a session redirects instead of confirming')

        setting('secpass_forceold', 'on')
        setting('secpass_minlen', '24')
        forced = Session(harness.base)
        response = submit(forced, changed_password)
        check('auth_changepassword.php' in response['url'], 'forced complexity hands the authenticated local user to password change')
        check(actor(forced)['status'] == 401, 'mandatory password change precedes protected Symfony access')
        completed_password = 'CompletedLocalAuthentication9!'
        forced.request('/auth_changepassword.php')
        forced.request('/auth_changepassword.php', {'action': 'changepassword', 'current_password': changed_password,
                       'password': completed_password, 'password_confirm': completed_password, '__csrf_magic': forced.token})
        check(actor(forced)['status'] == 401, 'completing a forced password change ends its restricted session')
        admitted(completed_password)
        check(scalar(f'SELECT must_change_password FROM user_auth WHERE id={user_id}') == '',
              'forced password-change completion clears the persisted change requirement')
        setting('secpass_minlen', '64')
        harness.sql(f"UPDATE user_auth SET password_change='',must_change_password='' WHERE id={user_id}")
        forbidden = Session(harness.base)
        submit(forbidden, completed_password)
        check(actor(forbidden)['status'] == 401, 'noncompliant password is refused when password changes are prohibited')

        guest_username = 'guest-flow-' + secrets.token_hex(5)
        guest_password = harness.php(
            '-r', 'echo password_hash("GuestFixture7!", PASSWORD_DEFAULT);'
        )['stdout'].strip()
        harness.sql(
            'INSERT INTO user_auth(username,password,realm,enabled,locked,password_change,'
            'must_change_password,login_opts,lastchange,lastlogin) VALUES('
            f'{literal(guest_username)},{literal(guest_password)},0,\'\',\'\',\'\',\'\',3,'
            'UNIX_TIMESTAMP(),UNIX_TIMESTAMP())'
        )
        guest_id = int(scalar(f'SELECT id FROM user_auth WHERE username={literal(guest_username)} AND realm=0'))
        account_ids.append(guest_id)
        guest_session = Session(harness.base)
        guest_session.request('/index.php')
        guest_before = cookie(guest_session, 'Cacti')
        setting('guest_user', guest_username)
        guest_page = guest_session.request('/help.php')
        check(guest_page['status'] == 200 and not guest_page['login_form'],
              'disabled guest account can read a guest-enabled page')
        check(cookie(guest_session, 'Cacti') != guest_before,
              'guest admission rotates the anonymous session identifier')
        check(actor(guest_session)['status'] == 401,
              'guest identity cannot enter the Symfony console session route')

        harness.sql(f"UPDATE user_auth SET locked='on' WHERE id={guest_id}")
        locked_guest = Session(harness.base)
        response = locked_guest.request('/help.php')
        check(response['status'] == 403,
              'a locked guest account is refused on a guest-enabled page')
        check(actor(locked_guest)['status'] == 401,
              'locked guest refusal creates no authenticated console session')
        setting('guest_user', '0')
        check(scalar(f'SELECT CONCAT(password,CHAR(58),lastlogin,CHAR(58),failed_attempts,CHAR(58),locked) FROM user_auth WHERE id={directory_id}') == directory_before,
              'the complete local workflow preserves the same username in the directory realm')
    finally:
        for name, encoded in saved_settings.items():
            harness.sql(f'DELETE FROM settings WHERE name={literal(name)}')
            if encoded is not None:
                harness.sql(f'INSERT INTO settings(name,value) VALUES({literal(name)},' + (f'CONVERT(0x{encoded} USING utf8mb4)' if encoded else "''") + ')')
        for account_id in account_ids:
            for table, column in [('user_auth_cache', 'user_id'), ('sessions', 'user_id'), ('settings_user', 'user_id'),
                                  ('user_auth_realm', 'user_id'), ('user_log', 'user_id'), ('user_auth', 'id')]:
                harness.sql(f'DELETE FROM {table} WHERE {column}={account_id}')
