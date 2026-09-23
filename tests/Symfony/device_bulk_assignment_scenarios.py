"""Bulk assignment HTTP checks with primary rollback and real remote replication."""
from device_state_scenarios import StateForm


def verify_bulk_assignments(harness, session, check, poller):
    ids = []
    site = template = None
    trigger = False
    try:
        site = int(harness.sql("INSERT INTO sites (name) VALUES ('Bulk assignment site'); SELECT LAST_INSERT_ID()").strip())
        template = int(harness.sql("INSERT INTO host_template (hash,name) VALUES ('bulk-assignment-template','Bulk assignment template'); SELECT LAST_INSERT_ID()").strip())
        graph = int(harness.sql('SELECT id FROM graph_templates ORDER BY id LIMIT 1').strip())
        harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graph})')
        for index in range(2):
            owner = poller if index == 0 else 1
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,location,snmp_version,availability_method) VALUES ('bulk-assignment-{index}','127.0.0.1',{owner},0,'',0,0); SELECT LAST_INSERT_ID()").strip()))
        harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={ids[0]}')
        selected = ','.join(map(str, ids))

        def form_for(kind):
            form = StateForm(harness, session, ids)
            form.path = form.path.replace('/disable?', '/assign/' + kind + '?')
            return form

        for kind, target, column in [('site', site, 'site_id'), ('template', template, 'host_template_id')]:
            form = form_for(kind)
            fields = form.fields() | {'device_bulk_assignment[target]': str(target)}
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {column}=0').strip() == '2', f'bulk {kind} GET does not mutate devices')
            check(form.request(fields=fields, origin=False)[0] == 422, f'bulk {kind} requires same-origin CSRF')
            missing = dict(fields)
            missing.pop('device_bulk_assignment[_token]')
            check(form.apply(missing) == 422, f'bulk {kind} requires CSRF token')
            check(form.apply(fields | {'device_bulk_assignment[target]': '16777214'}) == 422, f'bulk {kind} rejects unknown target')
            check(form.apply(fields | {'device_bulk_assignment[poller_id]': '2'}) == 422, f'bulk {kind} rejects unrelated fields')
            harness.sql(f"UPDATE host SET location='stale' WHERE id={ids[1]}")
            check(form.apply(fields) == 409, f'bulk {kind} rejects stale selection before writes')
            harness.sql(f"UPDATE host SET location='' WHERE id={ids[1]}")
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.apply(fields) == 502, f'bulk {kind} refuses unavailable remote before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
            harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_assignment BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.{column}={target} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk assignment rejection'; END IF; END$$\nDELIMITER ;")
            trigger = True
            check(form.apply(fields) == 502, f'bulk {kind} reports write failure')
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {column}=0').strip() == '2', f'bulk {kind} failure rolls back whole primary selection')
            harness.sql('DROP TRIGGER reject_bulk_assignment')
            trigger = False
            check(form.apply(fields) == 200, f'bulk {kind} assigns through Symfony')
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {column}={target}').strip() == '2', f'bulk {kind} persists entire selection')
            check(harness.sql(f'SELECT {column} FROM create_remote.host WHERE id={ids[0]}').strip() == str(target), f'bulk {kind} verifies remote assignment')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id IN ({selected}) AND graph_template_id={graph}').strip() == '2', 'bulk templates apply graph associations')
        for kind in ['site', 'template']:
            form = form_for(kind)
            check(form.apply(form.fields() | {'device_bulk_assignment[target]': '0'}) == 200, f'bulk {kind} supports explicit unassignment')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id IN ({selected}) AND graph_template_id={graph}').strip() == '2', 'bulk template unassignment retains existing associations')
        form = form_for('collector')
        fields = form.fields() | {'device_bulk_assignment[target]': str(poller)}
        harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
        try:
            check(form.apply(fields) == 502, 'bulk collector preflights destination availability')
            check(harness.sql(f'SELECT poller_id FROM host WHERE id={ids[1]}').strip() == '1', 'offline bulk collector leaves primary ownership intact')
        finally:
            harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        check(form.apply(fields) == 200, 'bulk collector moves full selection to remote')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected}) AND poller_id={poller}').strip() == '2', 'bulk collector confirms destination copies')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 200, 'bulk collector returns full selection to primary')
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND poller_id=1').strip() == '2', 'bulk collector confirms primary ownership')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '0', 'bulk collector purges old remote copies')
    finally:
        if trigger:
            harness.sql('DROP TRIGGER reject_bulk_assignment')
        if ids:
            selected = ','.join(map(str, ids))
            for prefix in ['', 'create_remote.']:
                for table, column in [('host','id'), ('host_graph','host_id'), ('host_snmp_query','host_id'), ('poller_item','host_id')]:
                    harness.sql(f'DELETE FROM {prefix}{table} WHERE {column} IN ({selected})')
        if site:
            harness.sql(f'DELETE FROM sites WHERE id={site}')
        if template:
            harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={template}; DELETE FROM host_template WHERE id={template}')
