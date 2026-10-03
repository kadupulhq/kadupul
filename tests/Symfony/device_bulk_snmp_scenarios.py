"""Installed bulk SNMP contracts using real data sources and polling caches."""
import json
import re
from device_state_scenarios import StateForm
from device_edit_scenarios import Inputs


class SnmpInputs(Inputs):
    def handle_starttag(self, tag, attrs):
        super().handle_starttag(tag, attrs)
        attributes = dict(attrs)
        if tag == 'input' and attributes.get('type') == 'checkbox' and 'checked' not in attributes:
            self.fields.pop(attributes.get('name'), None)


class SnmpForm(StateForm):
    def fields(self):
        status, body = self.request()
        if status != 200:
            raise AssertionError(f'SNMP form did not load: {status}')
        inputs = SnmpInputs()
        inputs.feed(body)
        return inputs.fields


def create_snmp_sources(harness, ids, check):
    """Clone an installed generic SNMP template into fixture-owned definitions."""
    source = harness.sql('SELECT dtd.id,dtd.data_template_id FROM data_template_data dtd JOIN data_input di ON di.id=dtd.data_input_id WHERE di.type_id=2 AND dtd.local_data_id=0 AND dtd.active="on" AND EXISTS (SELECT 1 FROM data_template_rrd dtr WHERE dtr.data_template_id=dtd.data_template_id AND dtr.local_data_id=0) ORDER BY dtd.id LIMIT 1').strip().split('\t')
    if len(source) != 2:
        raise AssertionError('installed generic SNMP template is missing')
    source_data, source_template = map(int, source)
    template = int(harness.sql("INSERT INTO data_template (hash,name) VALUES ('30330330330330330330330330330330','Bulk SNMP fixture'); SELECT LAST_INSERT_ID()").strip())

    def clone(table, source_id, replacements):
        columns = [line.split('\t')[0] for line in harness.sql(f'SHOW COLUMNS FROM {table}').splitlines() if line.split('\t')[0] != 'id']
        if not all(re.fullmatch(r'[A-Za-z0-9_]+', column) for column in columns):
            raise AssertionError('unexpected installed column')
        projection = ','.join(str(replacements[column]) if column in replacements else '`' + column + '`' for column in columns)
        return int(harness.sql(f'INSERT INTO {table} (' + ','.join('`' + column + '`' for column in columns) + f') SELECT {projection} FROM {table} WHERE id={source_id}; SELECT LAST_INSERT_ID()').strip())

    parent = clone('data_template_data', source_data, {'data_template_id': template, 'local_data_id': 0, 'local_data_template_data_id': 0})
    harness.sql(f'INSERT INTO data_input_data SELECT data_input_field_id,{parent},t_value,value FROM data_input_data WHERE data_template_data_id={source_data}')
    fields = "'hostname','snmp_version','snmp_community','snmp_username','snmp_password','snmp_auth_protocol','snmp_priv_protocol','snmp_priv_passphrase','snmp_context','snmp_engine_id'"
    harness.sql(f"UPDATE data_input_data did JOIN data_input_fields dif ON dif.id=did.data_input_field_id SET did.value='',did.t_value='' WHERE did.data_template_data_id={parent} AND dif.type_code IN ({fields})")
    parent_rrds = []
    for source_rrd in harness.sql(f'SELECT id FROM data_template_rrd WHERE data_template_id={source_template} AND local_data_id=0 ORDER BY id').splitlines():
        parent_rrds.append(clone('data_template_rrd', int(source_rrd), {'data_template_id': template, 'local_data_id': 0, 'local_data_template_rrd_id': 0}))
    # Copy installed profile definitions before fixture collector references.
    for table in ['data_source_profiles', 'data_source_profiles_cf', 'data_source_profiles_rra']:
        harness.sql(f'INSERT IGNORE INTO create_remote.{table} SELECT * FROM {table}')
    locals_ = []
    for device in ids:
        local = int(harness.sql(f'INSERT INTO data_local (host_id,data_template_id) VALUES ({device},{template}); SELECT LAST_INSERT_ID()').strip())
        locals_.append(local)
        data = clone('data_template_data', parent, {'data_template_id': template, 'local_data_id': local, 'local_data_template_data_id': parent})
        harness.sql(f'INSERT INTO data_input_data SELECT data_input_field_id,{data},t_value,value FROM data_input_data WHERE data_template_data_id={parent}')
        for rrd in parent_rrds:
            clone('data_template_rrd', rrd, {'data_template_id': template, 'local_data_id': local, 'local_data_template_rrd_id': rrd})
        result = harness.php('-r', 'define("KADUPUL_THROW_DATABASE_ERRORS", true); require "include/cli_check.php"; require_once "lib/poller.php"; require_once "lib/template.php"; require_once "lib/utility.php"; push_out_host(' + str(device) + '); echo \"complete\";')
        check(result['exit'] == 0 and result['stdout'] == 'complete', 'bulk SNMP real data-source cache fixture initialized')
    selected = ','.join(map(str, ids))
    check(harness.sql(f'SELECT COUNT(DISTINCT host_id) FROM poller_item WHERE host_id IN ({selected}) AND action=0 AND local_data_id>0').strip() == '2', 'bulk SNMP fixture has nonempty primary polling rows')
    check(harness.sql(f'SELECT COUNT(*) FROM create_remote.poller_item WHERE host_id={ids[0]} AND action=0 AND local_data_id>0').strip() != '0', 'bulk SNMP fixture has nonempty remote polling rows')
    return template, locals_


def verify_bulk_snmp(harness, session, check, poller):
    ids = []
    template = None
    triggers = set()
    hook = False
    nontransactional = set()
    try:
        for index in range(2):
            owner = poller if index == 0 else 1
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,snmp_community,snmp_username,snmp_password,snmp_auth_protocol,snmp_priv_protocol,snmp_priv_passphrase,snmp_context,snmp_engine_id,availability_method) VALUES ('bulk-snmp-{index}','127.0.0.1',{owner},0,2,'bulk-stored-{index}','','','MD5','DES','','','',0); SELECT LAST_INSERT_ID()").strip()))
        harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={ids[0]}')
        selected = ','.join(map(str, ids))
        template, _ = create_snmp_sources(harness, ids, check)
        check(harness.php('-r', 'require "include/global.php"; function setup_bulk_snmp_hooks() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); } setup_bulk_snmp_hooks();')['exit'] == 0 and harness.sql("SELECT COUNT(*) FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action' AND status=1").strip() == '1', 'bulk SNMP action hook registered')
        hook = True
        actor = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())

        def actions():
            return [json.loads(line)['args'] for line in harness.command('cat', '/artifacts/plugin.jsonl')['stdout'].splitlines() if json.loads(line).get('callback') == 'statistics_action']

        def snapshot(prefix='', cache=False):
            fields = 'snmp_version,snmp_auth_protocol,snmp_priv_protocol,snmp_context,snmp_engine_id,snmp_community,snmp_username,snmp_password,snmp_priv_passphrase'
            table, identity, ordering = ('poller_item', 'host_id', 'host_id,local_data_id,rrd_name') if cache else ('host', 'id', 'id')
            return harness.sql(f'SELECT {identity},SHA2(CONCAT_WS(CHAR(31),{fields}),256) FROM {prefix}{table} WHERE {identity} IN ({selected}) ORDER BY {ordering}')

        def all_snapshots():
            return [snapshot(prefix, cache) for prefix in ['', 'create_remote.'] for cache in [False, True]]

        def select(fields, **updates):
            result = dict(fields)
            for field, value in updates.items():
                result[f'device_state[snmp][apply_{field}]'] = '1'
                result[f'device_state[snmp][{field}]'] = value
            return result

        form = SnmpForm(harness, session, ids)
        form.path = form.path.replace('/disable?', '/snmp?')
        status, body = form.request()
        check(status == 200 and 'bulk-stored-' not in body, 'bulk SNMP never displays stored credentials')
        untouched = form.fields()
        before = all_snapshots()
        before_actions = actions()
        check(form.apply(untouched) == 422 and all_snapshots() == before and actions() == before_actions, 'untouched bulk SNMP form leaves settings credentials and caches unchanged')
        fields = select(untouched, snmp_context='bulk-context')
        for table, name in [('data_input_data', 'bulk SNMP rejects nontransactional primary cache participants before writes'), ('create_remote.poller_item', 'bulk SNMP rejects nontransactional collector cache participants before writes')]:
            harness.sql(f'ALTER TABLE {table} ENGINE=MyISAM')
            nontransactional.add(table)
            check(form.apply(fields) == 502 and all_snapshots() == before and actions() == before_actions, name)
            harness.sql(f'ALTER TABLE {table} ENGINE=InnoDB')
            nontransactional.remove(table)
        check(form.request(fields=fields, origin=False)[0] == 422, 'bulk SNMP requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_state[_token]')
        check(form.apply(missing) == 422, 'bulk SNMP requires CSRF token')
        check(form.apply(fields | {'device_state[snmp][poller_id]': '2'}) == 422, 'bulk SNMP rejects unrelated nested fields')
        harness.sql(f'UPDATE host SET snmp_version=1 WHERE id={ids[1]}')
        check(form.apply(fields) == 409, 'bulk SNMP rejects concurrent protocol changes')
        harness.sql(f'UPDATE host SET snmp_version=2 WHERE id={ids[1]}')
        harness.sql(f"UPDATE host SET snmp_username='valid-first',snmp_password='valid-first-pass' WHERE id={ids[0]}")
        before = all_snapshots()
        invalid = select(fields, snmp_version='3', snmp_auth_protocol='SHA256')
        check(form.apply(invalid) == 422, 'bulk SNMP validates all stored credentials before writes')
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_version=2').strip() == '2', 'invalid stored credentials leave entire bulk selection unchanged')
        check(all_snapshots() == before and actions() == before_actions, 'invalid stored credentials leave every collector and polling cache unchanged')
        check(form.apply(fields) == 200, 'bulk SNMP keeps each device credentials through Symfony')
        check(actions()[len(before_actions):] == [[['4', ids]]], 'bulk SNMP invokes action 4 once for the complete selection')
        for index, device in enumerate(ids):
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id={device} AND snmp_community=\'bulk-stored-{index}\'').strip() == '1', 'bulk SNMP preserves individual stored communities')
            check(harness.sql(f"SELECT COUNT(*) FROM poller_item WHERE host_id={device} AND action=0 AND snmp_community='bulk-stored-{index}'").strip() != '0', 'bulk SNMP rebuilds polling rows with each devices credentials')
        fields = form.fields()
        replacement = select(fields, snmp_community='bulk-new-secret') | {'device_state[snmp][keep_credentials]': 'replace'}
        before = all_snapshots()
        before_actions = actions()
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_snmp BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.snmp_community='bulk-new-secret' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk SNMP fixture'; END IF; END$$\nDELIMITER ;")
        triggers.add('reject_bulk_snmp')
        status, body = form.request(fields=replacement)
        check(status == 502 and 'bulk-new-secret' not in body, 'bulk SNMP failure hides submitted secrets')
        check(snapshot() == before[0], 'bulk SNMP failure rolls back entire primary selection')
        check(all_snapshots() == before, 'bulk SNMP later primary failure preserves collector credentials and caches')
        check(actions() == before_actions, 'rejected bulk SNMP does not invoke action 4')
        harness.sql('DROP TRIGGER reject_bulk_snmp')
        triggers.remove('reject_bulk_snmp')
        # Fail after an earlier device's remote host and cache have been updated.
        cache_failure = select(fields, snmp_community='bulk-cache-failure-secret') | {'device_state[snmp][keep_credentials]': 'replace'}
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_snmp_cache BEFORE INSERT ON poller_item FOR EACH ROW BEGIN IF NEW.host_id={ids[1]} AND NEW.snmp_community='bulk-cache-failure-secret' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk cache fixture'; END IF; END$$\nDELIMITER ;")
        triggers.add('reject_bulk_snmp_cache')
        check(form.apply(cache_failure) == 502 and all_snapshots() == before, 'bulk SNMP later cache failure rolls back primary and collector credentials')
        check(actions() == before_actions, 'bulk SNMP cache rejection invokes no action callback')
        harness.sql('DROP TRIGGER reject_bulk_snmp_cache')
        triggers.remove('reject_bulk_snmp_cache')
        status, body = form.request(fields=replacement)
        check(status == 200, 'bulk SNMP replaces credentials through Symfony')
        check('Selected device SNMP settings updated.' in body, 'bulk SNMP redirect displays its completion notice')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_community='bulk-new-secret'").strip() == '2', 'bulk SNMP persists replacement credentials')
        check(harness.sql(f"SELECT COUNT(*) FROM create_remote.host WHERE id={ids[0]} AND snmp_community='bulk-new-secret'").strip() == '1', 'bulk SNMP verifies remote credentials')
        check(all(harness.sql(f"SELECT COUNT(*) FROM {prefix}poller_item WHERE host_id IN ({selected}) AND snmp_community='bulk-new-secret' AND action=0").strip() == count for prefix, count in [('', '2'), ('create_remote.', '1')]), 'bulk SNMP replacement reaches primary and collector polling rows')
        v3 = select(form.fields(), snmp_version='3', snmp_username='bulk-user', snmp_auth_protocol='SHA384', snmp_password='bulk-auth-secret', snmp_priv_protocol='AES', snmp_priv_passphrase='bulk-privacy-secret') | {'device_state[snmp][keep_credentials]': 'replace'}
        check(form.apply(v3) == 200, 'bulk SNMP applies validated version 3 credentials')
        check(all(harness.sql(f"SELECT COUNT(*) FROM {prefix}poller_item WHERE host_id IN ({selected}) AND action=0 AND snmp_version=3 AND snmp_password='bulk-auth-secret' AND snmp_priv_passphrase='bulk-privacy-secret'").strip() == count for prefix, count in [('', '2'), ('create_remote.', '1')]), 'bulk SNMP version 3 secrets reach primary and remote polling caches')
        untouched = form.fields()
        before = all_snapshots()
        check(form.apply(untouched) == 422 and all_snapshots() == before, 'untouched version 3 bulk form retains every secret and protocol')
        # Retained passphrases are validated for each device, at the byte boundary.
        harness.sql(f"UPDATE host SET snmp_password='seven77' WHERE id={ids[1]}")
        before = all_snapshots()
        auth = select(form.fields(), snmp_auth_protocol='SHA256')
        language = harness.sql(f"SELECT JSON_ARRAY(value) FROM settings_user WHERE user_id={actor} AND name='user_language'").strip()
        try:
            harness.sql(f"REPLACE INTO settings_user (user_id,name,value) VALUES ({actor},'user_language','fr-FR')")
            status, body = form.request(fields=auth)
            check(status == 422 and all_snapshots() == before, 'bulk SNMP rejects a later stored seven-byte passphrase without writes')
            check('Les paramètres SNMP et les identifiants enregistrés sont incompatibles.' in body and 'SNMP settings and stored credentials' not in body, 'bulk SNMP stored passphrase denial uses the actual actor French preference')
        finally:
            harness.sql(f"DELETE FROM settings_user WHERE user_id={actor} AND name='user_language'")
            if language:
                encoded = json.loads(language)[0].encode('utf-8').hex()
                harness.sql(f"INSERT INTO settings_user (user_id,name,value) VALUES ({actor},'user_language',CONVERT(0x{encoded} USING utf8mb4))" if encoded else f"INSERT INTO settings_user (user_id,name,value) VALUES ({actor},'user_language','')")
        harness.sql(f"UPDATE host SET snmp_password='eight888' WHERE id={ids[1]}")
        auth = select(form.fields(), snmp_auth_protocol='SHA256')
        check(form.apply(auth) == 200, 'bulk SNMP accepts stored eight-byte passphrases')
        check(harness.sql(f"SELECT COUNT(*) FROM poller_item WHERE host_id={ids[0]} AND snmp_password='bulk-auth-secret'").strip() == '1' and harness.sql(f"SELECT COUNT(*) FROM poller_item WHERE host_id={ids[1]} AND snmp_password='eight888'").strip() == '1', 'bulk SNMP keeps distinct stored authentication secrets in polling rows')
        harness.sql(f"UPDATE host SET snmp_password=CONCAT('bad',CHAR(0),'text') WHERE id={ids[1]}")
        before = all_snapshots()
        check(form.apply(select(form.fields(), snmp_auth_protocol='SHA512')) == 422 and all_snapshots() == before, 'bulk SNMP rejects stored NUL credentials before every write')
        harness.sql(f"UPDATE host SET snmp_password='eight888' WHERE id={ids[1]}")
        before = all_snapshots()
        for value in ['x' * 51, 'bad\0text']:
            malformed = select(form.fields(), snmp_password=value) | {'device_state[snmp][keep_credentials]': 'replace'}
            check(form.apply(malformed) == 422 and all_snapshots() == before, 'bulk SNMP rejects oversized and NUL replacement credentials without writes')
        leave = select(form.fields(), snmp_version='2')
        check(form.apply(leave) == 200, 'bulk SNMP permits leaving version 3')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND snmp_username='bulk-user' AND snmp_priv_passphrase='bulk-privacy-secret' AND snmp_auth_protocol='SHA256'").strip() == '2', 'bulk version change preserves every unchecked credential and protocol')
        query_id = int(harness.sql('SELECT id FROM snmp_query ORDER BY id LIMIT 1').strip())
        for prefix, device in [('', ids[0]), ('', ids[1]), ('create_remote.', ids[0])]:
            harness.sql(f"INSERT INTO {prefix}host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES ({device},{query_id},1); INSERT INTO {prefix}poller_reindex (host_id,data_query_id,action,op,assert_value,arg1) VALUES ({device},{query_id},0,'=','1','fixture')")
        off = select(form.fields(), snmp_version='0')
        check(form.apply(off) == 200, 'bulk SNMP disables protocol through Symfony')
        for prefix in ['', 'create_remote.']:
            check(harness.sql(f'SELECT COUNT(*) FROM {prefix}host_snmp_query WHERE host_id IN ({selected}) AND reindex_method<>0').strip() == '0' and harness.sql(f'SELECT COUNT(*) FROM {prefix}poller_reindex WHERE host_id IN ({selected})').strip() == '0', 'bulk SNMP disabling clears primary and remote reindex state')
        audit_text = f'INVENTORY: User {actor} confirmed changed SNMP settings for devices ' + ','.join(map(str, ids))
        result = harness.php('-r', 'require "include/global.php"; $log=is_file(cacti_log_file()) ? file_get_contents(cacti_log_file()) : ""; echo str_contains($log,' + json.dumps(audit_text) + ') ? "recorded" : "missing";')
        check(result['exit'] == 0 and result['stdout'] == 'recorded', 'bulk SNMP success emits the actor and complete-selection audit line')
        result = harness.php('-r', 'require "include/global.php"; $clean=true; foreach ([cacti_log_file(), sys_get_temp_dir()."/cacti-sql.log"] as $path) { if (is_file($path)) { $log=file_get_contents($path); foreach (["bulk-new-secret","bulk-auth-secret","bulk-privacy-secret","bulk-cache-failure-secret"] as $secret) { if (str_contains($log,$secret)) { $clean=false; } } } } echo $clean ? "clean" : "leaked";')
        check(result['exit'] == 0 and result['stdout'] == 'clean', 'bulk SNMP secrets stay out of database diagnostics')
    finally:
        for table in nontransactional:
            harness.sql(f'ALTER TABLE {table} ENGINE=InnoDB')
        for trigger in triggers:
            harness.sql(f'DROP TRIGGER {trigger}')
        if hook:
            harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action'")
        if ids:
            selected = ','.join(map(str, ids))
            for prefix in ['', 'create_remote.']:
                harness.sql(f'DELETE did FROM {prefix}data_input_data did JOIN {prefix}data_template_data dtd ON dtd.id=did.data_template_data_id JOIN {prefix}data_local dl ON dl.id=dtd.local_data_id WHERE dl.host_id IN ({selected})')
                harness.sql(f'DELETE dtr FROM {prefix}data_template_rrd dtr JOIN {prefix}data_local dl ON dl.id=dtr.local_data_id WHERE dl.host_id IN ({selected})')
                harness.sql(f'DELETE dtd FROM {prefix}data_template_data dtd JOIN {prefix}data_local dl ON dl.id=dtd.local_data_id WHERE dl.host_id IN ({selected})')
                harness.sql(f'DELETE FROM {prefix}data_local WHERE host_id IN ({selected}); DELETE FROM {prefix}host WHERE id IN ({selected}); DELETE FROM {prefix}poller_item WHERE host_id IN ({selected}); DELETE FROM {prefix}host_snmp_query WHERE host_id IN ({selected}); DELETE FROM {prefix}poller_reindex WHERE host_id IN ({selected})')
        if template:
            harness.sql(f'DELETE did FROM data_input_data did JOIN data_template_data dtd ON dtd.id=did.data_template_data_id WHERE dtd.data_template_id={template}; DELETE FROM data_template_rrd WHERE data_template_id={template}; DELETE FROM data_template_data WHERE data_template_id={template}; DELETE FROM data_template WHERE id={template}')
