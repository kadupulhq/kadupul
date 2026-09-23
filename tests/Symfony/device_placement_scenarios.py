"""Inventory selection writes through Graphing and Reporting ownership boundaries."""
from device_association_scenarios import AssociationForm


def verify_device_placement(harness, session, user_id, check):
    ids = []
    tree = report = branch = None
    trigger = False
    try:
        tree = int(harness.sql(f"INSERT INTO graph_tree (name,user_id) VALUES ('placement-tree',{user_id}); SELECT LAST_INSERT_ID()").strip())
        branch = int(harness.sql(f"INSERT INTO graph_tree_items (graph_tree_id,title,parent) VALUES ({tree},'placement-branch',0); SELECT LAST_INSERT_ID()").strip())
        report = int(harness.sql(f"INSERT INTO reports (name,user_id,from_name,from_email,email,bcc) VALUES ('placement-report',{user_id},'','','',''); SELECT LAST_INSERT_ID()").strip())
        for index in range(2):
            ids.append(int(harness.sql(f"INSERT INTO host (description,hostname,site_id) VALUES ('placement-device-{index}','127.0.0.1',0); SELECT LAST_INSERT_ID()").strip()))
        for kind, destination, parent in [('tree', tree, branch), ('report', report, 0)]:
            probe = harness.php('-r', f'$placementFixture = ["{kind}",{destination},{parent},{ids[0]},{user_id}]; require "tests/Symfony/placement_lock_probe.php";')
            check(probe['exit'] == 0 and 'PLACEMENT_LOCK_OK' in probe['stdout'], kind+' legacy placement shares destination locks and rejects duplicates')
        check(harness.php('-r', 'require "include/global.php"; function setup_placement_hook() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_placement_tamper","setup.php",true); } setup_placement_hook();')['exit'] == 0, 'placement callback fixture registered')
        selection = ' OR '.join(f'host_id={id}' for id in ids)
        for kind, target, table in [('tree',f'{tree}:{branch}','graph_tree_items'),('report',str(report),'reports_items')]:
            form = AssociationForm(harness,session,ids[0])
            form.path='/app.php/inventory/devices/place/'+kind+'?'+'&'.join('ids[]='+str(id) for id in ids)
            fields=form.fields() | {'device_placement[target]':target}
            if kind=='report':
                fields.update({'device_placement[timespan]':'7','device_placement[alignment]':'2'})
            check(form.request(fields=fields,origin=False)[0]==422, 'placement requires same-origin CSRF')
            missing=dict(fields); missing.pop('device_placement[_token]')
            check(form.request(fields=missing)[0]==422, 'placement requires CSRF token')
            check(form.request(fields=fields|{'device_placement[target]':'999999'})[0]==422, 'placement rejects unavailable destination')
            check(form.request(fields=fields|{'device_placement[extra]':'1'})[0]==422, 'placement rejects extra fields')
            harness.sql(f"UPDATE host SET hostname='stale.invalid' WHERE id={ids[1]}")
            check(form.request(fields=fields)[0]==409, 'placement rejects stale selection before writes')
            harness.sql(f"UPDATE host SET hostname='127.0.0.1' WHERE id={ids[1]}")
            if kind=='tree':
                harness.sql(f'UPDATE graph_tree SET locked=1,modified_by=999999 WHERE id={tree}')
                check(form.request(fields=fields)[0]==422, 'placement hides another users locked tree')
                harness.sql(f'UPDATE graph_tree SET locked=0 WHERE id={tree}')
                check(form.request(fields=fields|{'device_placement[target]':f'{tree}:{branch+999999}'})[0]==422, 'placement rejects foreign tree branch')
            harness.sql(f"DELIMITER $$\nCREATE TRIGGER reject_placement BEFORE INSERT ON {table} FOR EACH ROW BEGIN IF NEW.host_id={ids[1]} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='placement rejection'; END IF; END$$\nDELIMITER ;")
            trigger=True
            check(form.request(fields=fields)[0]==502, kind+' placement rejects partial SQL failure')
            check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE {selection}').strip()=='0', kind+' placement rolls back entire selection')
            harness.sql('DROP TRIGGER reject_placement');trigger=False
            harness.sql(f"UPDATE host SET description='placement-reject-hook' WHERE id={ids[1]}")
            rejected = form.fields() | {'device_placement[target]':target}
            if kind=='report': rejected.update({'device_placement[timespan]':'7','device_placement[alignment]':'2'})
            check(form.request(fields=rejected)[0]==502, kind+' placement verifies final state after callbacks')
            check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE {selection}').strip()=='0', kind+' placement rolls back callback changes')
            harness.sql(f"UPDATE host SET description='placement-device-1' WHERE id={ids[1]}")
            check(form.request(fields=fields)[0]==200, kind+' placement saves through Symfony')
            check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE {selection}').strip()=='2', kind+' placement persists every selected device')
            if kind=='tree':
                check(harness.sql(f'SELECT COUNT(*) FROM graph_tree_items WHERE graph_tree_id={tree} AND parent={branch} AND ({selection})').strip()=='2', 'tree placement preserves selected parent')
            else:
                check(harness.sql(f'SELECT COUNT(*) FROM reports_items WHERE report_id={report} AND timespan=7 AND align=2 AND item_type=5 AND ({selection})').strip()=='2', 'report placement preserves display settings')
            check(form.request(fields=fields)[0]==200, kind+' placement repeated submission succeeds')
            check(harness.sql(f'SELECT COUNT(*) FROM {table} WHERE {selection}').strip()=='2', kind+' placement does not duplicate existing devices')
        check(harness.sql('SELECT COUNT(*) FROM host WHERE id IN ('+','.join(map(str,ids))+')').strip()=='2', 'placement retains selected devices')
    finally:
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_placement_tamper'")
        if trigger: harness.sql('DROP TRIGGER reject_placement')
        if tree: harness.sql(f'DELETE FROM graph_tree_items WHERE graph_tree_id={tree}; DELETE FROM graph_tree WHERE id={tree}')
        if report: harness.sql(f'DELETE FROM reports_items WHERE report_id={report}; DELETE FROM reports WHERE id={report}')
        if ids: harness.sql('DELETE FROM host WHERE id IN ('+','.join(map(str,ids))+')')
