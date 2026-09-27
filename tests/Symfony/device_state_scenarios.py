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
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}alter_statistics BEFORE UPDATE ON {prefix}host FOR EACH ROW BEGIN IF NEW.id={last} AND NEW.total_polls=0 THEN SET NEW.total_polls=1; END IF; END$$\nDELIMITER ;")
        try:
            check(form.apply() == 502, 'statistics reset rejects a successful write with altered stored values')
            check(actions() == before_actions, 'unconfirmed statistics resets do not invoke action 5 callbacks')
        finally:
            harness.sql(f'DROP TRIGGER {prefix}alter_statistics')
            if remote:
                seeded('create_remote.')
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
