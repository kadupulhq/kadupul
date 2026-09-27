"""Automation network read-route checks against the isolated Symfony harness."""
from urllib.parse import urlencode


def verify_network_list(harness, session, user_id, check):
    route = '/app.php/automation/networks'
    network_id = int(harness.sql("SELECT COALESCE(MAX(id), 0) + 1 FROM automation_networks").strip())
    original_realm = harness.sql(f'SELECT COUNT(*) FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23').strip()
    if original_realm == '0':
        harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},23)')
    try:
        harness.sql("INSERT INTO automation_networks (id,name,poller_id,sched_type,total_ips,enabled,up_hosts,snmp_hosts,threads,last_runtime,start_at,next_start,last_started) "
                    f"VALUES ({network_id},'symfony-network-fixture',1,1,3,'on',2,1,4,1.25,'2026-09-27 08:00','2026-09-27 08:00:00','2026-09-26 08:00:00')")
        process_id = int(harness.sql('SELECT COALESCE(MAX(pid), 0) + 1 FROM automation_processes').strip())
        harness.sql(f"INSERT INTO automation_processes (pid,network_id,status,up_hosts,snmp_hosts) VALUES ({process_id},{network_id},'done',7,3)")
        with session.opener.open(harness.base + route) as response:
            body = response.read().decode('utf-8', errors='replace')
            headers = response.headers
            status = response.status
        check(status == 200, 'Automation network list requires and accepts realm 23')
        check('symfony-network-fixture' in body and 'Idle' in body,
              'Automation network page renders saved network and idle status')
        check('no-store' in headers.get('Cache-Control', ''), 'Automation network list is not cached')
        check(harness.sql(f'SELECT COUNT(*) FROM automation_processes WHERE network_id={network_id}').strip() == '1',
              'Automation network GET leaves stale process rows unchanged')
        invalid = session.request(route + '?' + urlencode({'page': '0'}))
        check(invalid['status'] == 400, 'Automation network list rejects invalid page input')

        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23')
        denied = session.request(route)
        check(denied['status'] == 403, 'Device realm 3 does not grant Automation realm 23 access')
    finally:
        harness.sql(f'DELETE FROM automation_processes WHERE network_id={network_id}')
        harness.sql(f'DELETE FROM automation_networks WHERE id={network_id}')
        harness.sql(f'DELETE FROM user_auth_realm WHERE user_id={user_id} AND realm_id=23')
        if original_realm != '0':
            harness.sql(f'INSERT INTO user_auth_realm (user_id,realm_id) VALUES ({user_id},23)')
