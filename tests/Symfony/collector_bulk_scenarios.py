"""Collector mutation review regressions through real HTTP and InnoDB."""
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import Request
from device_edit_scenarios import Inputs


def verify_collector_bulk(harness, session, user_id, check):
    created = []
    hosts = []

    def request(path, fields=None, origin=True):
        req = Request(harness.base + path, data=None if fields is None else urlencode(fields).encode(),
                      headers={'Origin': harness.base} if origin else {})
        try:
            response = session.opener.open(req)
        except HTTPError as error:
            response = error
        with response:
            return response.status, response.read().decode('utf-8')

    def action(name, ids):
        return '/app.php/collectors/actions/' + name + '?' + urlencode([('ids[]', ident) for ident in ids])

    def form(path):
        status, body = request(path)
        check(status == 200, 'collector bulk confirmation loads without mutation')
        parser = Inputs()
        parser.feed(body)
        return parser.fields

    try:
        for index in range(2):
            created.append(int(harness.sql(f"INSERT INTO poller (name,hostname,dbhost,dbport,dbretries,total_time,avg_time,max_time,total_polls,last_sync) VALUES ('collector-bulk-{index}','collector-bulk-{index}.invalid','127.0.0.1',1,0,10,5,20,3,'2000-01-01'); SELECT LAST_INSERT_ID()").strip()))
        first, second = created
        disable = action('disable', created)
        fields = form(disable)
        check(harness.sql(f"SELECT COUNT(*) FROM poller WHERE id IN ({first},{second}) AND disabled='on'").strip() == '0',
              'collector confirmation GET leaves enabled state unchanged')
        check(request(disable, fields, origin=False)[0] == 422,
              'collector mutations require same-origin CSRF evidence')
        check(request(disable, {k: v for k, v in fields.items() if k != 'collector_bulk_action[_token]'})[0] == 422,
              'collector mutations require a CSRF token')
        check(request(disable, fields | {'collector_bulk_action[selection]': f'[{first}]'})[0] == 400,
              'collector mutation cannot replace the confirmed selection')
        check(request(disable, fields)[0] == 200,
              'collector disable persists through Symfony and redirects to its list')
        check(harness.sql(f"SELECT COUNT(*) FROM poller WHERE id IN ({first},{second}) AND disabled='on'").strip() == '2',
              'collector disable updates exactly the selected collectors')
        enable = action('enable', created)
        check(request(enable, form(enable))[0] == 200, 'collector enable persists through Symfony')
        check(harness.sql(f"SELECT COUNT(*) FROM poller WHERE id IN ({first},{second}) AND disabled=''").strip() == '2',
              'collector enable restores the selected collector states')
        clear = action('clear-statistics', created)
        check(request(clear, form(clear))[0] == 200, 'collector statistics reset persists through Symfony')
        check(harness.sql(f'SELECT SUM(total_time + avg_time + max_time + total_polls) FROM poller WHERE id IN ({first},{second})').strip() in ('0', '0.0'),
              'collector reset clears only timing and poll counters')
        for name in ('delete', 'disable', 'enable', 'clear-statistics', 'full-sync'):
            check(request(action(name, [1]))[0] == 400, 'primary collector is protected from forged selection: ' + name)

        snapshot = harness.sql(f'SELECT id,disabled FROM poller WHERE id IN ({first},{second}) ORDER BY id')
        fields = form(disable)
        harness.sql(f"DELIMITER //\nCREATE TRIGGER collector_review_reject BEFORE UPDATE ON poller FOR EACH ROW BEGIN IF NEW.id = {second} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'collector review failure'; END IF; END//\nDELIMITER ;")
        try:
            check(request(disable, fields)[0] == 502, 'collector database failure returns an uncertain outcome')
            check(harness.sql(f'SELECT id,disabled FROM poller WHERE id IN ({first},{second}) ORDER BY id') == snapshot,
                  'collector update failure rolls back every selected state')
        finally:
            harness.sql('DROP TRIGGER IF EXISTS collector_review_reject')
        host = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id) VALUES ('collector-review-device','127.0.0.1',{first}); SELECT LAST_INSERT_ID()").strip())
        hosts.append(host)
        harness.sql(f"INSERT INTO automation_processes (pid,poller_id,network_id,task,status) VALUES (2147483000,{first},0,'review','running')")
        process_delete = action('delete', [first])
        process_fields = form(process_delete)
        check(request(process_delete, process_fields)[0] == 502,
              'collector deletion fails closed while a MEMORY process row still names its owner')
        check(harness.sql(f'SELECT COUNT(*) FROM poller WHERE id={first}').strip() == '1'
              and harness.sql(f'SELECT poller_id FROM automation_processes WHERE pid=2147483000').strip() == str(first)
              and harness.sql(f'SELECT poller_id FROM host WHERE id={host}').strip() == str(first),
              'failed deletion preserves collector, process owner, and InnoDB assignments')
        harness.sql('DELETE FROM automation_processes WHERE pid=2147483000')
        timing = int(harness.sql(f"INSERT INTO poller_time (pid,poller_id,start_time,end_time) VALUES (1,{first},NOW(),NOW()); SELECT LAST_INSERT_ID()").strip())
        harness.sql(f"DELIMITER //\nCREATE TRIGGER collector_review_reject_time BEFORE UPDATE ON poller_time FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'collector time handoff failure'; END//\nDELIMITER ;")
        try:
            check(request(process_delete, form(process_delete))[0] == 502,
                  'collector deletion reports a later InnoDB ownership-handoff failure')
            check(harness.sql(f'SELECT COUNT(*) FROM poller WHERE id={first}').strip() == '1'
                  and harness.sql(f'SELECT poller_id FROM host WHERE id={host}').strip() == str(first)
                  and harness.sql(f'SELECT poller_id FROM poller_time WHERE id={timing}').strip() == str(first),
                  'later handoff failure rolls back collector and prior InnoDB ownership changes')
        finally:
            harness.sql('DROP TRIGGER IF EXISTS collector_review_reject_time')
            harness.sql(f'DELETE FROM poller_time WHERE id={timing}')
        sync = action('full-sync', [second])
        sync_before = harness.sql(f'SELECT last_sync FROM poller WHERE id={second}')
        check(request(sync, form(sync))[0] == 502, 'unavailable remote collector cannot report a successful full sync')
        check(harness.sql(f'SELECT last_sync FROM poller WHERE id={second}') == sync_before,
              'failed full sync preserves the last successful sync timestamp')

        delete = action('delete', [first])
        check(request(delete, form(delete))[0] == 200, 'collector deletion succeeds after confirmation')
        check(harness.sql(f'SELECT COUNT(*) FROM poller WHERE id={first}').strip() == '0', 'confirmed collector is removed')
        check(harness.sql(f'SELECT poller_id FROM host WHERE id={host}').strip() == '1',
              'collector deletion hands retained device ownership to the primary collector')
    finally:
        harness.sql('DROP TRIGGER IF EXISTS collector_review_reject')
        harness.sql('DROP TRIGGER IF EXISTS collector_review_reject_time')
        for host in hosts:
            harness.sql(f'DELETE FROM host WHERE id={host}')
        for ident in created:
            harness.sql(f'DELETE FROM poller WHERE id={ident}')
