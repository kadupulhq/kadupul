"""Device graph-template association mutation and retention through Symfony."""
from device_collector_scenarios import CollectorForm
from device_edit_scenarios import Inputs


class AssociationForm(CollectorForm):
    def __init__(self, harness, session, device, kind='graph'):
        super().__init__(harness, session, device)
        self.path = f'/app.php/inventory/devices/{device}/associations/{kind}'

    def fields(self):
        status, body = self.request()
        if status != 200:
            raise AssertionError(f'Association form unavailable: {status}')
        parser = Inputs()
        parser.feed(body)
        return parser.fields


def verify_graph_associations(harness, session, check, poller=1):
    device = None
    trigger = False
    prefix = 'create_remote.' if poller > 1 else ''
    try:
        device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,availability_method) VALUES ('graph-association-fixture','127.0.0.1',{poller},0,0,0); SELECT LAST_INSERT_ID()").strip())
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={device}')
        target = int(harness.sql('SELECT DISTINCT gt.id FROM graph_templates gt LEFT JOIN snmp_query_graph sqg ON sqg.graph_template_id=gt.id INNER JOIN graph_templates_item gti ON gti.graph_template_id=gt.id INNER JOIN data_template_rrd dtr ON gti.task_item_id=dtr.id INNER JOIN data_template_data dtd ON dtd.data_template_id=dtr.data_template_id WHERE sqg.name IS NULL AND gti.local_graph_id=0 AND dtr.local_data_id=0 ORDER BY gt.id LIMIT 1').strip())
        form = AssociationForm(harness, session, device)
        fields = form.fields() | {'device_association[operation]': 'add', 'device_association[target]': str(target)}
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph associations GET is read-only')
        check(form.request(fields=fields, origin=False)[0] == 422, 'graph associations require same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_association[_token]')
        check(form.request(fields=missing)[0] == 422, 'graph associations require CSRF token')
        check(form.request(fields=fields | {'device_association[target]': '16777214'})[0] == 422, 'graph associations reject unavailable templates')
        check(form.request(fields=fields | {'device_association[poller_id]': '2'})[0] == 422, 'graph associations reject extra fields')
        harness.sql(f'INSERT INTO host_graph (host_id,graph_template_id) VALUES ({device},{target})')
        check(form.request(fields=fields)[0] == 409, 'concurrent graph associations invalidate confirmation')
        harness.sql(f'DELETE FROM host_graph WHERE host_id={device}')
        if poller > 1:
            harness.sql(f"UPDATE poller SET last_status='2000-01-01 00:00:00' WHERE id={poller}")
            try:
                check(form.request(fields=fields)[0] == 502, 'graph association refuses offline collector before writes')
            finally:
                harness.sql(f'UPDATE poller SET last_status=NOW() WHERE id={poller}')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_graph_association BEFORE INSERT ON {prefix}host_graph FOR EACH ROW BEGIN IF NEW.host_id={device} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='graph association rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.request(fields=fields)[0] == 502, 'graph association SQL rejection cannot report success')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph association failure rolls back primary writes')
        harness.sql(f'DROP TRIGGER {prefix}reject_graph_association')
        trigger = False
        check(form.request(fields=fields)[0] == 200, 'graph association adds through Symfony')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device} AND graph_template_id={target}').strip() == '1', 'graph association persists selected template')
        if poller > 1:
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device} AND graph_template_id={target}').strip() == '1', 'graph association verifies remote template')
        harness.sql(f'INSERT INTO graph_local (host_id,graph_template_id) VALUES ({device},{target})')
        graphs = harness.sql(f'SELECT id FROM graph_local WHERE host_id={device} ORDER BY id')
        fields = form.fields() | {'device_association[operation]': 'remove', 'device_association[target]': str(target)}
        check(form.request(fields=fields)[0] == 200, 'graph association removes through Symfony')
        check(harness.sql(f'SELECT COUNT(*) FROM host_graph WHERE host_id={device}').strip() == '0', 'graph association removal persists')
        check(harness.sql(f'SELECT id FROM graph_local WHERE host_id={device} ORDER BY id') == graphs, 'graph association removal retains existing graphs')
        if poller > 1:
            check(harness.sql(f'SELECT COUNT(*) FROM create_remote.host_graph WHERE host_id={device}').strip() == '0', 'graph association removal reaches collector')
    finally:
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_graph_association')
        if device:
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                harness.sql(f'DELETE FROM {database}host_graph WHERE host_id={device}; DELETE FROM {database}graph_local WHERE host_id={device}; DELETE FROM {database}host WHERE id={device}')


def verify_query_associations(harness, session, check, poller=1):
    device = None
    trigger = False
    prefix = 'create_remote.' if poller > 1 else ''
    try:
        # Disabled devices validate configuration without claiming live discovery.
        device = int(harness.sql(f"INSERT INTO host (description,hostname,poller_id,site_id,snmp_version,availability_method,disabled) VALUES ('query-association-fixture','127.0.0.1',{poller},0,0,0,'on'); SELECT LAST_INSERT_ID()").strip())
        if poller > 1:
            harness.sql(f'INSERT INTO create_remote.host SELECT * FROM host WHERE id={device}')
        target = int(harness.sql('SELECT id FROM snmp_query ORDER BY id LIMIT 1').strip())
        form = AssociationForm(harness, session, device, 'query')
        fields = form.fields() | {'device_association[operation]': 'add', 'device_association[target]': str(target), 'device_association[reindex]': '2'}
        check(form.request(fields=fields, origin=False)[0] == 422, 'query association requires same-origin CSRF')
        missing = dict(fields)
        missing.pop('device_association[_token]')
        check(form.request(fields=missing)[0] == 422, 'query association requires CSRF token')
        for method in ['', '1', '4', '-1', 'garbage']:
            check(form.request(fields=fields | {'device_association[reindex]': method})[0] == 422, 'query association rejects invalid or SNMP-only method: ' + method)
        check(form.request(fields=fields | {'device_association[extra]': '1'})[0] == 422, 'query association rejects extra fields')
        harness.sql(f"DELIMITER $$\nCREATE TRIGGER {prefix}reject_query_association BEFORE INSERT ON {prefix}host_snmp_query FOR EACH ROW BEGIN IF NEW.host_id={device} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='query association rejection'; END IF; END$$\nDELIMITER ;")
        trigger = True
        check(form.request(fields=fields)[0] == 502, 'query association SQL rejection cannot report success')
        check(harness.sql(f'SELECT COUNT(*) FROM host_snmp_query WHERE host_id={device}').strip() == '0', 'query association failure rolls back primary writes')
        harness.sql(f'DROP TRIGGER {prefix}reject_query_association')
        trigger = False
        check(form.request(fields=fields)[0] == 200, 'query association adds through Symfony')
        check(harness.sql(f'SELECT reindex_method FROM host_snmp_query WHERE host_id={device} AND snmp_query_id={target}').strip() == '2', 'query association stores reindex method')
        fields = form.fields() | {'device_association[operation]': 'change', 'device_association[target]': str(target), 'device_association[reindex]': '3'}
        harness.sql(f'UPDATE host_snmp_query SET reindex_method=0 WHERE host_id={device}')
        check(form.request(fields=fields)[0] == 409, 'query method concurrency invalidates confirmation')
        harness.sql(f'UPDATE host_snmp_query SET reindex_method=2 WHERE host_id={device}')
        check(form.request(fields=fields)[0] == 200, 'query reindex method changes through Symfony')
        if poller > 1:
            check(harness.sql(f'SELECT reindex_method FROM create_remote.host_snmp_query WHERE host_id={device} AND snmp_query_id={target}').strip() == '3', 'query reindex method is verified on collector')
        for database in (['', 'create_remote.'] if poller > 1 else ['']):
            harness.sql(f"INSERT INTO {database}host_snmp_cache (host_id,snmp_query_id,field_name,field_value,snmp_index,oid) VALUES ({device},{target},'fixture','value','1','.1.3.6.1'); INSERT INTO {database}poller_reindex (host_id,data_query_id,action,op,assert_value,arg1) VALUES ({device},{target},0,'=','1','fixture')")
        graph = int(harness.sql(f'INSERT INTO graph_local (host_id,snmp_query_id) VALUES ({device},{target}); SELECT LAST_INSERT_ID()').strip())
        fields = form.fields() | {'device_association[operation]': 'remove', 'device_association[target]': str(target), 'device_association[reindex]': '0'}
        check(form.request(fields=fields)[0] == 200, 'query association removes through Symfony')
        for database in (['', 'create_remote.'] if poller > 1 else ['']):
            check(harness.sql(f'SELECT (SELECT COUNT(*) FROM {database}host_snmp_query WHERE host_id={device})+(SELECT COUNT(*) FROM {database}host_snmp_cache WHERE host_id={device})+(SELECT COUNT(*) FROM {database}poller_reindex WHERE host_id={device})').strip() == '0', 'query removal clears associations cache and reindex state')
        check(harness.sql(f'SELECT COUNT(*) FROM graph_local WHERE id={graph}').strip() == '1', 'query removal retains existing graphs')
    finally:
        if trigger:
            harness.sql(f'DROP TRIGGER {prefix}reject_query_association')
        if device:
            for database in (['', 'create_remote.'] if poller > 1 else ['']):
                for table in ['host_snmp_query','host_snmp_cache','poller_reindex','graph_local']:
                    harness.sql(f'DELETE FROM {database}{table} WHERE host_id={device}')
                harness.sql(f'DELETE FROM {database}host WHERE id={device}')
