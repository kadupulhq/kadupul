# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later

"""Verify the console conversion through the real Apache/session/database path."""
import argparse
from pathlib import Path
from types import SimpleNamespace
import sys
import re
import json
import os
import subprocess
import time
import urllib.request
from urllib.error import HTTPError
from urllib.request import Request
from html.parser import HTMLParser

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'Support/Behavior'))
from harness import Harness, Session


class Forms(HTMLParser):
    def __init__(self):
        super().__init__()
        self.forms = {}
        self.current = None

    def handle_starttag(self, tag, attributes):
        attributes = dict(attributes)
        if tag == 'form':
            self.current = attributes.get('id')
            self.forms[self.current] = {}
        if tag == 'input' and self.current is not None:
            self.forms[self.current][attributes.get('name')] = attributes.get('value')

    def handle_endtag(self, tag):
        if tag == 'form':
            self.current = None


def verify_console_home(harness, check):
    def page(client, path='/index.php', method='GET'):
        try:
            response = client.opener.open(Request(harness.base + path, method=method), timeout=90)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    anonymous = Session(harness.base)
    response = anonymous.request('/index.php')
    check(response['status'] == 200 and response['login_form'], 'index compatibility reaches the native login form')
    session = Session(harness.base)
    response = session.login('behavior-admin')
    if response['status'] != 200 or response['login_form']:
        logs = harness.compose('logs', '--no-color', '--tail', '200', 'web')['stdout']
        diagnostics = re.findall(r'Console (?:compatibility worker failed\. phase=[a-z]+|request failed\.) class=[A-Za-z0-9_\\]+ source=[A-Za-z0-9_.-]+:[0-9]+', logs)
        raise AssertionError('Native console login failed: status=' + str(response['status']) + ' diagnostics=' + repr(diagnostics))
    check(response['status'] == 200 and not response['login_form'], 'native login returns to the Symfony console')
    for path in ('/index.php', '/app.php/console', '/public/index.php/console'):
        status, body, headers = page(session, path)
        check(status == 200 and 'You are now logged into' in body, 'Twig console renders through ' + path + ' status=' + str(status))
        check('console-home.js' in body, 'console behavior loads from an external script through ' + path)
        check('logout.php' in body and 'breadCrumbBar' in body and 'class="cactiTable"' in body,
              'console retains account controls, breadcrumbs and table layout through ' + path)
        check('no-store' in headers.get('Cache-Control', ''), 'console never caches authenticated markup through ' + path)
    check(page(session, '/index.php?ignored=%FF')[0] == 400, 'invalid UTF-8 console input is refused before starting a compatibility worker')
    check(page(session, method='HEAD')[:2] == (200, ''), 'console HEAD has no response body')
    check(page(session, '/index.php?header=false')[0] == 200 and '<html' not in page(session, '/index.php?header=false')[1],
          'console legacy header=false renders a fragment')
    check(page(session, '/index.php?headercontent=1')[0] == 200 and '<html' not in page(session, '/index.php?headercontent=1')[1],
          'console legacy headercontent renders navigation without a second document')
    for operation in ('--install', '--enable'):
        result = harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', operation)
        check(result['exit'] == 0, 'native console plugin lifecycle ' + operation)
    registration = harness.php('-r', 'require "include/cli_check.php"; function setup_console_hooks() { foreach (["page_head"=>"head", "console_before"=>"before", "console_after"=>"after", "page_bottom"=>"bottom"] as $hook=>$name) { api_plugin_register_hook("compatibility_test", $hook, "compatibility_console_" . $name, "setup.php", true); } } setup_console_hooks();')
    check(registration['exit'] == 0 and harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='compatibility_test' AND hook IN ('page_head','console_before','console_after','page_bottom') AND status=1").strip() == '4', 'console hooks registered through the native plugin API')
    content = "<?php echo '<aside id=console-file-fixture>PHP content</aside><iframe class=\"content\" src=\"" + harness.base + "/include/content/console-frame.html\"></iframe>';"
    harness.command('php', '-r', 'file_put_contents($argv[1], $argv[2]);', 'include/content/console-fixture.php', content, check=True)
    harness.command('php', '-r', 'file_put_contents($argv[1], $argv[2]);', 'include/content/console-frame.html', '<h1>Console frame fixture</h1>', check=True)
    harness.sql("INSERT INTO external_links (contentfile,title,style,enabled) VALUES ('console-fixture.php','Console fixture','FRONTTOP','on')")
    link = int(harness.sql("SELECT id FROM external_links WHERE title='Console fixture'").strip())
    user = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
    try:
        status, body, _ = page(session)
        check(status == 200 and 'console-file-fixture' not in body, 'embedded PHP content requires its own link realm')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user},{10000 + link})')
        session = Session(harness.base)
        check(session.login('behavior-admin')['status'] == 200, 'new link grant is exercised with a fresh native permission cache')
        harness.truncate_artifacts('plugin.jsonl')
        status, body, headers = page(session)
        check(status == 200 and 'console-file-fixture' in body, 'authorized embedded PHP content executes')
        check(body.index('console-fixture-before') < body.index('console-file-fixture') < body.index('console-welcome') < body.index('console-fixture-after') < body.index('console-fixture-bottom'),
              'native console hooks and embedded content retain their order')
        events = [row['callback'] for row in harness.jsonl('/artifacts/plugin.jsonl') if row['callback'].startswith('console_')]
        check(events == ['console_head', 'console_before', 'console_after', 'console_bottom'], 'native console hooks execute exactly once in order')
        visits = int(re.search(r'data-visits="(\d+)"', body)[1])
        check(f'data-visits="{visits + 1}"' in page(session)[1], 'plugin session mutations persist across Symfony requests')
        forms = Forms()
        forms.feed(body)
        check(bool(forms.forms['console-fixture-form'].get('__csrf_magic')), 'native plugin POST form retains CSRF protection')
        check('__csrf_magic' not in forms.forms['console-fixture-foreign'], 'native CSRF token is withheld from foreign plugin forms')
        check('Content-Security-Policy' in headers, 'console retains the configured native security policy')
        for mode in ('default', 'nonce'):
            harness.sql("REPLACE INTO settings(name,value) VALUES ('content_security_policy_script','" + ('' if mode == 'default' else 'nonce') + "')")
            check(harness.php('-r', 'require "include/cli_check.php"; reset_user_perms((int) $argv[1]);', str(user))['exit'] == 0, 'native console policy cache refreshed: ' + mode)
            cookies = [{'name': cookie.name, 'value': cookie.value, 'url': harness.base + '/'}
                       for handler in session.opener.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor)
                       for cookie in handler.cookiejar]
            browser = subprocess.run(['mise', 'exec', 'node@22.22.2', '--', 'node', str(Path(__file__).with_name('console_browser_probe.cjs'))],
                                     input=json.dumps({'base': harness.base, 'cookies': cookies, 'mode': mode}),
                                     capture_output=True, text=True, timeout=90)
            if browser.returncode:
                raise AssertionError('Console browser probe failed: ' + browser.stderr)
            proof = json.loads(browser.stdout)
            check(all(proof.get(key) is True for key in ('console_script_executed', 'iframe_resize_executed', 'plugin_forms_protected', 'help_navigation')),
                  'console browser executes native forms, iframe resize and help navigation under ' + mode + ' CSP')
        harness.sql("REPLACE INTO settings(name,value) VALUES ('content_security_policy_script','')")
        harness.command('php', '-r', 'file_put_contents($argv[1], $argv[2]);', 'include/content/console-fixture.php', '<?php throw new RuntimeException("private-console-diagnostic");', check=True)
        status, failed, _ = page(session)
        check(status == 503 and 'private-console-diagnostic' not in failed, 'PHP extension failure returns no private diagnostic')
        logs = harness.compose('logs', '--no-color', '--tail', '300', 'web')['stdout']
        check('Console compatibility worker failed.' in logs and 'private-console-diagnostic' not in logs, 'worker failure records only bounded diagnostic fields')
        harness.command('php', '-r', 'file_put_contents($argv[1], $argv[2]);', 'include/content/console-fixture.php', content, check=True)
        harness.sql("REPLACE INTO settings(name,value) VALUES ('hide_console','on')")
        check(harness.php('-r', 'require "include/cli_check.php"; reset_user_perms((int) $argv[1]);', str(user))['exit'] == 0, 'native settings cache invalidation runs')
        hidden = page(session)[1]
        check('console-welcome' not in hidden and 'console-fixture-before' in hidden and 'console-file-fixture' in hidden,
              'hide_console hides introduction while keeping extensions')
        harness.sql("REPLACE INTO settings(name,value) VALUES ('hide_console','')")
        harness.php('-r', 'require "include/cli_check.php"; reset_user_perms((int) $argv[1]);', str(user))
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user} AND realm_id=8')
        harness.truncate_artifacts('plugin.jsonl')
        check(page(session)[0] == 403 and not any(row['callback'].startswith('console_') for row in harness.jsonl('/artifacts/plugin.jsonl')),
              'revoked console access refuses before extension callbacks')
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user},8)')
        harness.sql("REPLACE INTO settings(name,value) VALUES ('path_php_binary','/missing/php')")
        status, failed, _ = page(session)
        check(status == 503 and 'temporarily unavailable' in failed and 'console-fixture' not in failed,
              'unavailable compatibility worker returns a controlled failure')
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, *arguments, **keywords):
                return None

        version = harness.sql('SELECT cacti FROM version LIMIT 1').strip()
        if not re.fullmatch(r'[a-zA-Z0-9_.-]+', version):
            raise RuntimeError('Invalid fixture schema version')
        original_opener = session.opener
        try:
            session.opener = urllib.request.build_opener(NoRedirect(), *[
                handler for handler in original_opener.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor)])
            harness.sql("UPDATE version SET cacti='0.0.0'")
            status, _, headers = page(session)
            check(status == 302 and headers.get('Location') == '/install/',
                  'console schema mismatch preserves the native installer redirect before worker startup')
        finally:
            harness.sql("UPDATE version SET cacti='" + version + "'")
            session.opener = original_opener
    finally:
        harness.sql(f"DELETE FROM external_links WHERE id={link}; DELETE FROM user_auth_realm WHERE realm_id={10000 + link}; REPLACE INTO user_auth_realm(user_id,realm_id) VALUES ({user},8); REPLACE INTO settings(name,value) VALUES ('path_php_binary','/usr/local/bin/php'),('hide_console',''),('content_security_policy_script','');")
        harness.command('rm', '-f', 'include/content/console-fixture.php', 'include/content/console-frame.html', check=True)
        harness.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--uninstall')
    check(page(Session(harness.base), '/app.php/help')[0] == 401, 'new help overview requires an eligible account')
    status, help_body, _ = page(session, '/app.php/help')
    check(status == 200 and 'guides/worked-example/' in help_body, 'Twig help overview uses existing documentation routes')
    harness.sql("REPLACE INTO settings(name,value) VALUES ('local_documentation','on')")
    status, help_body, _ = page(session, '/app.php/help')
    check(status == 200 and 'Not available locally.' in help_body and 'https://kadupul.org/guides/' not in help_body,
          'help overview honors Local Page Help Only without remote fallback')
    harness.sql("REPLACE INTO settings(name,value) VALUES ('local_documentation','')")
    harness.php('-r', 'require "include/cli_check.php"; reset_user_perms((int) $argv[1]);', str(user))
    session = Session(harness.base)
    check(session.login('behavior-admin')['status'] == 200, 'page-help contract uses a refreshed native settings cache')
    check(session.request('/help.php?page=Devices.html').get('json') == {'status': 'Success', 'location': 'https://kadupul.org/guides/worked-example/'},
          'existing JSON page-help contract remains available')
    return session


def verify_console_collectors(harness, check):
    saved = False
    created = False
    try:
        harness.sql('CREATE DATABASE console_collector CHARACTER SET utf8mb4')
        created = True
        tables = harness.sql('SHOW TABLES').splitlines()
        if not tables or any(not re.fullmatch(r'[a-z0-9_]+', table) for table in tables):
            raise RuntimeError('Collector fixture table inventory is invalid')
        harness.sql('SET FOREIGN_KEY_CHECKS=0; ' + ' '.join(
            f'CREATE TABLE console_collector.`{table}` LIKE cacti.`{table}`; INSERT INTO console_collector.`{table}` SELECT * FROM cacti.`{table}`;'
            for table in tables))
        harness.command('cp', 'include/config.php', '/artifacts/console-primary-config.php', check=True)
        saved = True
        local = """
$rdatabase_type = $database_type ?? 'mysql';
$rdatabase_hostname = $database_hostname;
$rdatabase_default = $database_default;
$rdatabase_username = $database_username;
$rdatabase_password = $database_password;
$rdatabase_port = $database_port ?? 3306;
$database_default = 'console_collector';
$database_username = 'root';
$database_password = 'behavior-root';
$poller_id = 2;
"""
        for state in ('online', 'recovery', 'offline'):
            harness.command('cp', '/artifacts/console-primary-config.php', 'include/config.php', check=True)
            code = local + ("\n$conn_mode = 'offline';\n" if state == 'offline' else '')
            harness.command('php', '-r', 'file_put_contents("include/config.php", $argv[1], FILE_APPEND);', code, check=True)
            # Apache's opcode cache revalidates changed trusted configuration.
            time.sleep(3)
            harness.sql("DELETE FROM console_collector.poller_output_boost; UPDATE cacti.user_auth SET enabled='on' WHERE username='admin'; UPDATE console_collector.user_auth SET enabled='on' WHERE username='admin'")
            if state == 'online':
                harness.sql("UPDATE console_collector.user_auth SET enabled='' WHERE username='admin'")
            else:
                harness.sql("UPDATE cacti.user_auth SET enabled='' WHERE username='admin'")
                if state == 'recovery':
                    harness.sql("INSERT INTO console_collector.poller_output_boost(local_data_id,rrd_name,`time`,output) VALUES (1,'fixture','2026-01-01 00:00:00','1')")
            session = Session(harness.base)
            login = session.login('behavior-admin')
            check(login['status'] == 200 and not login['login_form'], 'collector console authenticates against the correct database: ' + state)
            with session.opener.open(harness.base + '/index.php') as response:
                body = response.read().decode()
                check(response.status == 200 and 'Remote Data Collector Status:' in body and ('>' + state.capitalize() + '<') in body,
                      'collector console displays the native connection state: ' + state)
                check(('Number of Offline Records:' in body) == (state != 'online'), 'collector queue visibility matches connection state: ' + state)
            check(session.request('/app.php/help')['status'] == 200, 'collector help uses the same admitted identity store: ' + state)
    finally:
        harness.sql("UPDATE cacti.user_auth SET enabled='on' WHERE username='admin'")
        if saved:
            harness.command('cp', '/artifacts/console-primary-config.php', 'include/config.php', check=True)
            harness.command('rm', '-f', '/artifacts/console-primary-config.php', check=True)
            time.sleep(3)
        if created:
            harness.sql('DROP DATABASE console_collector')


def verify_console_prefix(harness, check):
    harness.command('cp', 'include/config.php', '/artifacts/console-prefix-config.php', check=True)
    try:
        harness.command('php', '-r', 'file_put_contents("include/config.php", PHP_EOL . chr(36) . "url_path = " . var_export("/cacti/", true) . ";" . PHP_EOL, FILE_APPEND);', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', "printf 'Alias /cacti/ /var/www/html/\\n' > /etc/apache2/conf-available/console-prefix.conf; a2enconf console-prefix; apachectl -k graceful", check=True)
        time.sleep(3)
        session = Session(harness.base + '/cacti')
        login = session.login('behavior-admin')
        check(login['status'] == 200 and not login['login_form'], 'console native login preserves the installation subdirectory')
        for front in ('/index.php', '/app.php/console', '/public/index.php/console'):
            with session.opener.open(session.base + front) as response:
                body = response.read().decode()
                check(response.status == 200 and '/cacti/include/js/console-home.js' in body,
                      'console script and links retain the installation subdirectory: ' + front)
        for front in ('/app.php/help', '/public/index.php/help'):
            with session.opener.open(session.base + front) as response:
                body = response.read().decode()
                check(response.status == 200 and '/cacti/index.php' in body,
                      'help return link retains the installation subdirectory: ' + front)
    finally:
        harness.command('cp', '/artifacts/console-prefix-config.php', 'include/config.php', check=True)
        harness.command('rm', '-f', '/artifacts/console-prefix-config.php', check=True)
        harness.compose('exec', '-T', 'web', 'sh', '-ec', 'a2disconf console-prefix; apachectl -k graceful', check=True)
        time.sleep(3)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--database-sessions', action='store_true')
    parser.add_argument('--coverage-output', type=Path)
    parser.add_argument('--project', default='kadupul-console-review')
    args = parser.parse_args()
    if os.environ.get('KADUPUL_BROWSER_COVERAGE'):
        os.environ['KADUPUL_BROWSER_COVERAGE_SESSION'] = 'database' if args.database_sessions else 'files'
    harness = Harness(SimpleNamespace(project=args.project, target='console-review'))
    command = harness.command

    def installer_allowance(*arguments, check=False):
        if 'cli/install_cacti.php' in arguments:
            # This fixture exercises presentation, not device templates. Use
            # the installer's supported selection options to omit optional imports.
            arguments += tuple('--template=' + path.name + ':0' for path in sorted(
                (Path(__file__).resolve().parents[2] / 'install/templates').glob('*.xml.gz')))
            return harness.compose('exec', '-T', '-u', 'www-data', 'web', *arguments, check=check, timeout=600)
        return command(*arguments, check=check)

    harness.command = installer_allowance
    if args.coverage_output:
        from coverage_support import configure_coverage
        configure_coverage(harness, args.coverage_output)
    checks = []

    def check(condition, message):
        if not condition:
            raise AssertionError(message)
        checks.append(message)
        print('PASS ' + message, flush=True)

    try:
        harness.setup()
        if args.database_sessions:
            harness.command('php', '-r', 'file_put_contents("include/config.php", "\\n\\$cacti_db_session = true;\\n", FILE_APPEND);', check=True)
        verify_console_home(harness, check)
        verify_console_collectors(harness, check)
        verify_console_prefix(harness, check)
        if args.coverage_output:
            from coverage_support import publish_coverage
            publish_coverage(args.coverage_output, args.database_sessions, checks)
        print(f'Console HTTP integration passed: {len(checks)} checks.', flush=True)
    finally:
        if harness.setup_started:
            harness.compose('down', '--volumes', '--remove-orphans', timeout=120)
        harness.lock.close()


if __name__ == '__main__':
    main()
