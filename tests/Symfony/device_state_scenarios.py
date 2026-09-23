"""Bulk state changes through Symfony confirmation and the isolated worker."""
import json
from pathlib import Path
from urllib.parse import urlencode
from device_collector_scenarios import CollectorForm
from device_edit_scenarios import Inputs


class StateForm(CollectorForm):
    def __init__(self, harness, session, ids, enabled=False):
        self.harness = harness
        self.session = session
        self.path = '/app.php/inventory/devices/' + ('enable' if enabled else 'disable') + '?' + urlencode({'ids[]': ids}, doseq=True)

    def fields(self):
        status, body = self.request()
        if status != 200:
            raise AssertionError(f'State form did not load: {status}')
        parser = Inputs()
        parser.feed(body)
        return parser.fields

    def apply(self, fields=None):
        return self.request(fields=self.fields() if fields is None else fields)[0]


def verify_device_state(harness, session, user_id, ids, hidden, check):
    ids = ids[:2]
    selected = ','.join(map(str, ids))
    disable = StateForm(harness, session, ids)
    enable = StateForm(harness, session, ids, True)
    before = harness.sql(f'SELECT disabled,status,description,hostname,site_id,poller_id,host_template_id FROM host WHERE id IN ({selected}) ORDER BY id')
    trigger = False
    saved_agent = harness.sql("SELECT value FROM settings WHERE name='enable_snmp_agent'").strip()
    try:
        harness.sql("REPLACE INTO settings (name,value) VALUES ('enable_snmp_agent','on')")
        check(harness.php('-r', 'require "include/global.php"; snmpagent_cache_install();')['exit'] == 0, 'bulk state SNMP-agent fixture initialized')
        fields = disable.fields()
        check('device_state[_token]' in fields, 'bulk state confirmation includes CSRF')
        check(harness.sql(f'SELECT disabled,status,description,hostname,site_id,poller_id,host_template_id FROM host WHERE id IN ({selected}) ORDER BY id') == before, 'bulk state GET does not mutate devices')
        for invalid in [[], [ids[0], ids[0]], [0], ['01'], range(1, 102)]:
            check(StateForm(harness, session, list(invalid)).request()[0] == 400, 'bulk state rejects invalid selection: ' + str(invalid))
        for unavailable in [hidden, 16777215]:
            check(StateForm(harness, session, [ids[0], unavailable]).request()[0] == 404, 'bulk state hides missing and inaccessible devices')
        check(disable.request(fields=fields, origin=False)[0] == 422, 'bulk state requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_state[_token]')
        check(disable.apply(missing) == 422, 'bulk state requires CSRF token')
        check(disable.apply(fields | {'device_state[extra]': 'on'}) == 422, 'bulk state rejects unrelated fields')
        check(disable.apply(fields | {'device_state[selection]': json.dumps({str(ids[0]): 'a' * 64})}) == 422, 'bulk state rejects query and confirmation selection mismatch')
        harness.sql(f'UPDATE host SET host_template_id=16777214 WHERE id={ids[1]}')
        check(disable.apply(fields) == 409, 'one stale device rejects the entire bulk state selection')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled=''").strip() == '2', 'stale bulk state leaves all devices enabled')
        harness.sql(f'UPDATE host SET host_template_id=0 WHERE id={ids[1]}')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_state BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.disabled='on' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk state failure'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(disable.apply() == 502, 'bulk state SQL failure cannot report success')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled=''").strip() == '2', 'bulk state SQL failure rolls back the whole primary batch')
        harness.sql('DROP TRIGGER reject_bulk_state')
        trigger = False
        check(disable.apply() == 200, 'bulk state confirmation disables selected devices')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled='on' AND status=0").strip() == '2', 'bulk disable resets status on every selected device')
        check(harness.sql(f"SELECT value FROM snmpagent_cache WHERE name='cactiApplDeviceStatus' AND otype='DATA' AND oid LIKE '%.{ids[0]}'").strip() == '4', 'bulk disable refreshes SNMP-agent status')
        harness.sql(f'UPDATE host SET status=3 WHERE id={ids[0]}')
        check(disable.apply() == 200, 'repeated bulk disable repairs stale primary status')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled='on' AND status=0").strip() == '2', 'repeated disable confirms reset status for every device')
        marker = harness.sql("SELECT value FROM settings WHERE name='poller_replicate_device_cache_crc_1'")
        check(disable.apply() == 200, 'bulk state unchanged confirmation succeeds')
        check(harness.sql("SELECT value FROM settings WHERE name='poller_replicate_device_cache_crc_1'") == marker, 'bulk state no-op leaves cache markers unchanged')
        stale = enable.fields()
        harness.sql(f'DELETE FROM user_auth_perms WHERE user_id={user_id} AND type=3 AND item_id={ids[1]}')
        check(enable.apply(stale) == 404, 'bulk state rechecks visibility on submission')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled='on'").strip() == '2', 'revoked bulk state visibility prevents all writes')
        harness.sql(f'INSERT INTO user_auth_perms (user_id,item_id,type) VALUES ({user_id},{ids[1]},3)')
        check(enable.apply() == 200, 'bulk state confirmation enables selected devices')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND disabled=''").strip() == '2', 'bulk enable updates every selected device')
        check(harness.sql(f"SELECT value FROM snmpagent_cache WHERE name='cactiApplDeviceStatus' AND otype='DATA' AND oid LIKE '%.{ids[0]}'").strip() == '0', 'bulk enable refreshes SNMP-agent status')
    finally:
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('enable_snmp_agent',CONVERT(UNHEX('{saved_agent.encode().hex()}') USING utf8mb4))")
        if trigger:
            harness.sql('DROP TRIGGER reject_bulk_state')
        for device, line in zip(ids, before.strip('\n').splitlines()):
            values = line.split('\t')
            harness.sql(f"UPDATE host SET disabled='{values[0]}',status={values[1]},host_template_id={values[-1]} WHERE id={device}")
        harness.sql(f'REPLACE INTO user_auth_perms (user_id,item_id,type) VALUES ({user_id},{ids[1]},3)')


def verify_remote_device_state(harness, session, device_id, poller, check):
    description_hex = 'bulk-local-fixture 🌏'.encode().hex().upper()
    local = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,disabled) VALUES (CONVERT(UNHEX('{description_hex}') USING utf8mb4),'bulk.invalid',1,''); SELECT LAST_INSERT_ID()").strip())
    ids = [device_id, local]
    disable = StateForm(harness, session, ids)
    enable = StateForm(harness, session, ids, True)
    trigger = False
    probe = False
    probe_source = Path(__file__).with_name('device_state_connection_probe.php').read_text().removeprefix('<?php')
    try:
        check(harness.php('-r', probe_source, 'install')['exit'] == 0,
              'bulk state runtime session observer installed in disposable container')
        probe = True
        harness.sql(f"UPDATE host SET disabled='' WHERE id={device_id}; UPDATE create_remote.host SET disabled='' WHERE id={device_id}")
        harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
        check(disable.apply() == 502, 'bulk state preflights offline collectors before any writes')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({device_id},{local}) AND disabled=''").strip() == '2', 'offline bulk state leaves all primary devices unchanged')
        harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        check(disable.apply() == 200, 'bulk state updates mixed primary and remote selections')
        check(harness.sql(f"SELECT disabled FROM create_remote.host WHERE id={device_id}").strip() == 'on', 'bulk state verifies remote disabled state')
        harness.sql("CREATE TRIGGER create_remote.reject_bulk_state BEFORE UPDATE ON create_remote.host FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='remote bulk state failure'")
        trigger = True
        check(enable.apply() == 502, 'bulk state remote failure cannot report success')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({device_id},{local}) AND disabled='on'").strip() == '2', 'remote bulk state failure rolls back primary batch')
        harness.sql('DROP TRIGGER create_remote.reject_bulk_state')
        trigger = False
        # Reject a later replication step after the remote enabled flag changed.
        # The worker must not mistake matching flags for a successful operation.
        harness.sql(f"INSERT INTO host_snmp_cache (host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES ({device_id},16777213,'bulkStateFailure','before','1','.1.3.6.1')")
        try:
            harness.sql("CREATE TRIGGER create_remote.reject_bulk_reindex BEFORE INSERT ON create_remote.host_snmp_cache FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='remote reindex failure'")
            try:
                check(enable.apply() == 502, 'remote reindex failure cannot report successful bulk enable')
                check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({device_id},{local}) AND disabled='on'").strip() == '2', 'remote reindex failure rolls back the whole primary batch')
                check(harness.sql(f"SELECT disabled='' FROM create_remote.host WHERE id={device_id}").strip() == '1', 'reindex failure is exercised after remote flag mutation')
            finally:
                harness.sql('DROP TRIGGER create_remote.reject_bulk_reindex')
            check(enable.apply() == 200, 'bulk enable recovers after remote reindex rejection')
            check(harness.sql(f"SELECT field_value FROM create_remote.host_snmp_cache WHERE host_id={device_id} AND snmp_query_id=16777213").strip() == 'before', 'recovered bulk enable replicates the previously rejected cache row')
        finally:
            for prefix in ['', 'create_remote.']:
                harness.sql(f'DELETE FROM {prefix}host_snmp_cache WHERE host_id={device_id} AND snmp_query_id=16777213')
        check(enable.apply() == 200, 'bulk state recovers after remote write rejection')
        check(harness.sql(f"SELECT disabled='' FROM create_remote.host WHERE id={device_id}").strip() == '1', 'bulk state verifies remote enabled state')
        harness.sql(f"UPDATE create_remote.host SET disabled='on' WHERE id={device_id}")
        check(enable.apply() == 200, 'bulk state repairs remote drift when primary already matches')
        check(harness.sql(f"SELECT disabled='' FROM create_remote.host WHERE id={device_id}").strip() == '1', 'bulk state verifies repaired remote copy')
        marker = harness.sql(f"SELECT value FROM settings WHERE name='poller_replicate_device_cache_crc_{poller}'")
        check(enable.apply() == 200, 'bulk state confirms unchanged primary and remote copies')
        check(harness.sql(f"SELECT value FROM settings WHERE name='poller_replicate_device_cache_crc_{poller}'") == marker, 'verified remote no-op does not invalidate collector cache')
        observations = harness.php('-r', 'echo file_get_contents("/artifacts/state-session-modes.jsonl");')
        check(observations['exit'] == 0, 'bulk state runtime session observations are readable')
        writers = set()
        for line in observations['stdout'].splitlines():
            observation = json.loads(line)
            writers.add(observation['writer'])
            connections = {row['db']: row for row in observation['sessions']}
            check({'cacti', 'create_remote'} <= connections.keys(), 'state writer observes primary and preflighted remote sessions')
            for database in ['cacti', 'create_remote']:
                row = connections[database]
                check('STRICT_TRANS_TABLES' in row['mode'].split(','),
                      f"{observation['writer']} has strict SQL mode before writes on {database}")
                check(all(row[key] == 'utf8mb4' for key in ['client', 'connection', 'results']),
                      f"{observation['writer']} has utf8mb4 before writes on {database}")
        check(writers == {'api_device_enable_devices', 'api_device_disable_devices'},
              'runtime session checks exercised both enable and disable writers')
        check(harness.sql(f'SELECT HEX(description) FROM host WHERE id={local}').strip() == description_hex,
              'enable and disable preserve four-byte device descriptions and accept their revisions')
        harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
        check(enable.apply() == 502, 'bulk no-op cannot confirm an offline remote copy')
    finally:
        if probe:
            check(harness.php('-r', probe_source, 'restore')['exit'] == 0,
                  'bulk state observer restores original container source')
        if trigger:
            harness.sql('DROP TRIGGER create_remote.reject_bulk_state')
        harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql(f'DELETE FROM host WHERE id={local}')


def verify_device_statistics(harness, session, ids, check, remote=None, hidden=None):
    check(harness.php('-r', 'require "include/global.php"; function setup_statistics_hook() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); } setup_statistics_hook();')['exit'] == 0, 'statistics action hook registered')
    try:
        ids = ids[:2]
        selected = ','.join(map(str, ids))
        form = StateForm(harness, session, ids)
        form.path = form.path.replace('/disable?', '/clear-statistics?')
        columns = 'min_time,max_time,cur_time,avg_time,total_polls,failed_polls,availability'
        identity = 'id,description,hostname,disabled,status,site_id,poller_id,host_template_id'
        before = harness.sql(f'SELECT {identity} FROM host WHERE id IN ({selected}) ORDER BY id')
        seed = 'min_time=2,max_time=8,cur_time=4,avg_time=5,total_polls=10,failed_polls=2,availability=80'
        def seeded(prefix=''):
            harness.sql(f'UPDATE {prefix}host SET {seed} WHERE id IN ({selected})')
        def counters(prefix=''):
            return harness.sql(f'SELECT {columns} FROM {prefix}host WHERE id IN ({selected}) ORDER BY id')
        seeded()
        if remote:
            seeded('create_remote.')
        initial = counters()
        def actions():
            events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
            return [json.loads(line)['args'] for line in events.splitlines()
                    if json.loads(line).get('callback') == 'statistics_action']
        before_actions = actions()
        fields = form.fields()
        check(counters() == initial, 'statistics confirmation GET does not reset counters')
        check(form.request(fields=fields, origin=False)[0] == 422, 'statistics reset requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_state[_token]')
        check(form.apply(missing) == 422, 'statistics reset requires a CSRF token')
        check(form.apply(fields | {'device_state[extra]': '1'}) == 422, 'statistics reset rejects extra fields')
        check(form.apply(fields | {'device_state[selection]': json.dumps({str(ids[0]): 'a' * 64})}) in (409,422), 'statistics reset rejects stale or mismatched selection')
        if hidden:
            check(form.request(path=form.path + '&ids[]=' + str(hidden))[0] == 404, 'statistics reset conceals inaccessible devices')
        if remote:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={remote}")
            try:
                check(form.apply() == 502 and counters() == initial, 'offline collector rejects statistics reset before primary writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={remote}')
        prefix = 'create_remote.' if remote else ''
        last = ids[0] if remote else ids[-1]
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_statistics BEFORE UPDATE ON {prefix}host FOR EACH ROW BEGIN IF NEW.id={last} AND NEW.total_polls=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='statistics fixture rejection'; END IF; END$$\nDELIMITER ;")
        try:
            check(form.apply() == 502, 'statistics SQL rejection reports uncertain outcome')
            check(counters() == initial, 'statistics SQL rejection rolls back entire primary selection')
        finally:
            harness.sql(f'DROP TRIGGER {prefix}reject_statistics')
        check(actions() == before_actions, 'rejected statistics resets do not invoke action 5 callbacks')
        check(form.apply() == 200, 'statistics confirmation resets selected devices')
        check(actions()[len(before_actions):] == [[['5', sorted(ids)]]], 'statistics reset invokes action 5 once with the complete selection')
        predicate = 'min_time=9.99999 AND max_time=0 AND cur_time=0 AND avg_time=0 AND total_polls=0 AND failed_polls=0 AND availability=100'
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {predicate}').strip() == str(len(ids)), 'all seven primary statistics match the legacy reset')
        if remote:
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={ids[0]} AND {predicate}').strip() == '1', 'remote statistics match the legacy reset')
        check(harness.sql(f'SELECT {identity} FROM host WHERE id IN ({selected}) ORDER BY id') == before, 'statistics reset retains device identity configuration and state')
        before_repeat = actions()
        check(form.apply() == 200, 'statistics reset also succeeds for already-reset counters')
        check(actions()[len(before_repeat):] == [[['5', sorted(ids)]]], 'repeated statistics reset invokes action 5 once')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action'")


def verify_template_synchronization(harness, session, check, poller=1):
    graphs = [int(value) for value in harness.sql('SELECT id FROM graph_templates WHERE id NOT IN (SELECT graph_template_id FROM snmp_query_graph) ORDER BY id LIMIT 3').splitlines()]
    query = int(harness.sql('SELECT MIN(id) FROM snmp_query').strip())
    template = int(harness.sql("INSERT INTO host_template (hash,name) VALUES ('sync-template-fixture','Synchronization fixture'); SELECT LAST_INSERT_ID()").strip())
    device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,host_template_id,snmp_version,availability_method) VALUES ('sync-device-fixture','127.0.0.1',{poller},{template},0,0); SELECT LAST_INSERT_ID()").strip())
    unassigned = int(harness.sql("INSERT INTO host (description,hostname,poller_id,host_template_id,snmp_version,availability_method) VALUES ('sync-unassigned-fixture','127.0.0.1',1,0,0,0); SELECT LAST_INSERT_ID()").strip())
    ids = sorted([device, unassigned])
    form = StateForm(harness, session, ids)
    form.path = form.path.replace('/disable?', '/sync-template?')
    prefix = 'create_remote.' if poller > 1 else ''
    retained_graph = None
    trigger = False
    marker_rows = harness.sql("SELECT value FROM settings WHERE name='time_last_change_device'").splitlines()
    original_marker = marker_rows[0] if marker_rows else None
    check(harness.php('-r', 'require "include/global.php"; function setup_sync_hooks() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); api_plugin_register_hook("compatibility_test","device_template_change","compatibility_template_sync","setup.php",true); } setup_sync_hooks();')['exit'] == 0, 'template synchronization hook registered')
    def actions():
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        return [json.loads(line)['args'] for line in events.splitlines() if json.loads(line).get('callback') == 'statistics_action']
    def template_events():
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        return [json.loads(line)['args'] for line in events.splitlines() if json.loads(line).get('callback') == 'template_sync']
    try:
        harness.sql("INSERT INTO settings (name,value) VALUES ('time_last_change_device','sync-noop-sentinel') ON DUPLICATE KEY UPDATE value='sync-noop-sentinel'")
        skipped = StateForm(harness, session, [unassigned])
        skipped.path = skipped.path.replace('/disable?', '/sync-template?')
        before_skipped_actions = actions()
        before_skipped_templates = template_events()
        check(skipped.apply(skipped.fields()) == 200, 'template synchronization accepts an all-unassigned selection as a no-op')
        check(actions() == before_skipped_actions and template_events() == before_skipped_templates, 'all-unassigned template synchronization invokes no mutation callbacks')
        check(harness.sql("SELECT value FROM settings WHERE name='time_last_change_device'").strip() == 'sync-noop-sentinel', 'all-unassigned template synchronization preserves the device-change marker')
        retained_graph = int(harness.sql(f"INSERT INTO graph_local (host_id,graph_template_id) VALUES ({device},{graphs[2]}); SELECT LAST_INSERT_ID()").strip())
        harness.sql(f'INSERT INTO host_graph (host_id,graph_template_id) VALUES ({device},{graphs[2]})')
        harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graphs[0]})')
        harness.sql(f'INSERT INTO host_template_snmp_query (host_template_id,snmp_query_id) VALUES ({template},{query})')
        harness.sql(f'INSERT INTO host_graph (host_id,graph_template_id) VALUES ({device},{graphs[1]})')
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={device}')
            harness.sql(f'INSERT INTO create_remote.host_graph SELECT * FROM host_graph WHERE host_id={device}')
        fields = form.fields()
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device} AND graph_template_id={graphs[0]}').strip() == '0', 'template synchronization GET does not add associations')
        check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id={device} AND snmp_query_id={query}').strip() == '0', 'template synchronization GET does not add data-query associations')
        check(form.request(fields=fields, origin=False)[0] == 422, 'template synchronization requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_state[_token]')
        check(form.apply(missing) == 422, 'template synchronization requires CSRF token')
        check(form.apply(fields | {'device_state[extra]': '1'}) == 422, 'template synchronization rejects unexpected fields')
        harness.sql(f"UPDATE host SET description='changed-sync-fixture' WHERE id={device}")
        check(form.apply(fields) == 409, 'template synchronization rejects stale device revisions')
        harness.sql(f"UPDATE host SET description='sync-device-fixture' WHERE id={device}")
        if poller > 1:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.apply() == 502, 'template synchronization rejects offline collectors before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        before = actions()
        harness.sql(f"CREATE TRIGGER {prefix}reject_template_sync BEFORE INSERT ON {prefix}host_graph FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='sync association rejection'")
        trigger = True
        check(form.apply() == 502, 'template synchronization association failure cannot report success')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device} AND graph_template_id={graphs[0]}').strip() == '0', 'template synchronization failure rolls back primary associations')
        check(actions() == before, 'failed synchronization does not invoke the bulk action callback')
        harness.sql(f'DROP TRIGGER {prefix}reject_template_sync')
        trigger = False
        before_templates = template_events()
        check(form.apply() == 200, 'template synchronization saves through Symfony')
        check(template_events()[len(before_templates):] == [[{'device_id': device, 'device_template_id': template}]], 'template synchronization invokes the template-change hook once per assigned device')
        check(actions()[len(before):] == [[['7', ids]]], 'template synchronization invokes action 7 once with complete selection')
        configured_method = harness.php('-r', 'require "include/global.php"; echo read_config_option("reindex_method");')
        check(configured_method["exit"] == 0 and configured_method["stdout"] in ("0", "1", "2", "3"), "template synchronization resolves the effective default reindex method")
        for database in (['', 'create_remote.'] if poller > 1 else ['']):
            check(harness.sql(f'SELECT COUNT(*) FROM {database}host_graph WHERE host_id={device} AND graph_template_id={graphs[0]}').strip() == '1', 'template synchronization adds required graph associations')
            check(harness.sql(f'SELECT COUNT(*) FROM {database}host_graph WHERE host_id={device} AND graph_template_id={graphs[1]}').strip() == '0', 'template synchronization removes unused graph associations')
            check(harness.sql(f'SELECT COUNT(*) FROM {database}host_snmp_query WHERE host_id={device} AND snmp_query_id={query}').strip() == '1', 'template synchronization adds required data-query associations')
            check(harness.sql(f"SELECT reindex_method FROM {database}host_snmp_query WHERE host_id={device} AND snmp_query_id={query}").strip() == configured_method["stdout"], 'template synchronization preserves the configured data-query reindex method')
        if poller > 1:
            check(harness.sql(f'SELECT host_template_id FROM create_remote.host WHERE id={device}').strip() == str(template), 'remote template synchronization preserves assigned template identity')
        check(harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE id={retained_graph} AND host_id={device}').strip() == '1', 'template synchronization retains existing graphs')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device} AND graph_template_id={graphs[2]}').strip() == '1', 'template synchronization retains associations used by existing graphs')
        check(harness.sql(f'SELECT host_template_id FROM host WHERE id={unassigned}').strip() == '0', 'template synchronization skips unassigned devices')
        check(form.apply() == 200, 'template synchronization supports repeated synchronization')
    finally:
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_template_sync')
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action'")
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_template_change' AND `function`='compatibility_template_sync'")
        if retained_graph is not None:
            harness.sql(f'DELETE FROM graph_local WHERE id={retained_graph}')
        for database in (['', 'create_remote.'] if poller > 1 else ['']):
            harness.sql(f'DELETE FROM {database}host_snmp_cache WHERE host_id IN ({device},{unassigned}) AND snmp_query_id={query}; DELETE FROM {database}host_snmp_query WHERE host_id IN ({device},{unassigned}) AND snmp_query_id={query}')
            harness.sql(f'DELETE FROM {database}host_graph WHERE host_id IN ({device},{unassigned}); DELETE FROM {database}host WHERE id IN ({device},{unassigned})')
        harness.sql(f'DELETE FROM host_template_snmp_query WHERE host_template_id={template}; DELETE FROM host_template_graph WHERE host_template_id={template}; DELETE FROM host_template WHERE id={template}')
        if original_marker is None:
            harness.sql("DELETE FROM settings WHERE name='time_last_change_device'")
        else:
            harness.sql(f"UPDATE settings SET value=UNHEX('{original_marker.encode().hex()}') WHERE name='time_last_change_device'")


def verify_bulk_options(harness, session, check, poller=1):
    ids = []
    trigger = False
    prefix = 'create_remote.' if poller > 1 else ''
    check(harness.php('-r', 'require "include/global.php"; function setup_options_hook() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); } setup_options_hook();')['exit'] == 0, 'bulk options action hook registered')
    def actions():
        events = harness.command('cat', '/artifacts/plugin.jsonl')['stdout']
        return [json.loads(line)['args'] for line in events.splitlines() if json.loads(line).get('callback') == 'statistics_action']
    before_actions = actions()
    try:
        for index in range(2):
            owner = poller if index == 0 else 1
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,location,snmp_port,snmp_timeout,snmp_version,availability_method) VALUES ('bulk-options-{index}','127.0.0.1',{owner},'Original',161,500,0,0); SELECT LAST_INSERT_ID()").strip()))
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={ids[0]}')
        selected = ','.join(map(str, ids))
        form = StateForm(harness, session, ids)
        form.path = form.path.replace('/disable?', '/options?')
        fields = form.fields()
        # HTML parsers capture unchecked inputs too; mimic an actual browser.
        fields = {key: value for key, value in fields.items() if '[apply_' not in key}
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND location='Original' AND snmp_timeout=500").strip() == '2', 'bulk options GET does not modify selected devices')
        check(form.apply(fields) == 422, 'bulk options requires an explicit field selection')
        update = fields | {'device_state[options][apply_location]': '1', 'device_state[options][location]': 'Rack 東京', 'device_state[options][apply_snmp_timeout]': '1', 'device_state[options][snmp_timeout]': '750'}
        check(form.request(fields=update, origin=False)[0] == 422, 'bulk options requires same-origin CSRF')
        missing = dict(update)
        missing.pop('device_state[_token]')
        check(form.apply(missing) == 422, 'bulk options requires CSRF token')
        check(form.apply(update | {'device_state[options][poller_id]': '2'}) == 422, 'bulk options rejects assignment mass updates')
        check(form.apply(update | {'device_state[options][snmp_timeout]': '0'}) == 422, 'bulk options validates selected polling values')
        harness.sql(f"UPDATE host SET location='Concurrent' WHERE id={ids[1]}")
        check(form.apply(update) == 409, 'bulk options rejects concurrent option edits')
        harness.sql(f"UPDATE host SET location='Original' WHERE id={ids[1]}")
        if poller > 1:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.apply(update) == 502, 'bulk options rejects offline collectors before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        rejected = ids[0] if poller > 1 else ids[1]
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_bulk_options BEFORE UPDATE ON {prefix}host FOR EACH ROW BEGIN IF NEW.id={rejected} AND NEW.snmp_timeout=750 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='options fixture rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.apply(update) == 502, 'bulk options write failure cannot report success')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND location='Original' AND snmp_timeout=500").strip() == '2', 'bulk options failure rolls back entire primary batch')
        harness.sql(f'DROP TRIGGER {prefix}reject_bulk_options')
        trigger = False
        check(actions() == before_actions, 'rejected bulk options do not invoke action 4')
        check(form.apply(update) == 200, 'bulk options save through Symfony')
        check(actions()[len(before_actions):] == [[['4', sorted(ids)]]], 'bulk options invokes action 4 once for the complete selection')
        location_hex = 'Rack 東京'.encode().hex().upper()
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND HEX(location)='{location_hex}' AND snmp_timeout=750 AND snmp_port=161").strip() == '2', 'bulk options changes selected fields and preserves unchecked values')
        if poller > 1:
            check(harness.sql(f"SELECT COUNT(*) FROM create_remote.host WHERE id={ids[0]} AND HEX(location)='{location_hex}' AND snmp_timeout=750 AND snmp_port=161").strip() == '1', 'bulk options verifies remote values')
        cleared = {key: value for key, value in form.fields().items() if '[apply_' not in key}
        cleared |= {'device_state[options][apply_location]': '1', 'device_state[options][location]': '', 'device_state[options][snmp_timeout]': '0'}
        check(form.apply(cleared) == 200, 'bulk options allows clearing location and ignores unchecked invalid values')
        check(harness.sql(f"SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND location='' AND snmp_timeout=750").strip() == '2', 'bulk location clearing preserves polling values')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action'")
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_bulk_options')
        if ids:
            selected = ','.join(map(str, ids))
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                harness.sql(f'DELETE FROM {database}host WHERE id IN ({selected}); DELETE FROM {database}poller_item WHERE host_id IN ({selected})')
