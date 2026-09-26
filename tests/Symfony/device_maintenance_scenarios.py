"""Device maintenance through Symfony and disposable local/remote collectors."""
import json

from device_association_scenarios import AssociationForm


def verify_device_maintenance(harness, session, check, poller=1):
    device = None
    trigger = False
    saved_debug = harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'").strip()
    prefix = 'create_remote.' if poller > 1 else ''
    try:
        device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,snmp_community,availability_method,status) VALUES ('maintenance-fixture','snmp',{poller},0,2,'public',2,3); SELECT LAST_INSERT_ID()").strip())
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={device}')
        form = AssociationForm(harness, session, device)
        form.path = f'/app.php/inventory/devices/{device}/maintenance'
        fields = form.fields() | {'device_maintenance[operation]': 'enable-debug', 'device_maintenance[query]': '0'}
        check(str(device) not in saved_debug.split(','), 'maintenance GET does not enable debug')
        check(form.request(fields=fields, origin=False)[0] == 422, 'maintenance requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_maintenance[_token]')
        check(form.request(fields=missing)[0] == 422, 'maintenance requires CSRF token')
        check(form.request(fields=fields | {'device_maintenance[operation]': 'shell'})[0] == 422, 'maintenance rejects unknown actions')
        check(form.request(fields=fields | {'device_maintenance[poller_id]': '2'})[0] == 422, 'maintenance rejects extra fields')
        check(form.request(fields=fields | {'device_maintenance[query]': '16777214'})[0] == 422, 'maintenance rejects unassociated queries')
        harness.sql(f"UPDATE host SET hostname='changed.invalid' WHERE id={device}")
        check(form.request(fields=fields)[0] == 409, 'maintenance rejects stale device settings')
        harness.sql(f"UPDATE host SET hostname='snmp' WHERE id={device}")
        if poller > 1:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.request(fields=fields)[0] == 502, 'maintenance refuses offline collector before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql(f"INSERT IGNORE INTO {prefix}settings (name,value) VALUES ('selective_device_debug','')")
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_maintenance BEFORE UPDATE ON {prefix}settings FOR EACH ROW BEGIN IF NEW.name='selective_device_debug' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='maintenance rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.request(fields=fields)[0] == 502, 'maintenance SQL rejection cannot report success')
        check(harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'").strip() == saved_debug, 'maintenance failure rolls back primary debug settings')
        harness.sql(f'DROP TRIGGER {prefix}reject_maintenance')
        trigger = False
        check(form.request(fields=fields)[0] == 200, 'maintenance enables debug through Symfony')
        check(str(device) in harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'").strip().split(','), 'maintenance confirms primary debug setting')
        if poller > 1:
            check(str(device) in harness.sql("SELECT value FROM create_remote.settings WHERE name='selective_device_debug'").strip().split(','), 'maintenance confirms remote debug setting')
        fields = form.fields() | {'device_maintenance[operation]': 'disable-debug', 'device_maintenance[query]': '0'}
        check(form.request(fields=fields)[0] == 200, 'maintenance disables debug through Symfony')
        check(str(device) not in harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'").strip().split(','), 'maintenance removes only selected debug device')
        fields = form.fields() | {'device_maintenance[operation]': 'refresh-cache', 'device_maintenance[query]': '0'}
        check(form.request(fields=fields)[0] == 200, 'maintenance refreshes polling cache through Symfony')
        if poller == 1:
            fields = form.fields() | {'device_maintenance[operation]': 'connectivity', 'device_maintenance[query]': '0'}
            status, body = form.request(fields=fields)
            check(status == 200 and 'Kadupul deterministic SNMP fixture' in body, 'maintenance connectivity probes the real SNMP fixture')
            target = int(harness.sql("SELECT id FROM snmp_query WHERE xml_path='<path_cacti>/resource/snmp_queries/interface.xml'").strip())
            harness.sql(f'INSERT INTO host_snmp_query (host_id,snmp_query_id,reindex_method) VALUES ({device},{target},2)')
            for operation in ['reindex', 'reload-query', 'query-diagnostics']:
                fields = form.fields() | {'device_maintenance[operation]': operation, 'device_maintenance[query]': str(0 if operation == 'reindex' else target)}
                status, body = form.request(fields=fields)
                check(status == 200 and 'Data queries reindexed.' in body, 'maintenance executes ' + operation + ' against the SNMP fixture')
                check(' -c public' not in body, 'maintenance diagnostics do not expose community arguments')
            # Exercise the real collector HTTP boundary with the credentials used by SNMP.
            # The loopback collector is only an authorization fixture, never a polling destination.
            collector = int(harness.sql("INSERT INTO poller (name,hostname,disabled) VALUES ('diagnostic-loopback','127.0.0.1',''); SELECT LAST_INSERT_ID()").strip())
            try:
                for action in ['ping', 'runquery']:
                    url = f'http://127.0.0.1/remote_agent.php?action={action}&safe_diagnostics=1&host_id={device}&data_query_id={target}'
                    response = harness.php('-r', 'echo file_get_contents(' + json.dumps(url) + ');')
                    check(response['exit'] == 0, 'collector diagnostic HTTP request completes')
                    payload = json.loads(response['stdout'])
                    check(payload.get('diagnostics_sanitized') is True, 'collector ' + action + ' returns sanitized diagnostics')
                    check(' -c public' not in response['stdout'], 'collector diagnostics redact actual SNMP credentials')
            finally:
                harness.sql(f'DELETE FROM poller WHERE id={collector}')
            harness.sql(f"UPDATE host SET disabled='on' WHERE id={device}")
            fields = form.fields() | {'device_maintenance[operation]': 'reindex', 'device_maintenance[query]': '0'}
            check(form.request(fields=fields)[0] == 422, 'maintenance does not report disabled device queries as reindexed')
    finally:
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_maintenance')
        if device:
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                for table in ['host_snmp_query','host_snmp_cache','poller_reindex','poller_item']:
                    harness.sql(f'DELETE FROM {database}{table} WHERE host_id={device}')
                harness.sql(f'DELETE FROM {database}host WHERE id={device}')
        # Fixture IDs only; retained debug settings are numeric lists.
        if not all(c in '0123456789,' for c in saved_debug):
            raise AssertionError('Unexpected debug fixture setting')
        harness.sql(f"REPLACE INTO settings (name,value) VALUES ('selective_device_debug','{saved_debug}')")
