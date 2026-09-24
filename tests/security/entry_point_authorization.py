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

# Written wherever a denied path has no file in the image, so a 403 is Apache's
# deny and not a missing file, and a served canary shows in the body.
CANARY = 'kadupul-entry-canary'
WEBROOT = '/var/www/html/'

# update_hash.php rewrites stylesheets under here when it runs.
THEME = 'include/themes/midwinter'

# The root rules ship in .htaccess.dist and apply only once it is renamed.
OPT_IN = 'nginx; apache: opt-in via .htaccess.dist'
# The nested .well-known checks that the ACME exception is anchored at the root.
OPT_IN_DENIED = ('composer.json', '.git/config', 'plugins/kadupul-entry/.well-known/canary.txt')
OPT_IN_SERVED = '.well-known/kadupul-entry-canary.txt'

# Denied by default without a baseline row of their own: static docs, which
# Nginx denies with the rest of docs/, and a bootstrap name in another case.
DENIED_EXTRA = ('docs/kadupul-entry-canary.html', 'include/Config.php')

# CLI tools with no bootstrap of their own, so a request reaches their guard.
CLI_TOOLS = ('include/themes/midwinter/update_hash.php', 'script_server.php')


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


def cli_refused(response):
    # mod_php echoes a shebang line ahead of the guard.
    body = response['body'].strip()
    return (response['status'] == 404 and body in ('', '#!/usr/bin/env php')) \
        or 'only meant to run at the command line' in body


def denied_probe(entry):
    # Directly inside the denied directory, so a deny in a subdirectory
    # cannot stand in for a missing one on the directory itself.
    return entry + 'index.php' if entry.endswith('/') else entry


def stage_denied_paths(rig, rows):
    """Put every tracked .htaccess and a file at every denied probe into the image.

    .dockerignore drops cache/, docs/, tests/ and .php-cs-fixer.php, so without
    this those rows would answer 404 whether or not Apache denies them.
    """
    for htaccess in harness.run(['git', '-C', str(ROOT), 'ls-files', '*.htaccess'])['stdout'].split():
        rig.compose('exec', '-T', '-u', 'www-data', 'web', 'sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"',
                    'sh', WEBROOT + htaccess, data=(ROOT / htaccess).read_text())
    canaries = [denied_probe(entry) for entry, gate, _ in rows if gate == 'web-server-denied']
    canaries += DENIED_EXTRA
    for path in canaries:
        rig.command('sh', '-c', 'test -e "$1" || { mkdir -p "$(dirname "$1")" && printf "%s" "$2" > "$1"; }',
                    'sh', WEBROOT + path, ('<?php print "%s";' % CANARY) if path.endswith('.php') else CANARY, check=True)


def theme_css_digest(rig):
    digest = rig.command('sh', '-c', 'cd "$1" && find . -name "*.css" | LC_ALL=C sort | xargs sha256sum',
                         'sh', WEBROOT + THEME, check=True)['stdout']
    if 'main.css' not in digest:
        raise RuntimeError('theme stylesheets not found in ' + THEME)
    return digest


def page_assets(body):
    """Same-origin scripts and stylesheets a page loads, without cache busters."""
    urls = re.findall(r'<(?:script[^>]+src|link[^>]+href)=[\'"]([^\'"]+\.(?:js|css))(?:\?[^\'"]*)?[\'"]', body)
    return sorted({urllib.parse.urlsplit(u).path.lstrip('/') for u in urls if not urllib.parse.urlsplit(u).netloc})


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

        counted = 0
        rows = entries()
        stage_denied_paths(rig, rows)
        css_before = theme_css_digest(rig)

        # Denies must not reach what the login form and the console load.
        for name, client in (('anonymous', anonymous), ('admin', admin)):
            assets = page_assets(client.request('index.php')['body'])
            if not any(a.endswith('.js') for a in assets) or not any(a.endswith('.css') for a in assets):
                failures.append('%s index.php: no scripts or stylesheets found to check' % name)
            for asset in assets:
                response = client.request(asset)
                if response['status'] != 200:
                    failures.append('%s asset %s: HTTP %d' % (name, asset, response['status']))
            observed.setdefault('asset %s status:200' % name, []).extend(assets)

        for tool in CLI_TOOLS:
            counted += 1
            response = anonymous.request(tool)
            if not cli_refused(response):
                failures.append('%s: CLI tool answered HTTP %d with %d bytes' % (tool, response['status'], len(response['body'].strip())))
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
                if not cli_refused(response):
                    failures.append('%s: CLI entry answered HTTP %d with %d bytes' % (url, response['status'], len(response['body'].strip())))
                observed.setdefault('cli-only status:%d' % response['status'], []).append(url)
            elif gate == 'web-server-denied':
                # Nginx denies these too; nginx_private_paths.py covers that.
                probe = denied_probe(entry)
                response = anonymous.request(probe)
                if detail == OPT_IN:
                    # Not denied by default; the opt-in pass below checks it.
                    observed.setdefault('opt-in default status:%d' % response['status'], []).append(probe)
                    continue
                counted += 1
                if response['status'] != 403 or CANARY in response['body']:
                    failures.append('%s: denied path answered HTTP %d (%s)' % (probe, response['status'], detail))
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
        for path in DENIED_EXTRA:
            counted += 1
            response = anonymous.request(path)
            if response['status'] != 403 or CANARY in response['body']:
                failures.append('%s: denied path answered HTTP %d' % (path, response['status']))
            observed.setdefault('web-server-denied status:%d' % response['status'], []).append(path)

        if theme_css_digest(rig) != css_before:
            failures.append('theme CSS changed during the sweep')

        # Last, because it changes what every later request would see.
        opt_in = [denied_probe(entry) for entry, gate, detail in rows if gate == 'web-server-denied' and detail == OPT_IN]
        rig.command('sh', '-c', 'cd "$1" && cp -f .htaccess.dist .htaccess && mkdir -p .git .well-known "$(dirname "$4")" '
                    '&& { test -e .git/config || printf "%s" "$2" > .git/config; } && printf "%s" "$2" > "$3" '
                    '&& printf "%s" "$2" > "$4"',
                    'sh', WEBROOT, CANARY, OPT_IN_SERVED, OPT_IN_DENIED[-1], check=True)
        for path in opt_in + list(OPT_IN_DENIED):
            counted += 1
            response = anonymous.request(path)
            if response['status'] != 403 or CANARY in response['body']:
                failures.append('%s: answered HTTP %d with .htaccess.dist enabled' % (path, response['status']))
            observed.setdefault('opt-in enabled status:%d' % response['status'], []).append(path)
        response = anonymous.request(OPT_IN_SERVED)
        if response['status'] != 200 or CANARY not in response['body']:
            failures.append('%s: answered HTTP %d with .htaccess.dist enabled' % (OPT_IN_SERVED, response['status']))
        observed.setdefault('opt-in enabled status:%d' % response['status'], []).append(OPT_IN_SERVED)
        response = anonymous.request('index.php')
        if response['status'] != 200 or 'login_username' not in response['body']:
            failures.append('index.php: login page did not load with .htaccess.dist enabled')

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
