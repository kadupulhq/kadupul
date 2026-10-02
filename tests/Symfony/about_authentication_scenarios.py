# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Native Apache identity and remembered credentials against installed MariaDB."""
import base64
import hashlib
import ipaddress
import json
import re
import secrets
import time
from urllib.error import HTTPError
from urllib.parse import unquote
from urllib.request import Request, HTTPRedirectHandler, HTTPCookieProcessor, build_opener
from harness import Session


def verify_about_authentication(harness, admin_id, database_sessions, check):
    names = ('about-restore-basic', 'about-restore-cookie')
    policy = ('auth_method', 'auth_cache_enabled', 'force_https', 'guest_user')
    saved = {}
    created = []
    apache_enabled = False
    apache_path = '/etc/apache2/conf-available/about-restoration-fixture.conf'
    password_path = '/tmp/about-restoration-fixture.htpasswd'

    class NoRedirect(HTTPRedirectHandler):
        def redirect_request(self, request, fp, code, message, headers, new_url):
            return None

    def page(client, path='/app.php/about', headers=None, follow_redirects=True):
        opener = client.opener if follow_redirects else build_opener(HTTPCookieProcessor(jar(client)), NoRedirect())
        try:
            response = opener.open(Request(harness.base + path, headers=headers or {}))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    def jar(client):
        return next(handler.cookiejar for handler in client.opener.handlers if hasattr(handler, 'cookiejar'))

    def confirmed_cookie(headers, name):
        cookies = [value for value in headers.get_all('Set-Cookie', []) if value.startswith(name + '=')]
        return len(cookies) == 1 and 'HttpOnly' in cookies[0] and 'SameSite=Strict' in cookies[0]

    def persist(client, actor, marker):
        credential = next(cookie.value for cookie in jar(client) if cookie.name == 'Cacti')
        check(re.fullmatch(r'[a-zA-Z0-9,-]{22,256}', credential) is not None,
              marker + ' uses a native generated session ID')
        if database_sessions:
            stored = harness.sql(f"SELECT user_id FROM sessions WHERE id='{credential}'").strip()
            check(stored == str(actor), marker + ' persists the native database credential')
        else:
            result = harness.php('-r', '$directory=ini_get("session.save_path") ?: sys_get_temp_dir(); $file=$directory."/sess_' + credential + '"; print is_file($file) && str_contains(file_get_contents($file),"sess_user_id|i:' + str(actor) + ';") ? "confirmed" : "missing";')
            check(result['exit'] == 0 and result['stdout'] == 'confirmed',
                  marker + ' persists the native file credential')
        check(page(client)[0] == 200 and client.request('/app.php/session')['status'] == 401,
              marker + ' resumes About without granting console realm 8')
        return credential

    try:
        for name in names:
            check(harness.sql(f"SELECT COUNT(*) FROM user_auth WHERE username='{name}'").strip() == '0',
                  'About authentication fixture owns a fresh account: ' + name)
        for name in policy:
            exists = harness.sql(f"SELECT COUNT(*) FROM settings WHERE name='{name}'").strip() == '1'
            value = harness.sql(f"SELECT HEX(value) FROM settings WHERE name='{name}'").strip() if exists else ''
            if not re.fullmatch('[0-9A-F]*', value):
                raise RuntimeError('Invalid saved authentication-policy representation')
            saved[name] = (exists, value)
        for name, realm in zip(names, (2, 0)):
            harness.sql(f"INSERT INTO user_auth (username,password,realm,enabled,locked) VALUES ('{name}','',{realm},'on','')")
            created.append(int(harness.sql(f"SELECT id FROM user_auth WHERE username='{name}' AND realm={realm}").strip()))
        basic_id, remember_id = created
        ip = harness.sql(f"SELECT ip FROM user_log WHERE user_id={admin_id} ORDER BY time DESC LIMIT 1").strip()
        ipaddress.ip_address(ip)
        check(bool(ip), 'About remembered fixture uses the actual native login client address')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('auth_method','2'),('auth_cache_enabled','on'),('force_https',''),('guest_user','0')")
        password = secrets.token_hex(24)
        apache_configuration = '''<LocationMatch "^/about\\.php$">
AuthType Basic
AuthName "About restoration fixture"
AuthBasicProvider file
AuthUserFile /tmp/about-restoration-fixture.htpasswd
Require valid-user
</LocationMatch>
'''
        apache_code = ('$fixture=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);'
                       + 'file_put_contents($fixture["password_path"],$fixture["username"].":".password_hash($fixture["password"],PASSWORD_BCRYPT)."\\n");'
                       + 'file_put_contents($fixture["apache_path"],$fixture["configuration"]);')
        harness.compose('exec', '-T', '-u', 'root', 'web', 'php', '-d', 'auto_prepend_file=', '-r', apache_code,
                        data=json.dumps({'password_path': password_path, 'apache_path': apache_path,
                                         'username': names[0], 'password': password,
                                         'configuration': apache_configuration}))
        harness.compose('exec', '-T', '-u', 'root', 'web', 'a2enconf', 'about-restoration-fixture')
        apache_enabled = True
        harness.compose('exec', '-T', '-u', 'root', 'web', 'apache2ctl', 'configtest')
        harness.compose('exec', '-T', '-u', 'root', 'web', 'apache2ctl', '-k', 'graceful')
        time.sleep(1)
        basic = {'Authorization': 'Basic ' + base64.b64encode((names[0] + ':' + password).encode()).decode()}
        invalid = {'Authorization': 'Basic ' + base64.b64encode((names[0] + ':invalid').encode()).decode()}
        check(page(Session(harness.base), '/app.php/about', basic)[0] == 401
              and page(Session(harness.base), '/app.php/about', invalid)[0] == 401,
              'About unprotected Basic headers cannot establish a web-server principal')
        check(page(Session(harness.base), '/about.php', invalid)[0] == 401,
              'About Basic identity is verified by Apache before PHP')
        client = Session(harness.base)
        initial_status, _, headers = page(client, '/about.php', basic, follow_redirects=False)
        destination = headers.get('Location', '')
        status, body, _ = page(client)
        check(initial_status == 302 and destination.endswith('/app.php/about')
              and status == 200 and 'About Kadupul' in body,
              'About first Basic request restores native identity through the legacy forwarder')
        check(confirmed_cookie(headers, 'Cacti'),
              'About Basic transition publishes a native credential cookie')
        persist(client, basic_id, 'About Basic restoration')
        harness.sql(f"UPDATE user_auth SET locked='on' WHERE id={basic_id}")
        check(page(client)[0] == 401, 'About restored Basic session refuses a revoked account')
        # Remove the fixture-only Apache guard before ordinary legacy assertions.
        harness.compose('exec', '-T', '-u', 'root', 'web', 'a2disconf', 'about-restoration-fixture')
        apache_enabled = False
        harness.compose('exec', '-T', '-u', 'root', 'web', 'apache2ctl', '-k', 'graceful')
        time.sleep(1)
        harness.sql("REPLACE INTO settings (name,value) VALUES ('auth_method','1')")
        token = secrets.token_hex(32)
        digest = hashlib.sha512(token.encode()).hexdigest()
        harness.sql(f"INSERT INTO user_auth_cache (user_id,hostname,token) VALUES ({remember_id},'{ip}','{digest}')")
        remembered = {'Cookie': f'cacti_remembers={remember_id},0,{token}'}
        client = Session(harness.base)
        status, body, headers = page(client, headers=remembered)
        check(status == 200 and 'About Kadupul' in body,
              'About first remembered request restores the native cookie identity')
        check(confirmed_cookie(headers, 'Cacti') and confirmed_cookie(headers, 'cacti_remembers'),
              'About remembered transition publishes protected session and replacement cookies')
        persist(client, remember_id, 'About remembered restoration')
        replacement = next(cookie.value for cookie in jar(client) if cookie.name == 'cacti_remembers')
        raw_replacement = unquote(replacement)
        replacement_hash = hashlib.sha512(raw_replacement.split(',')[-1].encode()).hexdigest()
        check(harness.sql(f"SELECT COUNT(*) FROM user_auth_cache WHERE user_id={remember_id} AND token='{digest}'").strip() == '0'
              and harness.sql(f"SELECT COUNT(*) FROM user_auth_cache WHERE user_id={remember_id} AND token='{replacement_hash}' AND hostname='{ip}'").strip() == '1',
              'About remembered restoration consumes and rotates the exact native token')
        check(page(Session(harness.base), headers=remembered)[0] == 401,
              'About consumed remembered token cannot be replayed')
        check(page(Session(harness.base), headers={'Cookie': 'cacti_remembers=' + replacement})[0] == 200,
              'About replacement remembered token establishes a fresh native session')
        harness.sql(f"UPDATE user_auth SET enabled='' WHERE id={remember_id}")
        check(page(client)[0] == 401, 'About restored remembered session refuses a disabled account')
    finally:
        if apache_enabled:
            harness.compose('exec', '-T', '-u', 'root', 'web', 'a2disconf', 'about-restoration-fixture')
            harness.compose('exec', '-T', '-u', 'root', 'web', 'apache2ctl', '-k', 'graceful')
            time.sleep(1)
        harness.compose('exec', '-T', '-u', 'root', 'web', 'rm', '-f', apache_path, password_path)
        for name, (exists, value) in saved.items():
            if exists:
                harness.sql(f"REPLACE INTO settings (name,value) VALUES ('{name}',UNHEX('{value}'))")
            else:
                harness.sql(f"DELETE FROM settings WHERE name='{name}'")
        for actor in created:
            harness.sql(f'DELETE FROM user_auth_cache WHERE user_id={actor}; DELETE FROM sessions WHERE user_id={actor}; DELETE FROM user_log WHERE user_id={actor}; DELETE FROM user_auth WHERE id={actor}')
