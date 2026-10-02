# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Exercise real first-request credential restoration through the About kernel."""
import base64
import hashlib
import http.cookiejar
import http.client
import os
from pathlib import Path
import secrets
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import urllib.parse

ROOT = Path(__file__).resolve().parents[2]
FIXTURE = Path(__file__).parent / 'Fixtures/about_native_authentication.php'


def request(opener, base, headers=None, expected=200, path='/about'):
    try:
        response = opener.open(urllib.request.Request(base + path, headers=headers or {}), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode()
    assert response.status == expected, f'Expected HTTP{expected}, actual HTTP{response.status}'
    if expected == 200:
        assert 'About Kadupul' in body
    response.fixture_body = body
    return response


for storage in ('files', 'database'):
    with tempfile.TemporaryDirectory(prefix='about-native-authentication-') as directory:
        temporary = Path(directory)
        database = temporary / 'database.sqlite'
        connection = sqlite3.connect(database)
        connection.executescript('''
            CREATE TABLE settings (name TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT);
            INSERT INTO settings VALUES ('auth_method','2'),('auth_cache_enabled','on'),('guest_user','3'),('force_https','');
            CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT);
            CREATE TABLE user_auth_cache (id INTEGER PRIMARY KEY, user_id INTEGER, hostname TEXT, last_update TEXT DEFAULT CURRENT_TIMESTAMP, token TEXT);
            CREATE TABLE sessions (id TEXT PRIMARY KEY, remote_addr TEXT, access INTEGER, data BLOB, user_id INTEGER, user_agent TEXT, start_time TEXT DEFAULT CURRENT_TIMESTAMP, transactions INTEGER DEFAULT 1);
            CREATE TABLE user_log (username TEXT, user_id INTEGER, result INTEGER, ip TEXT, time TEXT, PRIMARY KEY (username,user_id,time));
        ''')
        password = secrets.token_hex(16)
        password_hash = subprocess.check_output(['mise', 'exec', 'php@8.4.25', '--', 'php', '-r', 'print password_hash($argv[1], PASSWORD_DEFAULT);', password], text=True)
        connection.executemany('INSERT INTO user_auth VALUES (?,?,?,?,?,?)', [(1, 'about-basic', 2, 'on', '', password_hash), (2, 'about-remember', 0, 'on', '', password_hash), (3, 'guest', 0, 'on', '', password_hash)])
        token = secrets.token_hex(32)
        connection.execute('INSERT INTO user_auth_cache(user_id,hostname,token) VALUES (?,?,?)', (2, '127.0.0.1', hashlib.sha512(token.encode()).hexdigest()))
        connection.commit()
        with socket.socket() as probe:
            probe.bind(('127.0.0.1', 0))
            port = probe.getsockname()[1]
        environment = dict(os.environ, ABOUT_REPRO_DATABASE=str(database), ABOUT_REPRO_STORAGE=storage)
        with open(temporary / 'server.log', 'w') as log:
            process = subprocess.Popen(['mise', 'exec', 'php@8.4.25', '--', 'php', '-d', 'session.save_path=' + directory, '-S', '127.0.0.1:' + str(port), str(FIXTURE)], cwd=ROOT, env=environment, stdout=log, stderr=log)
            base = 'http://127.0.0.1:' + str(port)
            try:
                for attempt in range(100):
                    if process.poll() is not None:
                        raise RuntimeError('Native HTTP fixture exited early')
                    health = http.client.HTTPConnection('127.0.0.1', port, timeout=1)
                    try:
                        health.request('GET', '/__health')
                        response = health.getresponse()
                        if response.status == 200 and response.read() == b'ready':
                            break
                    except (OSError, http.client.HTTPException):
                        pass
                    finally:
                        health.close()
                    time.sleep(0.05)
                else:
                    raise RuntimeError('Native HTTP fixture did not start')
                basic = {'Authorization': 'Basic ' + base64.b64encode(('about-basic:' + password).encode()).decode()}
                for mode, headers, actor in [('basic', basic, 1), ('remember', {'Cookie': 'cacti_remembers=2,0,' + token}, 2)]:
                    connection.execute("UPDATE settings SET value=? WHERE name='auth_method'", ('2' if mode == 'basic' else '1',))
                    connection.commit()
                    jar = http.cookiejar.CookieJar()
                    browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
                    response = request(browser, base, headers)
                    session = next(cookie for cookie in jar if cookie.name == 'AboutFixture')
                    assert len(session.value) >= 22 and session.value != token
                    cookie_headers = response.headers.get_all('Set-Cookie') or []
                    assert any('HttpOnly' in value and 'SameSite=Strict' in value and 'path=/' in value.lower() for value in cookie_headers)
                    if storage == 'database':
                        row = connection.execute('SELECT user_id,data FROM sessions WHERE id=?', (session.value,)).fetchone()
                        assert row and row[0] == actor and f'sess_user_id|i:{actor};' in row[1]
                    else:
                        payload = (temporary / ('sess_' + session.value)).read_text()
                        assert f'sess_user_id|i:{actor};' in payload
                    request(browser, base)  # Resume persisted native session without credentials.
                    request(browser, base, path='/about/legacy')
                    assert connection.execute('SELECT COUNT(*) FROM user_log WHERE user_id=?', (actor,)).fetchone()[0] == 1
                    if mode == 'remember':
                        replacement = next(cookie for cookie in jar if cookie.name == 'cacti_remembers')
                        assert replacement.value != '2,0,' + token
                        cached = connection.execute('SELECT token FROM user_auth_cache WHERE user_id=2').fetchall()
                        assert cached == [(hashlib.sha512(urllib.parse.unquote(replacement.value).split(',')[-1].encode()).hexdigest(),)]
                        request(urllib.request.build_opener(), base, headers, 401)  # Consumed token cannot replay.
                        request(urllib.request.build_opener(), base, {'Cookie': 'cacti_remembers=' + replacement.value})
                    connection.execute('UPDATE user_auth SET enabled=? WHERE id=?', ('', actor))
                    connection.commit()
                    request(browser, base, expected=401)
                    connection.execute('UPDATE user_auth SET enabled=? WHERE id=?', ('on', actor))
                    connection.commit()
                    print(storage + ':' + mode + ': native restoration/persistence/resume/revocation passed')
                connection.execute("UPDATE settings SET value='2' WHERE name='auth_method'")
                connection.commit()
                request(urllib.request.build_opener(), base, basic, 401, '/__unprotected/about')
                request(urllib.request.build_opener(), base, {'Remote-User': 'about-basic', 'X-Remote-User': 'about-basic'}, 401)
                request(urllib.request.build_opener(), base, {'Authorization': 'Basic ' + base64.b64encode(b'about-basic:invalid').decode()}, 401)
                connection.execute("UPDATE settings SET value='on' WHERE name='force_https'")
                connection.commit()
                request(urllib.request.build_opener(), base, basic, 403)
                print(storage + ': forged identity/invalid password/HTTPS refusal passed')
                connection.execute("UPDATE settings SET value='' WHERE name='force_https'")
                connection.commit()
                for failure in ('rollback_late', 'rollback_unknown', 'rollback_audit', 'rollback', 'commit_late', 'commit_unknown', 'commit_before', 'commit_after', 'cache_ignore', 'user_log_ignore', 'user_log', 'audit', 'audit_storage', 'sessions') if storage == 'database' else ('rollback_late', 'rollback_unknown', 'rollback_audit', 'rollback', 'commit_late', 'commit_unknown', 'commit_before', 'commit_after', 'cache_ignore', 'user_log_ignore', 'user_log', 'audit'):
                    connection.execute('DELETE FROM user_log')
                    previous_sessions = connection.execute('SELECT id FROM sessions ORDER BY id').fetchall()
                    previous_files = {path.name: path.read_bytes() for path in temporary.glob('sess_*')}
                    headers = basic
                    connection.execute("UPDATE settings SET value=? WHERE name='auth_method'", ('1' if failure == 'cache_ignore' else '2',))
                    if failure in ('rollback', 'rollback_audit', 'rollback_late', 'rollback_unknown'):
                        connection.execute("INSERT INTO settings VALUES ('fixture_rollback_failure',?)", ('late' if failure == 'rollback_late' else 'unknown' if failure == 'rollback_unknown' else 'on',))
                        if failure == 'rollback_audit':
                            connection.execute("INSERT INTO settings VALUES ('fixture_failure_audit_failure','on')")
                        connection.execute("CREATE TRIGGER refuse_rollback BEFORE INSERT ON user_log BEGIN SELECT RAISE(ABORT, 'fixture persistence refusal'); END")
                    elif failure.startswith('commit_'):
                        connection.execute("INSERT INTO settings VALUES ('fixture_commit_failure',?)", (failure.split('_')[1],))
                    elif failure == 'cache_ignore':
                        failure_token = secrets.token_hex(32)
                        connection.execute('INSERT INTO user_auth_cache(user_id,hostname,token) VALUES (?,?,?)', (2, '127.0.0.1', hashlib.sha512(failure_token.encode()).hexdigest()))
                        connection.execute('CREATE TRIGGER refuse_cache_ignore BEFORE INSERT ON user_auth_cache BEGIN SELECT RAISE(IGNORE); END')
                        headers = {'Cookie': 'cacti_remembers=2,0,' + failure_token}
                    elif failure in ('audit', 'audit_storage'):
                        connection.execute("INSERT INTO settings VALUES ('fixture_audit_failure','on')")
                        if failure == 'audit_storage':
                            connection.execute("CREATE TRIGGER refuse_credential_cleanup BEFORE DELETE ON sessions BEGIN SELECT RAISE(ABORT, 'fixture cleanup refusal'); END")
                    elif failure == 'user_log_ignore':
                        connection.execute('CREATE TRIGGER refuse_user_log_ignore BEFORE INSERT ON user_log BEGIN SELECT RAISE(IGNORE); END')
                    else:
                        connection.execute(f"CREATE TRIGGER refuse_{failure} BEFORE INSERT ON {failure} BEGIN SELECT RAISE(ABORT, 'fixture persistence refusal'); END")
                    connection.commit()
                    previous_cache = connection.execute('SELECT id,token FROM user_auth_cache ORDER BY id').fetchall()
                    failing_jar = http.cookiejar.CookieJar()
                    failing_browser = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(failing_jar))
                    failure_response = request(failing_browser, base, headers, 500)
                    if failure == 'rollback_audit':
                        assert 'Browser authentication cleanup was not confirmed.' in failure_response.fixture_body
                    session_headers = [value for value in failure_response.headers.get_all('Set-Cookie', []) if value.startswith('AboutFixture=')]
                    for value in failure_response.headers.get_all('Set-Cookie', []):
                        if value.startswith(('AboutFixture=', 'cacti_remembers=')):
                            assert 'Max-Age=0' in value, 'Failed transition exposed a live credential cookie.'
                    if failure != 'cache_ignore':
                        assert session_headers and 'Max-Age=0' in session_headers[-1]
                    assert connection.execute('SELECT id,token FROM user_auth_cache ORDER BY id').fetchall() == previous_cache
                    assert not any(cookie.name in ('AboutFixture', 'cacti_remembers') for cookie in failing_jar)
                    # No new credential is published before commit and audit.
                    current_sessions = connection.execute('SELECT id FROM sessions ORDER BY id').fetchall()
                    if failure == 'audit_storage':
                        assert len(current_sessions) == len(previous_sessions) + 1
                    else:
                        assert current_sessions == previous_sessions
                    assert {path.name: path.read_bytes() for path in temporary.glob('sess_*')} == previous_files
                    request(failing_browser, base, expected=401)
                    if failure in ('rollback', 'rollback_audit', 'rollback_late', 'rollback_unknown'):
                        connection.execute("DELETE FROM settings WHERE name IN ('fixture_rollback_failure','fixture_failure_audit_failure')")
                        connection.execute('DROP TRIGGER refuse_rollback')
                    elif failure.startswith('commit_'):
                        connection.execute("DELETE FROM settings WHERE name='fixture_commit_failure'")
                    elif failure in ('audit', 'audit_storage'):
                        connection.execute("DELETE FROM settings WHERE name='fixture_audit_failure'")
                        if failure == 'audit_storage':
                            connection.execute('DROP TRIGGER refuse_credential_cleanup')
                            for row in current_sessions:
                                if row not in previous_sessions:
                                    connection.execute('DELETE FROM sessions WHERE id=?', row)
                    else:
                        connection.execute(f'DROP TRIGGER refuse_{failure}')
                    connection.commit()
                    if failure == 'audit_storage':
                        print(storage + ':' + failure + ': storage cleanup uncertain; no credential was published')
                    else:
                        print(storage + ':' + failure + ': failed restoration leaves no new native credential')

            except Exception:
                print((temporary / 'server.log').read_text())
                raise
            finally:
                process.terminate()
                process.wait(timeout=10)
                connection.close()
