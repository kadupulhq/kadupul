"""Creation compatibility checks against the disposable primary and collector DBs."""
import base64
import json
import re
import time
from concurrent.futures import ThreadPoolExecutor


def verify_creation_compatibility(harness, post, fields, created, user_id, check, session):
    def submit(name, updates=None):
        data = fields | {'device_create[description]': name, 'device_create[host_template_id]': '0'} | (updates or {})
        status, body, _ = post(data)
        ids = harness.sql(f"SELECT id FROM host WHERE description='{name}'").strip()
        if ids:
            created.extend(int(value) for value in ids.splitlines())
        return status, body

    check(harness.php('-r', 'require "include/global.php"; function setup_creation_guard() { api_plugin_register_hook("compatibility_test","api_device_save","compatibility_create_guard","setup.php",true); } setup_creation_guard();')['exit'] == 0, 'creation guard fixture registered')
    before = harness.sql('SELECT id,description,hostname,host_template_id,site_id,poller_id FROM host ORDER BY id')
    for key in ['id', 'host_template_id', 'site_id', 'poller_id']:
        status, _ = submit('create-hook-change-' + key)
        check(status == 502 and harness.sql('SELECT id,description,hostname,host_template_id,site_id,poller_id FROM host ORDER BY id') == before, 'creation rejects plugin changes to authorized ' + key + ' before persistence')
    debug_fields = {k:v for k,v in fields.items() if k != 'device_create[use_default_credentials]'}
    debug_fields.update({'device_create[description]': 'create-debug-fixture', 'device_create[host_template_id]': '0', 'device_create[snmp_community]': 'creation-log-secret-92831'})
    status, _, _ = post(debug_fields)
    ids = harness.sql("SELECT id FROM host WHERE description='create-debug-fixture'").strip()
    if ids:
        created.extend(int(value) for value in ids.splitlines())
    check(status == 200 and ids, 'device creation succeeds with SQL debugging enabled by a plugin')
    result = harness.php('-r', 'require "include/global.php"; $clean=true; foreach ([cacti_log_file(), sys_get_temp_dir() . "/cacti-sql.log"] as $path) { if (is_file($path) && str_contains(file_get_contents($path), "creation-log-secret-92831")) { $clean=false; } } echo $clean ? "clean" : "leaked";')
    check(result['exit'] == 0 and result['stdout'] == 'clean', 'database diagnostics and SQL debug files omit creation credentials')

    v3 = {k:v for k,v in fields.items() if k != 'device_create[use_default_credentials]'}
    v3.update({'device_create[description]': 'create-sha384-fixture', 'device_create[host_template_id]': '0', 'device_create[snmp_version]': '3', 'device_create[snmp_username]': 'fixture', 'device_create[snmp_auth_protocol]': 'SHA384', 'device_create[snmp_priv_protocol]': '[None]', 'device_create[snmp_password]': 'fixture-sha384-password'})
    status, body, _ = post(v3)
    ids = harness.sql("SELECT id FROM host WHERE description='create-sha384-fixture'").strip()
    if ids:
        created.extend(int(value) for value in ids.splitlines())
    check(status == 200 and ids and 'fixture-sha384-password' not in body, 'SHA384 creates successfully through the actual legacy validator')
    check(harness.sql(f'SELECT snmp_auth_protocol FROM host WHERE id={created[-1]}').strip() == 'SHA384', 'SHA384 is preserved without mapping to an invalid protocol')

    remote_created = False
    poller = None
    try:
        harness.sql('CREATE DATABASE create_remote CHARACTER SET utf8mb4')
        remote_created = True
        tables = harness.sql('SHOW TABLES').splitlines()
        if not all(re.fullmatch(r'[A-Za-z0-9_]+', table) for table in tables):
            raise RuntimeError('Unexpected fixture table name')
        harness.sql(';'.join(f'CREATE TABLE create_remote.`{table}` LIKE cacti.`{table}`' for table in tables))
        poller = int(harness.sql("INSERT INTO poller (name,hostname,dbhost,dbdefault,dbuser,dbpass,last_status) VALUES ('Creation collector','db','db','create_remote','root','behavior-root',NOW()); SELECT LAST_INSERT_ID()").strip())
        status, _ = submit('create-remote-fixture', {'device_create[poller_id]': str(poller)})
        check(status == 200, 'device creation succeeds on a disposable remote collector')
        check(harness.sql(f'SELECT HEX(notes) FROM create_remote.host WHERE id={created[-1]}').strip().lower() == fields['device_create[notes]'].encode().hex(), 'remote collector preserves four-byte Unicode notes')
        from device_template_scenarios import verify_remote_template_assignment
        verify_remote_template_assignment(harness, session, created[-1], check)
        from device_collector_scenarios import verify_remote_collector_assignment
        verify_remote_collector_assignment(harness, session, created[-1], poller, check)
        from device_state_scenarios import verify_remote_device_state
        verify_remote_device_state(harness, session, created[-1], poller, check)
        from device_removal_scenarios import verify_device_removal
        verify_device_removal(harness, session, user_id, poller, check)
        harness.sql("UPDATE create_remote.host SET notes=''; ALTER TABLE create_remote.host MODIFY notes TEXT CHARACTER SET utf8mb3")
        status, _ = submit('create-remote-rejected-fixture', {'device_create[poller_id]': str(poller)})
        check(status == 502 and harness.sql("SELECT COUNT(*) FROM host WHERE description='create-remote-rejected-fixture'").strip() == '0', 'remote encoding failure cannot report successful creation or commit the primary row')
    finally:
        if poller is not None:
            harness.sql(f'DELETE FROM poller WHERE id={poller}')
        if remote_created:
            harness.sql('DROP DATABASE create_remote')

    check(harness.php('-r', 'require "include/global.php"; function setup_creation_lock() { api_plugin_register_hook("compatibility_test","api_device_save","compatibility_create_lock","setup.php",true); } setup_creation_lock();')['exit'] == 0, 'creation lock fixture registered')

    check(harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='compatibility_test' AND hook='api_device_save' AND `function`='compatibility_create_lock' AND status=1").strip() == '1', 'creation lock hook is enabled in the registry')

    def blocked(sql):
        encoded = base64.b64encode(sql.encode()).decode()
        code = 'require "include/global.php"; $db=$database_sessions["$database_hostname:$database_port:$database_default"]; $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $db->exec("SET SESSION innodb_lock_wait_timeout=1"); try { $db->exec(base64_decode("' + encoded + '")); echo "unlocked"; } catch (PDOException $e) { echo ($e->errorInfo[1] ?? 0) === 1205 ? "blocked" : "unexpected"; }'
        result = harness.php('-r', code)
        return result['exit'] == 0 and result['stdout'] == 'blocked'

    group = None
    try:
        for mode in ['direct', 'group']:
            if mode == 'group':
                group = int(harness.sql("INSERT INTO user_auth_group (name,enabled) VALUES ('creation-lock','on'); SELECT LAST_INSERT_ID()").strip())
                harness.sql(f'INSERT INTO user_auth_group_members (group_id,user_id) VALUES ({group},{user_id}); INSERT INTO user_auth_group_realm (group_id,realm_id) VALUES ({group},3); DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
            harness.command('rm', '-f', '/artifacts/create-lock-ready', '/artifacts/create-lock-release')
            with ThreadPoolExecutor(max_workers=1) as pool:
                future = pool.submit(submit, 'locked-create-fixture-' + mode)
                try:
                    deadline = time.monotonic() + 15
                    while harness.command('test', '-f', '/artifacts/create-lock-ready')['exit'] != 0:
                        if future.done():
                            raise RuntimeError('Creation returned before the lock fixture: ' + str(future.result()[0]))
                        if time.monotonic() > deadline:
                            raise RuntimeError('Creation did not reach the lock fixture')
                        time.sleep(0.1)
                    changes = [f"UPDATE user_auth SET locked='on' WHERE id={user_id}", "UPDATE settings SET value='0' WHERE name='auth_method'", "UPDATE settings SET value='admin' WHERE name='guest_user'"] if mode == 'direct' else []
                    changes += [f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3'] if mode == 'direct' else [f'DELETE FROM user_auth_group_members WHERE group_id={group} AND user_id={user_id}', f'DELETE FROM user_auth_group_realm WHERE group_id={group} AND realm_id=3', f"UPDATE user_auth_group SET enabled='' WHERE id={group}"]
                    check(all(blocked(sql) for sql in changes), 'device creation serializes ' + mode + ' authorization revocations')
                finally:
                    harness.command('touch', '/artifacts/create-lock-release')
                check(future.result(timeout=30)[0] == 200, 'authorized creation commits after ' + mode + ' lock verification')
    finally:
        harness.sql(f'REPLACE INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},3)')
        if group is not None:
            harness.sql(f'DELETE FROM user_auth_group_members WHERE group_id={group}; DELETE FROM user_auth_group_realm WHERE group_id={group}; DELETE FROM user_auth_group WHERE id={group}')
        harness.command('rm', '-f', '/artifacts/create-lock-ready', '/artifacts/create-lock-release')
