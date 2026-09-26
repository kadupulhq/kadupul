"""Safe legacy device URLs, typed filters, suggestions and existing rule execution."""
import json
from urllib.parse import urlencode
from device_collector_scenarios import CollectorForm
from device_state_scenarios import StateForm


def verify_device_legacy(harness, session, user_id, check):
    device = None
    trigger = False
    try:
        device = int(harness.sql("INSERT INTO host (description,hostname,site_id,snmp_version,availability_method,location) VALUES ('legacy-cutover-fixture','127.0.0.1',0,0,0,'cutover-location'); SELECT LAST_INSERT_ID()").strip())
        http = CollectorForm(harness,session,device)
        before = harness.sql(f'SELECT id,hostname,description,disabled,site_id,poller_id,host_template_id FROM host WHERE id={device}')
        debug = harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'")
        check(http.request(path='/host.php')[0] == 200, 'legacy device list opens Symfony')
        status,body=http.request(path=f'/host.php?action=edit&id={device}')
        check(status==200 and 'device_edit[' in body, 'legacy edit opens Symfony form')
        status,body=http.request(path='/host.php?action=edit&create=true')
        check(status==200 and 'device_create[' in body, 'legacy create opens Symfony form')
        for action in ['enable_debug','disable_debug','repopulate','reindex','query_reload','query_verbose']:
            check(http.request(path=f'/host.php?action={action}&host_id={device}')[0]==200, 'legacy maintenance GET opens confirmation only')
        for action in ['gt_add','gt_remove','query_add','query_remove','query_change']:
            check(http.request(path=f'/host.php?action={action}&host_id={device}&id=1')[0]==200, 'legacy association GET opens confirmation only')
        check(harness.sql(f'SELECT id,hostname,description,disabled,site_id,poller_id,host_template_id FROM host WHERE id={device}')==before and harness.sql("SELECT value FROM settings WHERE name='selective_device_debug'")==debug, 'legacy device GET links do not mutate state')
        check(http.request(path='/host.php',fields={'action':'save','id':str(device),'description':'overwrite'})[0]==409, 'legacy device POST is never replayed')
        check(harness.sql(f"SELECT description FROM host WHERE id={device}").strip()=='legacy-cutover-fixture', 'expired legacy device form preserves stored values')
        check(http.request(path='/host.php?action=save')[0]==405, 'legacy unknown mutations are rejected')
        check(http.request(path='/host.php?action=edit&id[]=1')[0]==400, 'legacy device bridge rejects malformed IDs')
        status,body=http.request(path='/host.php?action=export&filter=legacy-cutover-fixture')
        check(status==200 and 'legacy-cutover-fixture' in body and 'snmp_community' not in body, 'legacy device export uses bounded public CSV')
        response=session.request('/host.php?action=ajax_locations&term=cutover')
        check(response.get('json')==[{'label':'cutover-location','value':'cutover-location'}], 'legacy location suggestions use authorized Inventory query')
        query=urlencode({'q':'legacy-cutover-fixture','template':'0','collector':'1','location_mode':'exact','location':'cutover-location'})
        response=session.request('/app.php/inventory/devices.json?'+query)
        check(response['status']==200 and any(row['id']==device for row in response['json']['devices']), 'Inventory preserves template collector and exact location filters')
        response=session.request('/app.php/inventory/devices.json?'+query.replace('template=0','template=16777214'))
        check(response['status']==200 and response['json']['devices']==[], 'Inventory filters constrain the result set')
        check(harness.php('-r', 'require "include/global.php"; function setup_cutover_hook() { api_plugin_register_hook("compatibility_test","device_action_bottom","compatibility_statistics_action","setup.php",true); } setup_cutover_hook();')['exit']==0,'automation compatibility hook registered')
        def actions():
            return [json.loads(line)['args'] for line in harness.command('cat','/artifacts/plugin.jsonl')['stdout'].splitlines() if json.loads(line).get('callback')=='statistics_action']
        form=StateForm(harness,session,[device]);form.path=form.path.replace('/disable?','/automation?')
        fields=form.fields()
        check(form.request(fields=fields,origin=False)[0]==422,'device automation requires same-origin CSRF')
        harness.sql(f"UPDATE host SET description='stale' WHERE id={device}")
        check(form.apply(fields)==409,'device automation rejects stale selection')
        harness.sql(f"UPDATE host SET description='legacy-cutover-fixture' WHERE id={device}")
        before_actions=actions()
        check(form.apply(fields)==200,'existing device automation rules run through Symfony')
        check(actions()[len(before_actions):]==[[['6',[device]]]],'device automation preserves action 6 once with full selection')
        harness.sql("DELIMITER $$\nCREATE TRIGGER reject_automation_marker BEFORE INSERT ON settings FOR EACH ROW BEGIN IF NEW.name='time_last_change_device' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='automation marker rejection'; END IF; END$$\nDELIMITER ;")
        trigger=True
        check(form.apply(fields)==502,'device automation SQL failure cannot report success')
        harness.sql('DROP TRIGGER reject_automation_marker');trigger=False
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=3')
        try:
            check(http.request(path='/host.php')[0]==403,'legacy device entry rechecks revoked management realm')
        finally:
            harness.sql(f'INSERT IGNORE INTO user_auth_realm (realm_id,user_id) VALUES (3,{user_id})')
    finally:
        if trigger: harness.sql('DROP TRIGGER reject_automation_marker')
        harness.sql("DELETE FROM plugin_hooks WHERE name='compatibility_test' AND hook='device_action_bottom' AND `function`='compatibility_statistics_action'")
        if device:
            harness.sql(f'DELETE FROM graph_tree_items WHERE host_id={device}; DELETE FROM host WHERE id={device}')
