"""Bulk state changes through Symfony confirmation and the isolated worker."""
import json
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
    try:
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
    finally:
        if trigger:
            harness.sql('DROP TRIGGER reject_bulk_state')
        for device, line in zip(ids, before.strip('\n').splitlines()):
            values = line.split('\t')
            harness.sql(f"UPDATE host SET disabled='{values[0]}',status={values[1]},host_template_id={values[-1]} WHERE id={device}")
        harness.sql(f'REPLACE INTO user_auth_perms (user_id,item_id,type) VALUES ({user_id},{ids[1]},3)')


def verify_remote_device_state(harness, session, device_id, poller, check):
    local = int(harness.sql("INSERT INTO host (description,hostname,poller_id,disabled) VALUES ('bulk-local-fixture','bulk.invalid',1,''); SELECT LAST_INSERT_ID()").strip())
    ids = [device_id, local]
    disable = StateForm(harness, session, ids)
    enable = StateForm(harness, session, ids, True)
    trigger = False
    try:
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
        check(enable.apply() == 200, 'bulk state recovers after remote write rejection')
        check(harness.sql(f"SELECT disabled='' FROM create_remote.host WHERE id={device_id}").strip() == '1', 'bulk state verifies remote enabled state')
    finally:
        if trigger:
            harness.sql('DROP TRIGGER create_remote.reject_bulk_state')
        harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql(f'DELETE FROM host WHERE id={local}')
