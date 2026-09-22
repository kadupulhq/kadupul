"""Keep online collector Sites administration on the primary database."""
import base64
import time


def verify_collector_sites(harness, request, form, action, site_id, created, check):
    database_created = False
    configuration_saved = False
    try:
        harness.sql('CREATE DATABASE sites_collector CHARACTER SET utf8mb4; CREATE TABLE sites_collector.version LIKE cacti.version; INSERT INTO sites_collector.version SELECT * FROM cacti.version; CREATE TABLE sites_collector.poller_output_boost LIKE cacti.poller_output_boost')
        database_created = True
        check(harness.command('cp', 'include/config.php', '/artifacts/site-primary-config.php')['exit'] == 0, 'collector fixture preserves installation configuration')
        configuration_saved = True
        code = '''
$rdatabase_type = $database_type ?? 'mysql';
$rdatabase_hostname = $database_hostname;
$rdatabase_default = $database_default;
$rdatabase_username = $database_username;
$rdatabase_password = $database_password;
$rdatabase_port = $database_port ?? 3306;
$database_default = 'sites_collector';
$database_username = 'root';
$database_password = 'behavior-root';
$poller_id = 2;
'''
        encoded = base64.b64encode(code.encode()).decode()
        check(harness.php('-r', 'file_put_contents("include/config.php", base64_decode("' + encoded + '"), FILE_APPEND);')['exit'] == 0, 'collector configuration uses a separate local schema')
        # Apache caches include/config.php for opcache.revalidate_freq (2s).
        # Let workers observe the fixture change before making assertions.
        time.sleep(3)
        status, body, _ = request('/app.php/inventory/sites')
        check(status == 200 and '/host.php' in body, 'online collector site listing uses primary data and legacy device links')
        status, body, _ = request('/public/index.php/inventory/sites')
        check(status == 200 and 'href="/host.php"' in body and '/public/host.php' not in body, 'public collector entry links to the installation device page')
        path = f'/app.php/inventory/sites/{site_id}/edit'
        fields = form(path)
        check(request(path, fields | {'site_edit[notes]': 'collector-update'})[0] == 200 and harness.sql(f'SELECT notes FROM sites WHERE id={site_id}').strip() == 'collector-update', 'online collector site edit writes only to the primary')
        check(request(f'/sites.php?action=edit&id={site_id}')[0] == 200, 'legacy collector Sites bookmark remains usable')
        duplicate = action('duplicate', [site_id])
        check(request(duplicate, form(duplicate) | {'site_action[pattern]': 'collector-copy'})[0] == 200, 'online collector duplication uses the primary')
        copy_id = int(harness.sql("SELECT id FROM sites WHERE name='collector-copy'").strip())
        created.append(copy_id)
        delete = action('delete', [copy_id])
        check(request(delete, form(delete))[0] == 200 and harness.sql(f'SELECT COUNT(*) FROM sites WHERE id={copy_id}').strip() == '0', 'online collector deletion uses the primary')
        probe = harness.php('-r', 'require "include/vendor/autoload.php"; $stack=new Symfony\\Component\\HttpFoundation\\RequestStack(); $request=Symfony\\Component\\HttpFoundation\\Request::create("/inventory/sites"); $request->attributes->set("_route","inventory_sites"); $stack->push($request); $config=new Kadupul\\Platform\\Infrastructure\\Legacy\\InstallationConfiguration(getcwd(),$stack); $config->values(); $stack->pop(); try { $config->values(); exit(1); } catch (RuntimeException) { echo "blocked"; }')
        check(probe['exit'] == 0 and probe['stdout'] == 'blocked', 'collector compatibility does not enable primary-only CLI workers')
        fresh = form(path)
        harness.sql("INSERT INTO sites_collector.poller_output_boost (local_data_id,rrd_name,time,output) VALUES (1,'fixture',NOW(),'1')")
        check(request(path, fresh | {'site_edit[notes]': 'must-not-save'})[0] >= 500 and harness.sql(f'SELECT notes FROM sites WHERE id={site_id}').strip() == 'collector-update', 'collector recovery blocks site writes')
        harness.sql('DELETE FROM sites_collector.poller_output_boost')
        offline = base64.b64encode(b"\n$rdatabase_hostname = '127.0.0.1'; $rdatabase_port = 1;\n").decode()
        harness.php('-r', 'file_put_contents("include/config.php", base64_decode("' + offline + '"), FILE_APPEND);')
        time.sleep(3)
        check(request(path, fresh | {'site_edit[notes]': 'must-not-save'})[0] >= 500 and harness.sql(f'SELECT notes FROM sites WHERE id={site_id}').strip() == 'collector-update', 'unreachable primary cannot fall back to local site writes')
    finally:
        if configuration_saved:
            harness.command('cp', '/artifacts/site-primary-config.php', 'include/config.php')
            harness.command('rm', '/artifacts/site-primary-config.php')
            time.sleep(3)
        if database_created:
            harness.sql('DROP DATABASE sites_collector')
