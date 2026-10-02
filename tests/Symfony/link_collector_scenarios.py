"""Exercise Navigation through a real online collector installation configuration."""
import base64
import time
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request
from device_edit_scenarios import Inputs


def verify_collector_links(harness, session, user_id, check):
    database_created = False
    configuration_saved = False
    before = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='external_links_filters'").strip()
    def request(path, fields=None):
        target = harness.base + path
        if fields is not None:
            target = Request(target, data=urlencode(fields).encode(), headers={'Origin': harness.base})
        try:
            result = session.opener.open(target)
        except HTTPError as error:
            result = error
        with result:
            return result.status, result.read().decode()
    try:
        harness.sql('CREATE DATABASE links_collector CHARACTER SET utf8mb4; CREATE TABLE links_collector.version LIKE cacti.version; INSERT INTO links_collector.version SELECT * FROM cacti.version; CREATE TABLE links_collector.poller_output_boost LIKE cacti.poller_output_boost')
        database_created = True
        check(harness.command('cp', 'include/config.php', '/artifacts/link-primary-config.php')['exit'] == 0, 'links collector fixture preserves installation configuration')
        configuration_saved = True
        code = '''
$rdatabase_type = $database_type ?? 'mysql';
$rdatabase_hostname = $database_hostname;
$rdatabase_default = $database_default;
$rdatabase_username = $database_username;
$rdatabase_password = $database_password;
$rdatabase_port = $database_port ?? 3306;
$database_default = 'links_collector';
$database_username = 'root';
$database_password = 'behavior-root';
$poller_id = 2;
'''
        encoded = base64.b64encode(code.encode()).decode()
        check(harness.php('-r', 'file_put_contents("include/config.php", base64_decode("' + encoded + '"), FILE_APPEND);')['exit'] == 0, 'links collector uses an actual separate local schema')
        time.sleep(3)
        status, body = request('/app.php/links?filter=collector-requested')
        check(status == 200 and 'value="collector-requested"' in body, 'online collector Navigation lists validated primary filters')
        check(request('/public/index.php/links')[0] == 200, 'online collector public Navigation entry uses real configuration')
        check(request('/links.php?header=false')[0] == 200, 'online collector legacy Navigation bookmark remains usable')
        after = harness.sql(f"SELECT value FROM settings_user WHERE user_id={user_id} AND name='external_links_filters'").strip()
        check(before == after, 'online collector Navigation does not overwrite primary preferences')
        status, body = request('/app.php/links/new')
        check(status == 200, 'online collector Navigation can read the primary editor snapshot')
        form = Inputs()
        form.feed(body)
        fields = form.fields | {'link[title]': 'Remote must not save', 'link[style]': 'TAB', 'link[filename]': '0', 'link[fileurl]': 'https://example.org', 'link[consolesection]': 'External Links', 'link[consolenewsection]': '', 'link[enabled]': '1', 'link[refresh]': '0'}
        check(request('/app.php/links/new', fields)[0] == 502 and harness.sql("SELECT COUNT(*) FROM external_links WHERE title='Remote must not save'").strip() == '0', 'collector routing does not widen Navigation mutation ownership')
        probe = harness.php('-r', 'require "include/vendor/autoload.php"; $stack=new Symfony\\Component\\HttpFoundation\\RequestStack(); $request=Symfony\\Component\\HttpFoundation\\Request::create("/links"); $request->attributes->set("_route","navigation_links"); $stack->push($request); $config=new Kadupul\\Platform\\Infrastructure\\Legacy\\InstallationConfiguration(getcwd(),$stack); $config->values(); $stack->pop(); try { $config->values(); exit(1); } catch (RuntimeException) { echo "blocked"; }')
        check(probe['exit'] == 0 and probe['stdout'] == 'blocked', 'Navigation collector routing remains closed to primary-only CLI')
        harness.sql("INSERT INTO links_collector.poller_output_boost (local_data_id,rrd_name,time,output) VALUES (1,'fixture',NOW(),'1')")
        check(request('/app.php/links')[0] >= 500, 'collector recovery blocks Navigation primary access')
        harness.sql('DELETE FROM links_collector.poller_output_boost')
        offline = base64.b64encode(b"\n$conn_mode = 'offline';\n").decode()
        harness.php('-r', 'file_put_contents("include/config.php", base64_decode("' + offline + '"), FILE_APPEND);')
        time.sleep(3)
        check(request('/app.php/links')[0] >= 500, 'offline collector cannot fall back to local Navigation data')
    finally:
        if configuration_saved:
            harness.command('cp', '/artifacts/link-primary-config.php', 'include/config.php')
            harness.command('rm', '/artifacts/link-primary-config.php')
            time.sleep(3)
        if database_created:
            harness.sql('DROP DATABASE links_collector')
