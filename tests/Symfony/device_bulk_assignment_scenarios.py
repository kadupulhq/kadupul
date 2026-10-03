"""Bulk assignment HTTP checks with primary rollback and real remote replication."""
from device_state_scenarios import StateForm
from device_collector_scenarios import install_collector_profile_guards
import json


def verify_bulk_assignments(harness, session, check, poller):
    ids = []
    site = template = None
    trigger = False
    destination = None
    try:
        check(harness.php('-r', 'require "include/global.php"; function setup_assignment_hooks() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); api_plugin_register_hook("compatibility_test","device_template_change","compatibility_template_sync","setup.php",true); } setup_assignment_hooks();')['exit'] == 0, 'bulk assignment plugin hooks registered')

        def events(callback):
            return [json.loads(line)['args'] for line in harness.command('cat', '/artifacts/plugin.jsonl')['stdout'].splitlines() if json.loads(line).get('callback') == callback]

        site = int(harness.sql("INSERT INTO sites (name) VALUES ('Bulk assignment site'); SELECT LAST_INSERT_ID()").strip())
        template = int(harness.sql("INSERT INTO host_template (hash,name) VALUES ('bulk-assignment-template','Bulk assignment template'); SELECT LAST_INSERT_ID()").strip())
        graph = int(harness.sql('SELECT id FROM graph_templates ORDER BY id LIMIT 1').strip())
        harness.sql(f'INSERT INTO host_template_graph (host_template_id,graph_template_id) VALUES ({template},{graph})')
        for index in range(2):
            owner = poller if index == 0 else 1
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,location,snmp_version,availability_method) VALUES ('bulk-assignment-{index}','127.0.0.1',{owner},0,'',0,0); SELECT LAST_INSERT_ID()").strip()))
        harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={ids[0]}')
        harness.sql(f"INSERT INTO poller_item (host_id,poller_id,action,hostname,rrd_name) VALUES ({ids[0]},{poller},0,'127.0.0.1','assignment-first'),({ids[1]},1,0,'127.0.0.1','assignment-second'); INSERT INTO create_remote.poller_item SELECT * FROM poller_item WHERE host_id={ids[0]}")
        selected = ','.join(map(str, ids))
        listing_form = StateForm(harness, session, ids)
        status, listing = listing_form.request(path='/app.php/inventory/devices')
        check(status == 200 and all(f'formaction="/app.php/inventory/devices/assign/{kind}"' in listing for kind in ['site', 'template', 'collector']), 'device list exposes all bulk assignment routes')

        def worker(fields, kind, target, extra=None):
            actor = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
            command = {'actor': actor, 'selection': json.loads(fields['device_bulk_assignment[selection]']), 'operation': 'assign', 'kind': kind, 'target': target}
            command.update(extra or {})
            return harness.compose('exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/legacy-device-state.php', data=json.dumps(command), check=False)

        def form_for(kind):
            form = StateForm(harness, session, ids)
            form.path = form.path.replace('/disable?', '/assign/' + kind + '?')
            return form

        harness.sql(f'INSERT INTO graph_local (host_id,graph_template_id) VALUES ({ids[0]},{graph}); INSERT INTO data_local (host_id) VALUES ({ids[0]})')
        retained_graphs = harness.sql(f'SELECT id FROM graph_local WHERE host_id={ids[0]}')
        retained_data = harness.sql(f'SELECT id FROM data_local WHERE host_id={ids[0]}')
        # An earlier failed enable/disable may leave this legitimate drift.
        harness.sql(f"UPDATE create_remote.host SET disabled='on' WHERE id={ids[0]}")
        harness.sql(f"UPDATE host SET site_id=16777214 WHERE id={ids[0]}; UPDATE poller SET disabled='on' WHERE id={poller}")
        try:
            probe = harness.php('-r', 'require "include/global.php"; $db=$database_sessions["$database_hostname:$database_port:$database_default"]; $db->beginTransaction(); try { $result=(new \\Kadupul\\Inventory\\Infrastructure\\Legacy\\DeviceMutationSelection())->lock($db,(int)db_fetch_cell("SELECT id FROM user_auth WHERE username=\\\"admin\\\""),[' + str(ids[0]) + '],static function($status){}); echo count($result["rows"]); } finally { $db->rollBack(); }')
            check(probe['exit'] == 0 and probe['stdout'] == '1', 'default mutation selection retains existing missing-site and disabled-poller behavior')
        finally:
            harness.sql(f"UPDATE host SET site_id=0 WHERE id={ids[0]}; UPDATE poller SET disabled='' WHERE id={poller}")
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
            prior_actions = events('statistics_action')
            prior_site_marker = harness.sql("SELECT value FROM settings WHERE name='time_last_change_site_device'")
            check(form.apply(fields) == 502, f'bulk {kind} reports write failure')
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {column}=0').strip() == '2', f'bulk {kind} failure rolls back whole primary selection')
            check(events('statistics_action') == prior_actions, f'rejected bulk {kind} does not invoke action 4')
            harness.sql('DROP TRIGGER reject_bulk_assignment')
            trigger = False
            if kind == 'site':
                harness.sql("REPLACE INTO settings (name,value) VALUES ('time_last_change_site_device','1')")
            status, completion = form.request(fields=fields)
            check(status == 200, f'bulk {kind} assigns through Symfony')
            check('Selected device assignments updated.' in completion, f'bulk {kind} displays its assignment completion notice')
            check(events('statistics_action')[len(prior_actions):] == [[['4', ids]]], f'bulk {kind} invokes action 4 once for the complete selection')
            if kind != 'site':
                check(harness.sql("SELECT value FROM settings WHERE name='time_last_change_site_device'") == prior_site_marker, 'bulk template preserves site membership cache marker')
            if kind == 'site':
                check(int(harness.sql("SELECT value FROM settings WHERE name='time_last_change_site_device'").strip()) > 1, 'bulk site invalidates site membership cache')
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND {column}={target}').strip() == '2', f'bulk {kind} persists entire selection')
            check(harness.sql(f'SELECT {column} FROM create_remote.host WHERE id={ids[0]}').strip() == str(target), f'bulk {kind} verifies remote assignment')
            check(harness.sql(f'SELECT disabled FROM create_remote.host WHERE id={ids[0]}').strip() == 'on', f'bulk {kind} preserves preflight remote disabled state')
        site_marker = harness.sql("SELECT value FROM settings WHERE name='time_last_change_site_device'")
        # Existing template ownership does not imply complete associations.
        for prefix in ['', 'create_remote.']:
            harness.sql(f'DELETE FROM {prefix}host_graph WHERE host_id={ids[0]} AND graph_template_id={graph}')
        form = form_for('template')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': str(template)}) == 200, 'bulk existing template repairs missing association')
        check(harness.sql("SELECT value FROM settings WHERE name='time_last_change_site_device'") == site_marker, 'bulk repeated template preserves site membership cache marker')
        check(all(harness.sql(f'SELECT COUNT(*) FROM {prefix}host_graph WHERE host_id={ids[0]} AND graph_template_id={graph}').strip() == '1' for prefix in ['', 'create_remote.']), 'bulk existing template restores primary and collector association')
        check(harness.sql(f'SELECT id FROM graph_local WHERE host_id={ids[0]}') == retained_graphs and harness.sql(f'SELECT id FROM data_local WHERE host_id={ids[0]}') == retained_data, 'bulk template assignment retains existing graphs and data')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id IN ({selected}) AND graph_template_id={graph}').strip() == '2', 'bulk templates apply graph associations')
        for kind in ['site', 'template']:
            form = form_for(kind)
            prior_templates = events('template_sync')
            check(form.apply(form.fields() | {'device_bulk_assignment[target]': '0'}) == 200, f'bulk {kind} supports explicit unassignment')
            if kind == 'template':
                check(events('template_sync')[len(prior_templates):] == [[{'device_id': device, 'device_template_id': 0}] for device in ids], 'bulk template unassignment invokes the zero-template hook for each device')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id IN ({selected}) AND graph_template_id={graph}').strip() == '2', 'bulk template unassignment retains existing associations')
        form = form_for('collector')
        fields = form.fields() | {'device_bulk_assignment[target]': str(poller)}
        harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
        try:
            check(form.apply(fields) == 502, 'bulk collector preflights destination availability')
            check(harness.sql(f'SELECT poller_id FROM host WHERE id={ids[1]}').strip() == '1', 'offline bulk collector leaves primary ownership intact')
        finally:
            harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        # Reach the worker guard directly after the destination changed since GET.
        harness.sql(f"UPDATE poller SET disabled='on' WHERE id={poller}")
        result = worker(fields, 'collector', poller)
        check(result['exit'] == 1 and '"status":"failed"' in result['stdout'], 'bulk collector worker rejects disabled destination after GET')
        check(harness.sql(f'SELECT poller_id FROM host WHERE id={ids[1]}').strip() == '1' and harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id={ids[1]}').strip() == '0', 'disabled bulk collector destination writes no ownership or copy')
        harness.sql(f"UPDATE poller SET disabled='' WHERE id={poller}")
        check(form.apply(fields) == 200, 'bulk collector moves full selection to remote')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected}) AND poller_id={poller}').strip() == '2', 'bulk collector confirms destination copies')
        # A cleanup attempt using stale destination ownership must not purge.
        cleanup_actor = int(harness.sql("SELECT id FROM user_auth WHERE username='admin'").strip())
        cleanup_probe = harness.php('-r', 'require "include/global.php"; $db=$database_sessions["$database_hostname:$database_port:$database_default"]; try { (new \\Kadupul\\Inventory\\Infrastructure\\Legacy\\DeviceCollectorTransfer())->finish($db,' + str(cleanup_actor) + ',[],[' + str(ids[0]) + '=>' + str(poller) + '],1); echo "unexpected"; } catch (RuntimeException $e) { echo $e->getMessage(); }')
        check(cleanup_probe['exit'] == 0 and cleanup_probe['stdout'] == 'Collector ownership changed before cleanup' and harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '2', 'bulk collector cleanup rejects changed ownership before purging')
        # A later primary failure must leave the old polling copies available.
        rollback_owners = harness.sql(f'SELECT id,poller_id FROM host WHERE id IN ({selected}) ORDER BY id')
        rollback_items = harness.sql(f'SELECT host_id,poller_id FROM poller_item WHERE host_id IN ({selected}) ORDER BY host_id')
        rollback_remote = harness.sql(f'SELECT id,poller_id FROM create_remote.host WHERE id IN ({selected}) ORDER BY id')
        rollback_statistics = harness.sql('SELECT id,snmp,script,server FROM poller ORDER BY id')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_assignment BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.poller_id=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk return rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 502, 'bulk collector return failure reports uncertain outcome')
        check(harness.sql(f'SELECT id,poller_id FROM host WHERE id IN ({selected}) ORDER BY id') == rollback_owners and harness.sql(f'SELECT host_id,poller_id FROM poller_item WHERE host_id IN ({selected}) ORDER BY host_id') == rollback_items and harness.sql('SELECT id,snmp,script,server FROM poller ORDER BY id') == rollback_statistics, 'bulk collector return failure rolls back primary ownership and statistics')
        check(harness.sql(f'SELECT id,poller_id FROM create_remote.host WHERE id IN ({selected}) ORDER BY id') == rollback_remote, 'bulk collector rollback retains every previous polling copy')
        harness.sql('DROP TRIGGER reject_bulk_assignment')
        trigger = False
        # Exercise a distinct remote destination with the installed schema.
        harness.sql('CREATE DATABASE bulk_remote2 CHARACTER SET utf8mb4')
        tables = harness.sql('SHOW TABLES').splitlines()
        # These identifiers come from the owned database catalog.
        if not all(table.replace('_', '').isalnum() for table in tables):
            raise AssertionError('unexpected installed table identifier')
        harness.sql(';'.join(f'CREATE TABLE bulk_remote2.`{table}` LIKE cacti.`{table}`' for table in tables))
        install_collector_profile_guards(harness, 'bulk_remote2')
        destination = int(harness.sql("INSERT INTO poller (name,hostname,dbhost,dbdefault,dbuser,dbpass,last_status) VALUES ('Bulk second collector','db','db','bulk_remote2','root','behavior-root',NOW()); SELECT LAST_INSERT_ID()").strip())
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': str(destination)}) == 200, 'bulk collector transfers a full remote selection to another remote')
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND poller_id={destination}').strip() == '2' and harness.sql(f'SELECT COUNT(*) FROM bulk_remote2.host WHERE id IN ({selected}) AND poller_id={destination}').strip() == '2', 'bulk remote transfer confirms primary and destination ownership')
        check(harness.sql(f'SELECT COUNT(*) FROM poller_item WHERE host_id IN ({selected}) AND poller_id={destination}').strip() == '2' and harness.sql(f'SELECT COUNT(*) FROM bulk_remote2.poller_item WHERE host_id IN ({selected}) AND poller_id={destination}').strip() == '2', 'bulk remote transfer preserves nonempty polling ownership')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '0', 'bulk remote transfer removes old copies after commit')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': str(poller)}) == 200, 'bulk collector can return to its previous remote')
        check(harness.sql(f'SELECT COUNT(*) FROM bulk_remote2.host WHERE id IN ({selected})').strip() == '0', 'bulk remote return cleans the second collector')
        # After primary commit, failed cleanup retains the confirmed target.
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER create_remote.reject_bulk_cleanup BEFORE DELETE ON create_remote.host FOR EACH ROW BEGIN IF OLD.id={ids[0]} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk cleanup rejection'; END IF; END$$\nDELIMITER ;")
        try:
            check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 502, 'bulk collector cleanup failure cannot report success')
            check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND poller_id=1').strip() == '2' and harness.sql(f'SELECT COUNT(*) FROM poller_item WHERE host_id IN ({selected}) AND poller_id=1').strip() == '2', 'bulk collector cleanup failure retains committed destination ownership')
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '2', 'bulk collector cleanup failure leaves recoverable old copies')
            receipt_names = ','.join(f"'poller_replicate_device_cleanup_{device}_{poller}'" for device in ids)
            check(harness.sql(f'SELECT COUNT(*) FROM settings WHERE name IN ({receipt_names}) AND value=1').strip() == '2', 'bulk collector cleanup failure persists complete retry inventory')
            check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 502, 'bulk collector same-target retry reports repeated cleanup failure')
            check(harness.sql(f'SELECT COUNT(*) FROM settings WHERE name IN ({receipt_names}) AND value=1').strip() == '2', 'bulk collector failed retry retains complete cleanup inventory')
        finally:
            harness.sql('DROP TRIGGER create_remote.reject_bulk_cleanup')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_ack BEFORE DELETE ON settings FOR EACH ROW BEGIN IF OLD.name='poller_replicate_device_cleanup_{ids[1]}_{poller}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk receipt acknowledgement rejection'; END IF; END$$\nDELIMITER ;")
        try:
            check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 502, 'bulk collector later acknowledgement failure cannot report success')
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '0' and harness.sql(f'SELECT COUNT(*) FROM settings WHERE name IN ({receipt_names}) AND value=1').strip() == '2', 'bulk collector failed acknowledgement rolls back all receipts after remote absence')
        finally:
            harness.sql('DROP TRIGGER reject_bulk_ack')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 200, 'bulk collector same-target retry completes pending cleanup')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '0' and harness.sql(f'SELECT COUNT(*) FROM create_remote.poller_item WHERE host_id IN ({selected})').strip() == '0', 'bulk collector successful retry removes old polling copies')
        check(harness.sql(f'SELECT COUNT(*) FROM settings WHERE name IN ({receipt_names})').strip() == '0', 'bulk collector successful cleanup acknowledges complete retry inventory')
        check(harness.sql(f'SELECT COUNT(*) FROM poller_command WHERE poller_id={poller} AND action=3 AND command IN ({selected})').strip() == '0', 'bulk collector verified cleanup publishes no redundant purge commands')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': str(poller)}) == 200, 'bulk collector recovers old residue by returning to remote')
        check(form.apply(form.fields() | {'device_bulk_assignment[target]': '1'}) == 200, 'bulk collector returns full selection to primary')
        check(harness.sql(f'SELECT COUNT(*) FROM host WHERE id IN ({selected}) AND poller_id=1').strip() == '2', 'bulk collector confirms primary ownership')
        check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host WHERE id IN ({selected})').strip() == '0', 'bulk collector purges old remote copies')
        # The second primary write fails after the first remote replication.
        before_stats = harness.sql('SELECT id,snmp,script,server FROM poller ORDER BY id')
        before_owners = harness.sql(f'SELECT id,poller_id FROM host WHERE id IN ({selected}) ORDER BY id')
        before_items = harness.sql(f'SELECT host_id,poller_id FROM poller_item WHERE host_id IN ({selected}) ORDER BY host_id')
        form = form_for('collector')
        fields = form.fields() | {'device_bulk_assignment[target]': str(poller)}
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_bulk_assignment BEFORE UPDATE ON host FOR EACH ROW BEGIN IF NEW.id={ids[1]} AND NEW.poller_id={poller} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='bulk collector rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        prior_actions = events('statistics_action')
        check(form.apply(fields) == 502, 'bulk collector mid-batch failure reports uncertain outcome')
        check(harness.sql(f'SELECT id,poller_id FROM host WHERE id IN ({selected}) ORDER BY id') == before_owners and harness.sql(f'SELECT host_id,poller_id FROM poller_item WHERE host_id IN ({selected}) ORDER BY host_id') == before_items, 'bulk collector mid-batch failure rolls back primary host and cache ownership')
        check(harness.sql('SELECT id,snmp,script,server FROM poller ORDER BY id') == before_stats, 'bulk collector mid-batch failure rolls back poller statistics')
        check(events('statistics_action') == prior_actions, 'rejected bulk collector does not invoke action 4')
        check(harness.sql(f'SELECT poller_id FROM create_remote.host WHERE id={ids[0]}').strip() == str(poller), 'bulk collector failure retains documented first-device remote residue')
        harness.sql('DROP TRIGGER reject_bulk_assignment')
        trigger = False
        # Site target removal must also be rejected inside the actual worker.
        form = form_for('site')
        fields = form.fields()
        before_sites = harness.sql(f'SELECT id,site_id FROM host WHERE id IN ({selected}) ORDER BY id')
        harness.sql(f'DELETE FROM sites WHERE id={site}')
        result = worker(fields, 'site', site)
        check(result['exit'] == 1 and '"status":"failed"' in result['stdout'] and harness.sql(f'SELECT id,site_id FROM host WHERE id IN ({selected}) ORDER BY id') == before_sites, 'bulk site worker rejects deleted destination after GET without writes')
        for kind, target in [('unknown', 0), ([], 1), ('collector', 0), ('site', []), ('site', 1.5)]:
            result = worker(fields, kind, target)
            check(result['exit'] == 1 and '"status":"failed"' in result['stdout'] and harness.sql(f'SELECT id,site_id FROM host WHERE id IN ({selected}) ORDER BY id') == before_sites, 'malformed bulk assignment command cannot write')
        result = worker(fields, 'site', 0, {'unexpected': True})
        check(result['exit'] == 1 and '"status":"failed"' in result['stdout'] and harness.sql(f'SELECT id,site_id FROM host WHERE id IN ({selected}) ORDER BY id') == before_sites, 'bulk assignment worker rejects extra command keys before writes')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND ((hook='device_action_bottom' AND `function`='compatibility_statistics_action') OR (hook='device_template_change' AND `function`='compatibility_template_sync'))")
        if trigger:
            harness.sql('DROP TRIGGER reject_bulk_assignment')
        if ids:
            selected = ','.join(map(str, ids))
            for prefix in ['', 'create_remote.']:
                for table, column in [('host','id'), ('graph_local','host_id'), ('data_local','host_id'), ('host_graph','host_id'), ('host_snmp_query','host_id'), ('poller_item','host_id')]:
                    harness.sql(f'DELETE FROM {prefix}{table} WHERE {column} IN ({selected})')
        if site:
            harness.sql(f'DELETE FROM sites WHERE id={site}')
        if destination:
            harness.sql(f'DELETE FROM poller WHERE id={destination}; DROP DATABASE bulk_remote2')
        if template:
            harness.sql(f'DELETE FROM host_template_graph WHERE host_template_id={template}; DELETE FROM host_template WHERE id={template}')
