"""Bulk SNMP writes, secret handling and per-device credential resolution."""
from device_state_scenarios import StateForm


def verify_bulk_snmp(harness, session, check, poller):
    ids = []
    trigger = False
    try:
        for index in range(2):
            owner = poller if index == 0 else 1
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,snmp_community,availability_method) VALUES ('bulk-snmp-{index}','127.0.0.1',{owner},0,2,'bulk-stored-{index}',0); SELECT LAST_INSERT_ID()").strip()))
        harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={ids[0]}')
        selected = ','.join(map(str, ids))
        form = StateForm(harness, session, ids)
        form.path = form.path.replace('/disable?', '/snmp?')
        status, body = form.request()
        check(status == 200 and 'bulk-stored-' not in body, 'bulk SNMP never displays stored credentials')
        fields = form.fields()
        check(form.request(fields=fields, origin=False)[0] == 422, 'bulk SNMP requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_state[_token]')
        check(form.apply(missing) == 422, 'bulk SNMP requires CSRF token')
        check(form.apply(fields | {'device_state[snmp][poller_id]': '2'}) == 422, 'bulk SNMP rejects unrelated nested fields')
        harness.sql(f'UPDATE host SET snmp_version=1 WHERE id={ids[1]}')
        check(form.apply(fields) == 409, 'bulk SNMP rejects concurrent protocol changes')
        harness.sql(f'UPDATE host SET snmp_version=2 WHERE id={ids[1]}')
        harness.sql(f"UPDATE host SET snmp_username='valid-first',snmp_password='valid-first-pass' WHERE id={ids[0]}")
        invalid = fields | {'device_state[snmp][snmp_version]': '3', 'device_state[snmp][snmp_auth_protocol]': 'SHA256'}
        check(form.apply(invalid) == 422, 'bulk SNMP validates all stored credentials before writes')
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_version=2').strip() == '2', 'invalid stored credentials leave entire bulk selection unchanged')
        check(form.apply(fields) == 200, 'bulk SNMP keeps each device credentials through Symfony')
        for index, device in enumerate(ids):
            check(harness.sql(f'SELECT snmp_community FROM host WHERE id={device}').strip() == f'bulk-stored-{index}', 'bulk SNMP preserves individual stored communities')
        fields = form.fields()
        replacement = fields | {'device_state[snmp][keep_credentials]': 'replace', 'device_state[snmp][snmp_community]': 'bulk-new-secret'}
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_snmp BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.snmp_community='bulk-new-secret' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk SNMP fixture'; END IF; END$$\nDELIMITER ;")
        trigger = True
        status, body = form.request(fields=replacement)
        check(status == 502 and 'bulk-new-secret' not in body, 'bulk SNMP failure hides submitted secrets')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_community LIKE 'bulk-stored-%'").strip() == '2', 'bulk SNMP failure rolls back entire primary selection')
        harness.sql('DROP TRIGGER reject_bulk_snmp')
        trigger = False
        check(form.apply(replacement) == 200, 'bulk SNMP replaces credentials through Symfony')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_community='bulk-new-secret'").strip() == '2', 'bulk SNMP persists replacement credentials')
        check(harness.sql(f"SELECT COUNT(*) FROM create_remote.host WHERE id={ids[0]} AND snmp_community='bulk-new-secret'").strip() == '1', 'bulk SNMP verifies remote credentials')
        v3 = form.fields() | {'device_state[snmp][keep_credentials]': 'replace', 'device_state[snmp][snmp_version]': '3', 'device_state[snmp][snmp_username]': 'bulk-user', 'device_state[snmp][snmp_auth_protocol]': 'SHA384', 'device_state[snmp][snmp_password]': 'bulk-auth-secret', 'device_state[snmp][snmp_priv_protocol]': 'AES', 'device_state[snmp][snmp_priv_passphrase]': 'bulk-privacy-secret'}
        check(form.apply(v3) == 200, 'bulk SNMP applies validated version 3 credentials')
        fields = form.fields() | {'device_state[snmp][snmp_version]': '2'}
        check(form.apply(fields) == 200, 'bulk SNMP permits leaving version 3')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_username='' AND snmp_password='' AND snmp_priv_passphrase='' AND snmp_auth_protocol='[None]'").strip() == '2', 'bulk SNMP clears version 3 secrets when leaving version 3')
        query_id = int(harness.sql('SELECT id FROM snmp_query ORDER BY id LIMIT 1').strip())
        for prefix, device in [('', ids[0]), ('', ids[1]), ('create_remote.', ids[0])]:
            harness.sql(f"INSERT INTO {prefix}host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES ({device},{query_id},1); INSERT INTO {prefix}poller_reindex (host_id,data_query_id,action,op,assert_value,arg1) VALUES ({device},{query_id},0,'=','1','fixture')")
        off = form.fields() | {'device_state[snmp][snmp_version]': '0'}
        check(form.apply(off) == 200, 'bulk SNMP disables protocol through Symfony')
        for prefix in ['', 'create_remote.']:
            check(harness.sql(f'SELECT COUNT(*) FROM {prefix}host_snmp_query WHERE host_id IN ({selected}) AND reindex_method<>0').strip() == '0' and harness.sql(f'SELECT COUNT(*) FROM {prefix}poller_reindex WHERE host_id IN ({selected})').strip() == '0', 'bulk SNMP disabling clears primary and remote reindex state')
        result = harness.php('-r', 'require "include/global.php"; $clean=true; foreach ([cacti_log_file(), sys_get_temp_dir()."/cacti-sql.log"] as $path) { if (is_file($path)) { $log=file_get_contents($path); foreach (["bulk-new-secret","bulk-auth-secret","bulk-privacy-secret"] as $secret) { if (str_contains($log,$secret)) { $clean=false; } } } } echo $clean ? "clean" : "leaked";')
        check(result['exit'] == 0 and result['stdout'] == 'clean', 'bulk SNMP secrets stay out of database diagnostics')
    finally:
        if trigger:
            harness.sql('DROP TRIGGER reject_bulk_snmp')
        if ids:
            selected = ','.join(map(str, ids))
            for prefix in ['', 'create_remote.']:
                harness.sql(f'DELETE FROM {prefix}host WHERE id IN ({selected}); DELETE FROM {prefix}poller_item WHERE host_id IN ({selected}); DELETE FROM {prefix}host_snmp_query WHERE host_id IN ({selected}); DELETE FROM {prefix}poller_reindex WHERE host_id IN ({selected})')
