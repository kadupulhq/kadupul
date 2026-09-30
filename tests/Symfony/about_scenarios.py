"""Authenticated-only About page parity against real HTTP and MariaDB."""
from urllib.error import HTTPError
from urllib.request import Request


def verify_about(harness, session, user_id, check):
    from harness import Session
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
        check(status == 200 and 'About Kadupul' in body and 'Version 1.2.31' in body,
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
        harness.command('php', '-r', '$f=\'include/global.php\'; copy($f,\'/tmp/about-global.original\'); file_put_contents($f,str_replace("#define(\'CACTI_VERSION_BETA\', 1);","define(\'CACTI_VERSION_BETA\', \'<beta>\');",file_get_contents($f))); $v=\'include/cacti_version\';copy($v,\'/tmp/about-version.original\');file_put_contents($v,"<script>version</script>");', check=True)
        status, body, _ = page(session)
        check(status == 200 and 'Version &lt;script&gt;version&lt;/script&gt;' in body and '- Beta &lt;beta&gt;' in body and '<script>' not in body,
              'About version and beta are escaped without legacy bootstrap')
    finally:
        harness.command('php', '-r', "foreach (['global'=>'include/global.php','version'=>'include/cacti_version'] as $name=>$file) { $backup='/tmp/about-'.$name.'.original'; if(is_file($backup)){copy($backup,$file);unlink($backup);} }", check=True)
        harness.sql(f'INSERT IGNORE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},8)')
        harness.sql("REPLACE INTO settings (name,value) VALUES ('i18n_language_support','1'),('i18n_auto_detection','1'),('i18n_default_language','en-US')")
