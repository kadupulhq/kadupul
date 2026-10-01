# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Authenticated-only About page parity against real HTTP and MariaDB."""
import base64
import time
from urllib.error import HTTPError
from urllib.request import Request


def verify_about(harness, session, user_id, check):
    from harness import Session
    version = harness.php('-r', 'echo trim(file_get_contents("include/cacti_version"));')
    if version["exit"] or not version["stdout"].strip():
        raise RuntimeError("About fixture could not read the actual source release")
    expected_version = "Version " + version["stdout"].strip()
    def page(client, path='/app.php/about', method='GET'):
        try:
            response = client.opener.open(Request(harness.base + path, method=method))
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode(), response.headers

    for path in ('/app.php/about', '/public/index.php/about', '/about.php'):
        check(page(Session(harness.base), path)[0] == 401, 'anonymous About denied: ' + path)
        status, body, headers = page(session, path)
        check(status == 200 and 'About Kadupul' in body and expected_version in body,
              'authenticated About version and legacy redirect: ' + path + ' status=' + str(status))
        check('no-store' in headers.get('Cache-Control', ''), 'About is never cached: ' + path)
        check(all(marker not in body for marker in ('behavior-root', 'behavior-admin', '/var/www/html', 'database_password', 'session_id', 'DB:')),
              'About excludes credentials installation and account details: ' + path)
    check(page(session, method='HEAD')[0] == 200 and page(session, method='HEAD')[1] == '', 'About HEAD has no body')
    check(page(session, method='POST')[0] == 405 and page(session, '/about.php', method='POST')[0] == 405,
          'About and legacy shim reject POST')
    for text in ('either version 2 of the License, or (at your option) any later version.', 'WITHOUT ANY WARRANTY', 'MERCHANTABILITY', 'FITNESS FOR A PARTICULAR PURPOSE', 'https://github.com/kadupulhq/kadupul'):
        check(text in body, 'About retains license support text: ' + text)
    try:
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=8')
        check(session.request('/app.php/session')['status'] == 401 and page(session)[0] == 200,
              'About requires login without console realm 8')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_force_language','fr'),('i18n_auto_detection','0'),('i18n_default_language','fr'),('i18n_language_support','1')")
        status, body, _ = page(session, '/app.php/about?_locale=en')
        check(status == 200 and 'À propos de Kadupul' in body and 'SANS AUCUNE GARANTIE' in body and 'toute version ultérieure' in body,
              'French About preserves full license and ignores locale query override')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','0')")
        check('About Kadupul' in page(session)[1], 'disabled translations restore English About')
        verify_collector_about(harness, session, page, check)
        harness.command('php', '-r', '$f=\'include/global.php\'; copy($f,\'/tmp/about-global.original\'); file_put_contents($f,str_replace("#define(\'CACTI_VERSION_BETA\', 1);","define(\'CACTI_VERSION_BETA\', \'<beta>\');",file_get_contents($f))); $v=\'include/cacti_version\';copy($v,\'/tmp/about-version.original\');file_put_contents($v,"<script>version</script>");', check=True)
        status, body, _ = page(session)
        check(status == 200 and 'Version &lt;script&gt;version&lt;/script&gt;' in body and '- Beta &lt;beta&gt;' in body and '<script>' not in body,
              'About version and beta are escaped without legacy bootstrap')
    finally:
        harness.command('php', '-r', "foreach (['global'=>'include/global.php','version'=>'include/cacti_version'] as $name=>$file) { $backup='/tmp/about-'.$name.'.original'; if(is_file($backup)){copy($backup,$file);unlink($backup);} }", check=True)
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_auto_detection','1'),('i18n_default_language','en-US')")


def verify_collector_about(harness, session, page, check):
    from harness import Session
    saved = False
    created = False
    try:
        harness.sql('CREATE DATABASE about_collector CHARACTER SET utf8mb4; CREATE TABLE about_collector.version LIKE cacti.version; INSERT INTO about_collector.version SELECT * FROM cacti.version; CREATE TABLE about_collector.poller_output_boost LIKE cacti.poller_output_boost')
        created = True
        harness.command('cp', 'include/config.php', '/artifacts/about-primary-config.php', check=True)
        saved = True
        code = '''
$rdatabase_type = $database_type ?? 'mysql';
$rdatabase_hostname = $database_hostname;
$rdatabase_default = $database_default;
$rdatabase_username = $database_username;
$rdatabase_password = $database_password;
$rdatabase_port = $database_port ?? 3306;
$database_default = 'about_collector';
$database_username = 'root';
$database_password = 'behavior-root';
$poller_id = 2;
'''
        encoded = base64.b64encode(code.encode()).decode()
        configured = harness.php('-r', 'file_put_contents("include/config.php", base64_decode("' + encoded + '"), FILE_APPEND);')
        check(configured['exit'] == 0, 'About collector fixture installs separate local configuration')
        time.sleep(3)
        check(page(Session(harness.base))[0] == 401,
              'online collector About rejects anonymous requests')
        for path in ('/app.php/about', '/public/index.php/about', '/about.php'):
            status, body, headers = page(session, path)
            check(status == 200 and 'About Kadupul' in body and 'no-store' in headers.get('Cache-Control', ''),
                  'online collector About authenticates using primary without local auth tables: ' + path)
        check(page(session, method='POST')[0] == 405 and page(session, '/about.php', method='POST')[0] == 405,
              'online collector About remains read-only')
        check(session.request('/app.php/inventory/devices')['status'] >= 500,
              'collector About exception does not enable primary-only inventory routes')
    finally:
        if saved:
            harness.command('cp', '/artifacts/about-primary-config.php', 'include/config.php', check=True)
            harness.command('rm', '/artifacts/about-primary-config.php', check=True)
            time.sleep(3)
        if created:
            harness.sql('DROP DATABASE about_collector')
