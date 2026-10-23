# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Permission denied page parity against real Apache, sessions and MariaDB."""
import re
import time
import urllib.request
from urllib.error import HTTPError
from urllib.request import Request

STRICT = "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
TEXT = 'You are not permitted to access this section of Kadupul.  If you feel that this is an error. Please contact your Kadupul Administrator.'


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def page(session, path, headers=None):
    """One response without following redirects, using the session's cookies."""
    cookies = next(h for h in session.opener.handlers if isinstance(h, urllib.request.HTTPCookieProcessor))
    opener = urllib.request.build_opener(cookies, NoRedirect)
    try:
        response = opener.open(Request(session.base + path, headers=headers or {}), timeout=90)
    except HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode(), response.headers


def hrefs(body, text):
    return re.findall(r'<a(?: class="returnTo")? href="([^"]*)">' + re.escape(text) + '</a>', body)


def verify_permission_denied(harness, check, prefix=''):
    from harness import Session
    for path in ('/permission_denied.php', '/app.php/permission-denied'):
        status, _, headers = page(Session(harness.base + prefix), path)
        check(status == 302 and headers.get('Location', '').endswith(prefix + '/login.php'),
              'anonymous permission denied request goes to login: ' + prefix + path)
    session = Session(harness.base + prefix)
    login = session.login('behavior-admin')
    check(login['status'] == 200 and not login['login_form'], 'permission denied fixture signs in' + (' below ' + prefix if prefix else ''))
    for path in ('/permission_denied.php', '/app.php/permission-denied', '/public/index.php/permission-denied'):
        status, body, headers = page(session, path, {'Referer': prefix + '/graph_view.php?action=tree&node=3'})
        check(status == 200 and 'You are not permitted to access this section of Kadupul.' in body,
              'permission denied renders in place: ' + prefix + path)
        check(hrefs(body, 'Return') == [prefix + '/graph_view.php?action=tree&amp;node=3'], 'local Referer becomes the Return link: ' + prefix + path)
        check(hrefs(body, 'Login Again') == [prefix + '/logout.php'], 'Login Again keeps the installation path: ' + prefix + path)
        check(re.search(re.escape(prefix) + r'/(?:public/assets/)?include/js/permission-denied', body) is not None and '<script>' not in body, 'Return behavior loads from an external script: ' + prefix + path)
        check('no-store' in headers.get('Cache-Control', '') and "'unsafe-inline'" not in headers.get('Content-Security-Policy', ''),
              'permission denied is private and under the strict policy: ' + prefix + path)
    if prefix:
        return
    for referer in ('https://foreign.invalid/graph_view.php', 'javascript:alert(1)', '//foreign.invalid/', ''):
        status, body, _ = page(session, '/permission_denied.php', {'Referer': referer})
        check(status == 200 and hrefs(body, 'Return') == ['/index.php'], 'refused Referer returns to the console: ' + repr(referer))
    status, body, _ = page(session, '/permission_denied.php')
    check(status == 200 and '<a class="returnTo" href="#">Return</a>' in body, 'without a Referer Return steps back in history')
    status, body, headers = page(session, '/permission_denied.php', {'X-Requested-With': 'XMLHttpRequest'})
    check(status == 401 and body == TEXT and 'no-store' in headers.get('Cache-Control', ''), 'AJAX request receives the native text and status')

    # An existing caller still redirects to the compatibility entry and lands on the page.
    caller = '/graph_view.php?action=tree_content&node=tree_anchor-999999'
    status, _, headers = page(session, caller)
    check(status == 302 and headers.get('Location') == 'permission_denied.php', 'existing callers keep redirecting to permission_denied.php')
    response = session.request(caller)
    check(response['status'] == 200 and response['url'] == '<BASE>/permission_denied.php' and response['title'] == 'Permission Denied',
          'caller redirect renders the Symfony page')

    try:
        for operation in ('--install', '--enable'):
            result = harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', operation)
            check(result['exit'] == 0, 'permission denied plugin lifecycle ' + operation)
        # The native API admits registration only from a setup or install function.
        registration = harness.php('-r', 'require "include/cli_check.php"; function setup_denied_hook() { api_plugin_register_hook("compatibility_test", "custom_denied", "compatibility_denied", "setup.php", true); } setup_denied_hook();')
        check(registration['exit'] == 0 and harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='compatibility_test' AND hook='custom_denied' AND status=1").strip() == '1',
              'custom_denied registered through the native plugin API')
        harness.truncate_artifacts('plugin.jsonl')
        status, body, _ = page(session, '/permission_denied.php')
        events = [row['args'] for row in harness.jsonl('/artifacts/plugin.jsonl') if row['callback'] == 'custom_denied']
        check(events == [[0, '/permission_denied.php']], 'custom_denied runs once with OPER_MODE_NATIVE for the native page path')
        check(status == 200 and 'You are not permitted' in body and 'denied-native-output' not in body,
              'a native custom_denied result keeps the core page')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('compatibility_denied_reskin','on')")
        user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        for mode in ('', 'nonce'):
            harness.sql("REPLACE INTO settings (name,value) VALUES ('content_security_policy_script','" + mode + "')")
            harness.php('-r', 'require "include/cli_check.php"; reset_user_perms((int) $argv[1]);', str(user))
            for headers in ({}, {'X-Requested-With': 'XMLHttpRequest'}):
                status, body, response = page(session, '/permission_denied.php', headers)
                check(status == 200 and 'id="denied-fixture"' in body and 'You are not permitted' not in body,
                      'custom_denied reskin replaces the page' + (' before the AJAX answer' if headers else '') + (' under nonce CSP' if mode else ''))
                check(re.search(r'<form id="denied-fixture-form"[^>]*>.*?name=.__csrf_magic.', body, re.S) is not None,
                      'reskinned page forms keep CSRF protection' + (' under nonce CSP' if mode else ''))
                policy = response.get('Content-Security-Policy', '') + response.get('Content-Security-Policy-Report-Only', '')
                if mode:
                    nonce = re.search(r'<script nonce="([A-Za-z0-9_-]+)"', body)
                    check(nonce is not None and "'nonce-" + nonce[1] + "'" in policy, 'reskinned page keeps the native policy matching its nonce')
                else:
                    check(policy not in ('', STRICT), 'reskinned page keeps the native policy instead of the Symfony one')
    finally:
        harness.sql("REPLACE INTO settings (name,value) VALUES ('content_security_policy_script','')")
        harness.sql("DELETE FROM settings WHERE name='compatibility_denied_reskin'")
        # Later scenarios seed their own compatibility_test row; leave none behind.
        harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--uninstall')
    check(harness.sql("SELECT COUNT(*) FROM plugin_config WHERE directory='compatibility_test'").strip() == '0'
          and harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='compatibility_test'").strip() == '0',
          'permission denied scenario uninstalls its fixture plugin')


def verify_permission_denied_prefix(harness, check):
    harness.command('cp', 'include/config.php', '/artifacts/denied-prefix-config.php', check=True)
    try:
        harness.command('php', '-r', 'file_put_contents("include/config.php", PHP_EOL . chr(36) . "url_path = " . var_export("/cacti/", true) . ";" . PHP_EOL, FILE_APPEND);', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', "printf 'Alias /cacti/ /var/www/html/\\n' > /etc/apache2/conf-available/denied-prefix.conf; a2enconf denied-prefix; apachectl -k graceful", check=True)
        time.sleep(3)
        verify_permission_denied(harness, check, '/cacti')
    finally:
        harness.command('cp', '/artifacts/denied-prefix-config.php', 'include/config.php', check=True)
        harness.command('rm', '-f', '/artifacts/denied-prefix-config.php', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', 'a2disconf denied-prefix; apachectl -k graceful', check=True)
        time.sleep(3)
