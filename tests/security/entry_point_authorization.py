# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Request every inventoried entry point over real HTTP and require refusal.

The install comes from the behavioral harness: MariaDB, Apache mod_php and the
real installer in Docker. Three callers are used: anonymous, a signed-in local
account whose realms were all revoked, and a local account with only Console
Access (realm 8). Every
request is a GET or an empty POST with no action parameter, so nothing is
created, changed or deleted even when a gate is missing.
"""
import argparse
import http.cookiejar
import re
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BASELINE = ROOT / 'tests/security/baselines/entry_points.baseline.tsv'
sys.path.insert(0, str(ROOT / 'tests/Support/Behavior'))
import harness  # noqa: E402

PASSWORD = 'entry-auth-sweep'
CONSOLE_REALM = 8
PROFILE_REALM = 20
LOGIN_TARGETS = ('', 'index.php', 'auth_login.php')

# The observed refusal contracts. auth.php renders the login form in place
# (HTTP 200) for anonymous callers and the Permission Denied page (HTTP 200)
# for accounts without the realm, retitled Installation In Progress for realm
# 26. graph_json.php and graph_image.php answer anonymous callers in JSON and
# text. Symfony answers 401 or 403.
REFUSALS = [
    ('login-form', lambda r: 'login_username' in r['body']),
    ('permission-denied', lambda r: 'You are not permitted to access this section' in r['body']),
    ('install-denied', lambda r: 'Only Kadupul Administrators with Install/Upgrade privilege' in r['body']),
    ('json-not-logged-in', lambda r: '"statusText":"Not Logged In"' in r['body']),
    ('text-not-logged-in', lambda r: 'You must be logged in to access this area' in r['body']),
    # A redirect only refuses when it lands on the login entry; any other
    # target could be a success page.
    ('redirect', lambda r: r['status'] in (301, 302, 303) and not r['admin_layout']
        and urllib.parse.urlsplit(r['location']).path.rsplit('/', 1)[-1] in LOGIN_TARGETS),
    ('status', lambda r: r['status'] in (401, 403, 404, 405) and not r['admin_layout']),
]

# Paths whose operation segment names a mutation get GET only, even though an
# empty POST cannot submit their forms.
MUTATING_SEGMENTS = re.compile(r'\{operation\}')


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())
        self.token = None

    def request(self, path, fields=None):
        data = None
        if fields is not None:
            fields = dict(fields)
            if self.token:
                fields['__csrf_magic'] = self.token
            data = urllib.parse.urlencode(fields).encode()
        try:
            response = self.opener.open(self.base + '/' + path, data=data, timeout=60)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode('utf-8', errors='replace')
        token = re.search(r"name=['\"]__csrf_magic['\"] value=['\"]([^'\"]+)", body) \
            or re.search(r"csrfMagicToken\s*=\s*['\"]([^'\"]+)", body)
        if token:
            self.token = token[1]
        return {'status': response.status, 'location': response.headers.get('Location') or '', 'body': body,
                'admin_layout': bool(re.search(r"(?:id=['\"]main_logo|class=['\"]cactiPageHead)", body))}

    def login(self, username, password):
        self.request('index.php')
        result = self.request('index.php', {'action': 'login', 'login_username': username,
                                            'login_password': password, 'realm': 'local'})
        if 'login_username' in result['body'] and result['status'] == 200:
            raise RuntimeError('Login failed for ' + username)
        return result


def refusal(response):
    return next((name for name, test in REFUSALS if test(response)), None)


def sample(entry, detail):
    """Concrete URL for a route template; numeric ids use 1."""
    requirements = dict(re.findall(r'(\w+)=([\w|]+)', detail.split('requirements=', 1)[1].split(';')[0])) if 'requirements=' in detail else {}
    return re.sub(r'\{(\w+)\}', lambda m: requirements[m[1]].split('|')[0] if m[1] in requirements else '1', entry)


def entries():
    rows = [line.split('\t') for line in BASELINE.read_text().splitlines()[1:] if line.strip()]
    return [(entry, gate, detail) for entry, gate, detail in rows]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', default='kadupul-entry-auth')
    parser.add_argument('--keep', action='store_true')
    args = parser.parse_args()

    started = time.monotonic()
    rig = harness.Harness(argparse.Namespace(target='entry-auth', project=args.project))
    failures, observed = [], {}

    def expect(label, response):
        verdict = refusal(response)
        contract = verdict or 'NOT REFUSED'
        if verdict in ('status', 'redirect'):
            contract += ':%d' % response['status']
        if verdict == 'redirect':
            contract += ' to ' + urllib.parse.urlsplit(response['location']).path.rsplit('/', 1)[-1]
        observed.setdefault(' '.join(label.split(' ', 2)[:2]) + ' ' + contract, []).append(label.split(' ', 2)[2])
        if verdict is None:
            failures.append('%s: not refused (HTTP %d%s)' % (label, response['status'], ', admin layout' if response['admin_layout'] else ''))
        return verdict

    try:
        rig.setup()
        base = rig.base
        setup_done = time.monotonic()
        # Login refuses an account with no realm at all ("does not have access
        # to any area"), so the no-realm caller signs in with Update Profile
        # and loses it afterwards, the way user_admin.php revokes a grant.
        for username, realms in (('entry-norealm', [PROFILE_REALM]), ('entry-console', [CONSOLE_REALM])):
            copy = rig.php('cli/copy_user.php', 'admin', username)
            if copy['exit']:
                raise RuntimeError('copy_user.php failed: ' + copy['stdout'] + copy['stderr'])
            hashed = rig.php('-r', 'echo password_hash("' + PASSWORD + '", PASSWORD_BCRYPT);')['stdout'].strip()
            rig.sql("SET @id = (SELECT id FROM user_auth WHERE username = '%s');"
                    "UPDATE user_auth SET password = '%s', must_change_password = '', password_change = '', enabled = 'on' WHERE id = @id;"
                    "DELETE FROM user_auth_realm WHERE user_id = @id;"
                    "DELETE FROM user_auth_group_members WHERE user_id = @id;%s"
                    % (username, hashed, ''.join('INSERT INTO user_auth_realm (realm_id, user_id) VALUES (%d, @id);' % r for r in realms)))
        # One external link so link.php reaches its own realm check instead of
        # the missing-page redirect.
        rig.sql("INSERT INTO external_links (id, sortorder, enabled, contentfile, title, style) VALUES (1, 1, 'on', 'basic-example.html', 'Sweep', 'CONSOLE');")
        guest = rig.sql("SELECT value FROM settings WHERE name = 'guest_user'").strip()

        anonymous = Client(base)
        norealm, console = Client(base), Client(base)
        norealm.login('entry-norealm', PASSWORD)
        rig.sql("SET @id = (SELECT id FROM user_auth WHERE username = 'entry-norealm');"
                "DELETE FROM user_auth_realm WHERE user_id = @id;"
                "UPDATE user_auth SET reset_perms = reset_perms + 1 WHERE id = @id;")
        if rig.sql("SELECT COUNT(*) FROM user_auth_realm r JOIN user_auth u ON u.id = r.user_id WHERE u.username = 'entry-norealm'").strip() != '0':
            raise RuntimeError('entry-norealm still holds a realm')
        console.login('entry-console', PASSWORD)
        admin = Client(base)
        admin.login('admin', 'behavior-admin')

        # Positive controls: a refusal only means something if the same session
        # can be admitted where it holds the grant.
        controls = [
            ('admin index.php', admin.request('index.php'), lambda r: r['admin_layout']),
            ('norealm about.php', norealm.request('about.php'), lambda r: r['status'] == 200 and refusal(r) is None),
            ('console index.php', console.request('index.php'), lambda r: r['status'] == 200 and refusal(r) is None),
            ('console app.php/session', console.request('app.php/session'), lambda r: r['status'] == 200),
        ]
        for label, response, test in controls:
            if not test(response):
                failures.append('control %s: expected admission, got HTTP %d' % (label, response['status']))

        tracked = harness.run(['git', '-C', str(ROOT), 'ls-files', '*.php'])['stdout'].split()
        counted = 0
        rows = entries()
        routes = {entry: (gate, detail) for entry, gate, detail in rows}
        for entry, gate, detail in rows:
            if gate == 'symfony:forward':
                # A compatibility forwarder carries its target route's gate.
                gate, detail = routes['app.php' + detail.rsplit('app.php', 1)[1]]
            protected = gate.startswith('realm:') or gate == 'authenticated' or (gate.startswith('symfony:') and 'ConsoleAccess' in detail)
            url = entry + ('?id=1' if entry == 'link.php' else '')
            if entry.startswith('app.php/'):
                url = sample(entry, detail)
            post = not gate.startswith('symfony:') or ('POST' in detail.split(';')[0] and not MUTATING_SEGMENTS.search(entry))

            if protected:
                counted += 1
                expect('anonymous GET ' + url, anonymous.request(url))
                if url.startswith('app.php/'):
                    # Same kernel, other front controller.
                    expect('anonymous GET public/index.php' + url[7:], anonymous.request('public/index.php' + url[7:]))
                if post:
                    expect('anonymous POST ' + url, anonymous.request(url, {}))
                if gate == 'authenticated':
                    continue
                for name, client in (('norealm', norealm), ('console', console)):
                    if name == 'console' and (gate == 'realm:%d' % CONSOLE_REALM or 'ConsoleAccess realm 8;' in detail + ';'):
                        continue
                    expect(name + ' GET ' + url, client.request(url))
                    if post:
                        expect(name + ' POST ' + url, client.request(url, {}))
            elif gate == 'cli-only':
                counted += 1
                response = anonymous.request(url)
                body = response['body'].strip()
                # mod_php echoes a shebang line. script_server.php parses its
                # arguments before the guard and dies there with HTTP 500.
                if not ((response['status'] in (404, 500) and body in ('', '#!/usr/bin/env php'))
                        or 'only meant to run at the command line' in body):
                    failures.append('%s: CLI entry answered HTTP %d with %d bytes' % (url, response['status'], len(body)))
                observed.setdefault('cli-only status:%d' % response['status'], []).append(url)
            elif gate == 'web-server-denied':
                inside = [f for f in tracked if f.startswith(entry)] if entry.endswith('/') else []
                probe = inside[0] if inside else (entry + 'index.php' if entry.endswith('/') else entry)
                response = anonymous.request(probe)
                if detail != 'nginx; apache .htaccess':
                    # Nginx denies these; nginx_private_paths.py covers that.
                    # Apache has no matching deny, so record what it does.
                    observed.setdefault('apache-parity-gap status:%d' % response['status'], []).append(probe)
                    continue
                counted += 1
                if response['status'] not in (403, 404):
                    failures.append('%s: denied path answered HTTP %d' % (probe, response['status']))
                observed.setdefault('web-server-denied status:%d' % response['status'], []).append(probe)
            elif gate == 'ungated':
                # Requesting it runs the finding; it is reported, not exercised.
                observed.setdefault('ungated (not requested)', []).append(entry)
            elif gate.startswith('symfony:') or gate == 'anonymous-allowed':
                counted += 1
                response = anonymous.request(url)
                body = response['body'].strip()
                if detail.startswith(('inert', 'fragment')):
                    # Without the bootstrap these either declare symbols or stop
                    # at the first undefined function; neither may emit content.
                    if response['admin_layout'] or (response['status'] == 200 and body):
                        failures.append('%s: %s answered HTTP %d with %d bytes' % (url, detail.split(':')[0].split(' ')[0], response['status'], len(body)))
                elif detail == 'redirect-only' and response['status'] not in (301, 302, 303):
                    failures.append('%s: redirect stub answered HTTP %d' % (url, response['status']))
                elif entry == 'remote_agent.php' and 'Client authorization failed' not in body:
                    failures.append('remote_agent.php: unregistered client was not refused')
                elif response['admin_layout']:
                    failures.append('%s: anonymous request rendered the console' % url)
                observed.setdefault('anonymous-allowed status:%d' % response['status'], []).append(url)
            else:
                failures.append('%s: gate %s has no expectation in this suite' % (entry, gate))

        finished = time.monotonic()
        for key in sorted(observed):
            print('%-48s %4d  %s' % (key, len(observed[key]), ' '.join(observed[key][:4])))
        print('guest_user setting: %r' % guest)
        print('entries exercised: %d; setup %.0fs; sweep %.0fs' % (counted, setup_done - started, finished - setup_done))
    finally:
        if not args.keep:
            rig.compose('down', '--volumes', '--remove-orphans', check=False, timeout=120)

    for failure in failures:
        print('FAIL ' + failure)
    if failures:
        return 1
    print('PASS negative authorization sweep')
    return 0


if __name__ == '__main__':
    sys.exit(main())
