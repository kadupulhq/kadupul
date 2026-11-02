# SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
# SPDX-License-Identifier: GPL-3.0-or-later
"""Rehearse a real release upgrade and snapshot rollback in disposable containers."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import shutil
import select
import subprocess
import sys
import tempfile
import time
import uuid
from types import SimpleNamespace

import harness

ROOT = harness.ROOT
RECOVERY_CASES = ('partial_collector_sync', 'worker_crash', 'database_outage')
COLLECTOR_ID = 60000
COLLECTOR_SCHEMA = 'rehearsal_remote'


def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def checked(result, label):
    require(result['exit'] == 0, label + ': ' + json.dumps(result))
    return result


def rrd_manifest(h):
    code = '''$r=[];
if (is_link("rra")) { throw new RuntimeException("RRD snapshot cannot preserve an external symlink target"); }
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator("rra", FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if ($file->isLink()) { throw new RuntimeException("RRD snapshot cannot preserve an external symlink target"); }
    if (!$file->isFile() || strtolower($file->getExtension()) !== "rrd") { continue; }
    $path=$file->getPathname(); $hash=hash_file("sha256",$path);
    if ($hash === false) { throw new RuntimeException("Cannot hash RRD snapshot member"); }
    $r[substr($path,4)]=$hash;
}
ksort($r); echo json_encode($r);'''
    result = checked(h.php('-r', code), 'RRD manifest')
    values = json.loads(result['stdout'])
    require(isinstance(values, dict) and values, 'No RRD files were created')
    return values


def domain_state(h):
    state = {table: h.sql('SELECT * FROM ' + table + ' ORDER BY ' + key)
            for table, key in [('host', 'id'), ('data_local', 'id'), ('graph_local', 'id'),
                               ('aggregate_graphs', 'id'), ('plugin_config', 'id'), ('plugin_hooks', 'id')]}
    state['custom_template'] = h.sql("SELECT id,hash,name FROM host_template WHERE hash='dddddddddddddddddddddddddddddddd'")
    state['custom_template_graphs'] = h.sql("SELECT htg.* FROM host_template_graph htg INNER JOIN host_template ht ON ht.id=htg.host_template_id WHERE ht.hash='dddddddddddddddddddddddddddddddd' ORDER BY htg.graph_template_id")
    return state


def installer_receipt(h):
    receipt = json.loads(h.sql("SELECT value FROM settings WHERE name='installer_v13_job'"))
    require(isinstance(receipt, dict) and isinstance(receipt.get('attempt'), int), 'Missing real installer receipt')
    # Keep tokens, actor names and review fingerprints out of retained evidence.
    return {key: receipt.get(key) for key in ('state', 'attempt', 'operation', 'cleanup_done', 'pending_collectors', 'target')}


def require_recovery_evidence(evidence):
    for name in RECOVERY_CASES:
        case = evidence.get(name)
        require(isinstance(case, dict) and case.get('observed') is True and case.get('preserved') is True,
                'Missing native recovery evidence: ' + name)
    require(evidence['partial_collector_sync'].get('repaired_sync') is True, 'Collector repair did not perform real synchronization')
    for name in ('worker_crash', 'database_outage'):
        require(evidence[name].get('native_and_outer_stopped') is True, 'Native and outer processes were not confirmed stopped: ' + name)
    before = evidence['partial_collector_sync'].get('remote_before')
    after = evidence['partial_collector_sync'].get('remote_after')
    require(isinstance(before, list) and isinstance(after, list) and len(before) == len(after) == 2
            and all(a != b for a, b in zip(before, after)), 'Collector evidence does not establish transfer of previously stale values')


def prepare_offline_collector(h):
    require(h.sql('SELECT COUNT(*) FROM poller WHERE id=' + str(COLLECTOR_ID)).strip() == '0', 'Collector fixture ID already exists')
    require(h.sql("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='" + COLLECTOR_SCHEMA + "'").strip() == '0', 'Collector fixture schema already exists')
    constants = json.loads(checked(h.php('-r', 'require "include/global_constants.php"; echo json_encode([POLLER_STATUS_DOWN,POLLER_STATUS_IDLE]);'), 'Native collector status constants')['stdout'])
    require(len(constants) == 2 and all(isinstance(value, int) for value in constants), 'Invalid native collector status constants')
    h.sql("INSERT INTO poller(id,name,status,last_update,hostname,dbhost,dbdefault,dbuser,dbpass) VALUES ("
          + str(COLLECTOR_ID) + ",'rehearsal-owned-collector'," + str(constants[0])
          + ",NOW(),'rehearsal-owned-collector','db','" + COLLECTOR_SCHEMA + "','cactiuser','cactiuser')")
    return constants[1]


def await_condition(test, message, seconds=40):
    deadline = time.monotonic() + seconds
    while time.monotonic() < deadline:
        if test():
            return
        time.sleep(.15)
    raise RuntimeError(message)


def owned_installer_process(h, outer, identity=None):
    """Discover or kill only the native descendant, rechecking identity at mutation."""
    code = '''$outer=(int)$argv[1]; $kill=isset($argv[2]); $found=[];
foreach (glob("/proc/[0-9]*/status") as $path) {
 $pid=(int)basename(dirname($path)); $status=@file_get_contents($path); $cmd=@file_get_contents("/proc/$pid/cmdline");
 if (!is_string($status) || !is_string($cmd) || !preg_match('/^Uid:\\s+(\\d+)/m',$status,$uid) || (int)$uid[1]!==posix_geteuid()) {continue;}
 $args=explode("\\0",rtrim($cmd,"\\0"));
 if (!in_array("/var/www/html/bin/legacy-installer.php",$args,true) || @readlink("/proc/$pid/cwd")!==realpath("/var/www/html")) {continue;}
 $parent=$pid; $owned=false;
 for ($depth=0;$depth<32 && $parent>1;$depth++) {
  $s=@file_get_contents("/proc/$parent/status"); if (!is_string($s) || !preg_match('/^PPid:\\s+(\\d+)/m',$s,$m)) {break;}
  $parent=(int)$m[1]; if ($parent===$outer) {$owned=true;break;}
 }
 if (!$owned) {continue;}
 $stat=@file_get_contents("/proc/$pid/stat"); if (!is_string($stat)) {continue;}
 $fields=explode(' ',substr($stat,strrpos($stat,')')+2)); $start=$fields[19]??null;
 if (!is_string($start) || !ctype_digit($start)) {exit(1);}
 $found[]=['pid'=>$pid,'start'=>$start];
}
if (count($found)!==1) {exit(1);}
if ($kill && ((string)$found[0]['pid']!==$argv[2] || $found[0]['start']!==$argv[3] || !posix_kill($found[0]['pid'],9))) {exit(1);}
echo json_encode($found[0]);'''
    args = [outer] + ([str(identity['pid']), identity['start']] if identity else [])
    result = checked(h.command('php', '-d', 'auto_prepend_file=', '-r', code, *args), 'Verify the owned native installer descendant')
    return json.loads(result['stdout'])


def locked_finishing_attempt(h, fault):
    """Block the real replication UPDATE, then interrupt only our owned worker/backend."""
    require(fault in ('worker_crash', 'database_outage'), 'Unknown real fault scenario')
    lock = subprocess.Popen(h.dc + ['exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root',
                            '-N', '-B', '--unbuffered', 'cacti'], stdin=subprocess.PIPE,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    worker = None
    pidfile = '/tmp/installer-rehearsal-' + uuid.uuid4().hex
    database_stopped = False
    try:
        lock.stdin.write('START TRANSACTION; SELECT id FROM poller WHERE id=' + str(COLLECTOR_ID) + ' FOR UPDATE;\n')
        lock.stdin.flush()
        require(bool(select.select([lock.stdout], [], [], 20)[0]) and lock.stdout.readline().strip() == str(COLLECTOR_ID), 'Owned collector row lock was not acquired')
        launch = '''$pid=(string)getmypid(); $f=fopen($argv[1],"x"); if (!$f || fwrite($f,$pid)!==strlen($pid) || !fclose($f)) {exit(1);}
pcntl_exec(PHP_BINARY,["-d","auto_prepend_file=/harness/errors.php","bin/console","kadupul:install","--install","--force","--output=json"]); exit(1);'''
        worker = subprocess.Popen(h.dc + ['exec', '-T', '-u', 'www-data', 'web', 'php', '-d',
                                  'auto_prepend_file=', '-r', launch, pidfile],
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        await_condition(lambda: installer_receipt(h)['state'] == 'running', 'Native finishing worker never acknowledged its receipt')
        waiter_count = lambda: int(h.sql("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB='cacti' AND INFO LIKE 'UPDATE poller SET requires_sync%'"))
        await_condition(lambda: waiter_count() > 0,
                        'Native collector replication never reached the blocked UPDATE')
        before = installer_receipt(h)
        require(before['operation'] == 'finish', 'Recovery unexpectedly replayed installation')
        pid = checked(h.command('cat', pidfile), 'Read owned native worker PID')['stdout'].strip()
        require(pid.isdigit() and int(pid) > 1, 'Invalid native worker PID')
        native = owned_installer_process(h, pid)
        if fault == 'worker_crash':
            # Kill the native worker, not the outer Symfony process. The outer
            # process must observe the failed child and exit naturally.
            owned_installer_process(h, pid, native)
        else:
            h.compose('stop', 'db', timeout=60)
            database_stopped = True
        stdout, stderr = worker.communicate(timeout=40)
        require(worker.returncode != 0, 'Interrupted native worker reported success')
        gone = 'exit(is_dir("/proc/".$argv[1]) ? 1 : 0);'
        checked(h.command('php', '-d', 'auto_prepend_file=', '-r', gone, pid), 'Confirm outer installer process ended')
        checked(h.command('php', '-d', 'auto_prepend_file=', '-r', gone, str(native['pid'])), 'Confirm native installer descendant ended')
        if database_stopped:
            h.compose('start', 'db', timeout=60)
            await_condition(lambda: h.compose('exec', '-T', 'db', 'mariadb-admin', '-uroot', '-pbehavior-root', 'ping', check=False)['exit'] == 0,
                            'Owned database did not recover after the outage', seconds=60)
            database_stopped = False
        else:
            lock.stdin.write('ROLLBACK;\nquit\n')
            lock.stdin.flush()
        await_condition(lambda: waiter_count() == 0, 'Interrupted native database statement remains active after process shutdown')
        after = installer_receipt(h)
        require(after['attempt'] == before['attempt'] and after['state'] in ('waiting', 'running', 'failed'), 'Interrupted worker published a completed outcome')
        if after['state'] in ('waiting', 'running'):
            wrong = h.php('bin/console', 'kadupul:install', '--action=recover', '--attempt=' + str(after['attempt'] + 1), '--force')
            require(wrong['exit'] != 0 and installer_receipt(h) == after, 'Stale recovery changed another attempt')
            checked(h.php('bin/console', 'kadupul:install', '--action=recover', '--attempt=' + str(after['attempt']), '--force'), 'Explicitly recover the stopped attempt')
        recovered = installer_receipt(h)
        require(recovered['state'] == 'failed' and recovered['attempt'] == before['attempt'], 'Recovery started a worker or changed attempt ownership')
        return {'observed': True, 'before': before, 'after': after, 'recovered': recovered,
                'worker_exit': worker.returncode, 'native_and_outer_stopped': True,
                'worker_output_bytes': len(stdout) + len(stderr)}
    finally:
        primary_error = sys.exc_info()[1]
        cleanup_errors = []

        def cleanup(label, operation):
            try:
                operation()
            except Exception as error:
                # Preserve the original fault without echoing private process
                # arguments, SQL or output from cleanup exceptions.
                cleanup_errors.append(label + ':' + type(error).__name__)

        cleanup('remove_pidfile', lambda: checked(h.command('rm', '-f', pidfile), 'Remove owned PID file'))
        if primary_error is not None:
            # Stop every process in this invocation's web container before
            # releasing its lock, even when its docker-exec client has exited.
            cleanup('stop_web', lambda: h.compose('stop', 'web', timeout=60))
        if database_stopped:
            def restore_database():
                h.compose('start', 'db', timeout=60)
                await_condition(lambda: h.compose('exec', '-T', 'db', 'mariadb-admin', '-uroot', '-pbehavior-root', 'ping', check=False)['exit'] == 0,
                                'Owned database did not recover during fault cleanup', seconds=60)
            cleanup('restore_database', restore_database)
        if worker is not None:
            def close_worker():
                if worker.poll() is None:
                    worker.terminate()
                worker.communicate(timeout=10)
            cleanup('close_worker', close_worker)

        def close_lock():
            if lock.poll() is None:
                try:
                    lock.stdin.write('ROLLBACK;\nquit\n')
                    lock.stdin.flush()
                    lock.communicate(timeout=10)
                except (BrokenPipeError, OSError, ValueError, subprocess.TimeoutExpired):
                    lock.terminate()
                    lock.communicate(timeout=10)
            else:
                lock.communicate(timeout=10)
        cleanup('close_lock', close_lock)
        if cleanup_errors:
            if primary_error is not None:
                primary_error.rehearsal_cleanup_errors = cleanup_errors
            else:
                raise RuntimeError('Owned fault cleanup failed: ' + ', '.join(cleanup_errors))


def rehearse_installer_recovery(h, before_rrd, before_domain, idle_status, evidence):
    partial = installer_receipt(h)
    require(partial['state'] == 'partial' and partial['pending_collectors'] == 1 and partial['cleanup_done'] is True,
            'Offline collector did not retain a genuine partial installation receipt')
    repeat = h.php('bin/console', 'kadupul:install', '--install', '--force', '--output=json')
    again = installer_receipt(h)
    require(repeat['exit'] != 0 and again['state'] == 'partial' and again['pending_collectors'] == 1
            and again['operation'] == 'finish' and again['attempt'] == partial['attempt'] + 1,
            'Offline finishing retry did not retain pending collector work')
    evidence['partial_collector_sync'] = {'observed': True, 'initial': partial, 'offline_retry': again}
    # Clone the genuinely upgraded schema, including its installed reference
    # guards, into another database on this invocation's isolated DB service.
    dump = h.compose('exec', '-T', 'db', 'mariadb-dump', '-uroot', '-pbehavior-root', '--skip-comments', '--skip-dump-date', 'cacti')['stdout']
    h.sql('CREATE DATABASE ' + COLLECTOR_SCHEMA + ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
          " GRANT ALL ON " + COLLECTOR_SCHEMA + ".* TO 'cactiuser'@'%';")
    h.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root', COLLECTOR_SCHEMA, data=dump)
    expected_remote = [h.sql("SELECT name FROM host_template WHERE hash='dddddddddddddddddddddddddddddddd'").strip(),
                       h.sql("SELECT version FROM plugin_config WHERE directory='compatibility_test'").strip()]
    require(all(expected_remote) and expected_remote != ['stale collector template', '0.0.0'], 'Missing representative replication source values')
    h.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root', COLLECTOR_SCHEMA,
              data="UPDATE host_template SET name='stale collector template' WHERE hash='dddddddddddddddddddddddddddddddd'; UPDATE plugin_config SET version='0.0.0' WHERE directory='compatibility_test';")
    remote_before = h.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root', '-N', '-B', COLLECTOR_SCHEMA,
                              data="SELECT name FROM host_template WHERE hash='dddddddddddddddddddddddddddddddd'; SELECT version FROM plugin_config WHERE directory='compatibility_test';")['stdout'].splitlines()
    require(remote_before == ['stale collector template', '0.0.0'] and all(a != b for a, b in zip(remote_before, expected_remote)), 'Remote replication fixture does not differ from the primary')
    h.sql('UPDATE poller SET status=' + str(idle_status) + ',last_update=NOW() WHERE id=' + str(COLLECTOR_ID))
    # Any replay of the primary schema marker during a finishing retry is a
    # real database failure, not a second hand-written implementation oracle.
    h.sql("CREATE TRIGGER rehearsal_no_schema_replay BEFORE UPDATE ON version FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finishing retry replayed schema upgrade'")
    try:
        for fault in ('worker_crash', 'database_outage'):
            evidence[fault] = locked_finishing_attempt(h, fault)
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, fault + ' changed representative RRD or application data')
            require(h.sql('SELECT cacti FROM version').strip() == (ROOT / 'include/cacti_version').read_text().strip(), fault + ' changed the confirmed target schema')
            evidence[fault]['preserved'] = True
        repaired = checked(h.php('bin/console', 'kadupul:install', '--install', '--force', '--output=json'), 'Explicit repaired collector finishing retry')
        final = installer_receipt(h)
        require(final['state'] == 'succeeded' and final['pending_collectors'] == 0 and final['operation'] == 'finish', 'Repaired collector finishing retry did not succeed')
        require(h.sql('SELECT requires_sync FROM poller WHERE id=' + str(COLLECTOR_ID)).strip() == '', 'Real collector replication retained its pending marker')
        require(h.sql('SELECT UNIX_TIMESTAMP(last_sync)>0 FROM poller WHERE id=' + str(COLLECTOR_ID)).strip() == '1', 'Real collector replication did not acknowledge last_sync')
        remote = h.compose('exec', '-T', 'db', 'mariadb', '-uroot', '-pbehavior-root', '-N', '-B', COLLECTOR_SCHEMA,
                           data="SELECT name FROM host_template WHERE hash='dddddddddddddddddddddddddddddddd'; SELECT version FROM plugin_config WHERE directory='compatibility_test';")['stdout'].splitlines()
        require(remote == expected_remote, 'Repaired collector did not replace stale template/plugin data with primary values')
        require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Finishing retry changed representative data or RRD bytes')
        evidence['partial_collector_sync'].update(preserved=True, repaired_sync=True, final=final, command=repaired,
                                                   remote_before=remote_before, remote_after=remote,
                                                   collector_schema_snapshot_sha256=hashlib.sha256(dump.encode()).hexdigest())
        require_recovery_evidence(evidence)
    finally:
        h.sql('DROP TRIGGER IF EXISTS rehearsal_no_schema_replay')


def authenticate(h):
    h.base = 'http://' + h.compose('port', 'web', '80')['stdout'].strip()
    session = harness.Session(h.base)
    try:
        result = session.login('behavior-admin')
    except RuntimeError:
        failed = session.request('/index.php')
        h.capture('release/authentication-failure', {key: failed.get(key) for key in
                  ('status', 'url', 'title', 'login_form', 'admin_layout')})
        raise
    require(result['admin_layout'] and not result['login_form'], 'Admin login failed')
    return session


def assert_graph(h):
    graph = h.probe('graph')['result']
    require(isinstance(graph, dict) and bool(graph.get('source')), 'Graph definition is empty')
    # Drive the production rendering function, then inspect the returned PNG.
    code = '''chdir('/var/www/html'); $no_http_headers=true; include 'include/global.php'; include_once 'lib/rrd.php';
$id=(int)db_fetch_cell('SELECT MIN(gti.local_graph_id) FROM graph_templates_item gti INNER JOIN data_template_rrd dtr ON dtr.id=gti.task_item_id INNER JOIN data_local dl ON dl.id=dtr.local_data_id WHERE gti.local_graph_id>0');
ob_start(); $png=rrdtool_function_graph($id,0,array('graph_start'=>time()-3600,'graph_end'=>time(),'graph_width'=>400,'graph_height'=>120,'image_format'=>'png')); $diagnostics=ob_get_clean();
if (!is_string($png) || substr($png,0,8)!=="\\x89PNG\\r\\n\\x1a\\n") {fwrite(STDERR,'Graph did not return PNG: '.substr((string)$png.$diagnostics,0,200));exit(1);} echo json_encode(array('signature'=>'PNG','bytes'=>strlen($png)));'''
    return json.loads(checked(h.php('-r', code), 'Graph rendering')['stdout'])


def assert_plugin(h):
    observed = h.probe('plugin')
    result = observed['result']
    require(result.get('callbacks_observed') == 8, 'Plugin did not dispatch every callback')
    require(len(result.get('filter', [])) == 7 and all(row['input'] == row['result'] for row in result['filter']),
            'Plugin changed filter values')
    return observed


def assert_poll(h, label):
    h.truncate_artifacts('rrd-argv.log', 'rrd-stdin.log', 'plugin.jsonl')
    result = checked(h.php('poller.php', '--force'), label)
    calls = h.rrd_calls()
    require(any(call.startswith('update ') for call in calls), label + ' made no RRD updates')
    events = [row['args'][0][0] for row in h.jsonl('/artifacts/plugin.jsonl')
              if row.get('callback') == 'event' and row.get('args')]
    require('poller_top' in events and 'poller_bottom' in events, label + ' skipped plugin lifecycle hooks')
    return {'command': result, 'rrd_calls': calls, 'plugin_events': events}


def assert_failed_writer_retains_queue(h):
    row = h.sql("SELECT local_data_id,rrd_name FROM poller_item WHERE rrd_name != '' ORDER BY local_data_id LIMIT 1").strip().split('\t')
    require(len(row) == 2 and row[0].isdigit() and row[1].replace('_', '').isalnum(), 'No safe queue fixture key')
    predicate = "local_data_id=" + row[0] + " AND rrd_name='" + row[1] + "' AND time='2001-01-01 00:00:00'"
    h.sql("INSERT INTO poller_output(local_data_id,rrd_name,time,output) VALUES (" + row[0] + ",'" + row[1] + "','2001-01-01 00:00:00','8675309')")
    # An upgraded database can retain this inert setting; it must not revive the old queue swap.
    h.sql("REPLACE INTO settings(name,value) VALUES ('poller_refresh_output_table','on')")
    # Hostless data sources are valid. Deleted-host and deleted-source rows are not.
    fixture_ids = '16000000,16000001,16000002'
    require(h.sql("SELECT COUNT(*) FROM data_local WHERE id IN (" + fixture_ids + ")").strip() == '0', 'Queue fixture IDs already exist')
    require(h.sql("SELECT COUNT(*) FROM host WHERE id=16000001").strip() == '0', 'Deleted-device fixture already exists')
    h.sql("INSERT INTO data_local(id,host_id,data_template_id) VALUES (16000000,0,0),(16000001,16000001,0)")
    h.sql("INSERT INTO poller_output(local_data_id,rrd_name,time,output) VALUES (16000000,'fixture','2001-01-01','1'),(16000001,'fixture','2001-01-01','2'),(16000002,'fixture','2001-01-01','3')")
    before = rrd_manifest(h)
    checked(h.php('-r', 'if (!chmod("rra",0777)) {exit(1);}'), 'Unsafe storage fixture')
    try:
        result = h.php('poller.php', '--force')
        require(result['exit'] == 1, 'Unavailable writer did not fail the poller run')
        require(h.sql("SELECT output FROM poller_output WHERE " + predicate).strip() == '8675309', 'Unavailable writer consumed pending samples')
        require(rrd_manifest(h) == before, 'Unavailable writer changed RRD samples')
        require(h.sql("SELECT COUNT(*) FROM poller_output WHERE local_data_id IN (" + fixture_ids + ")").strip() == '3', 'Failed storage preflight changed the pending queue')
        checked(h.php('-r', 'if (!chmod("rra",0755)) {exit(1);}'), 'Restore storage before orphan cleanup')
        checked(h.php('poller.php', '--force'), 'Poll after restoring writer configuration')
        require(h.sql("SELECT local_data_id FROM poller_output WHERE local_data_id IN (" + fixture_ids + ") ORDER BY local_data_id").strip() == '16000000', 'Orphan cleanup removed a hostless source or retained a deleted device/source')
        return {'exit': result['exit'], 'queue_retained': True, 'rrd_unchanged': True}
    finally:
        checked(h.php('-r', 'if (!chmod("rra",0755)) {exit(1);}'), 'Restore storage permissions')
        h.sql("DELETE FROM poller_output WHERE local_data_id IN (" + fixture_ids + ")")
        h.sql("DELETE FROM data_local WHERE id IN (" + fixture_ids + ")")


def prepare_representative_snapshot(h):
    authenticate(h)
    h.poller_scenarios()
    checked(h.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--install'), 'Plugin install')
    checked(h.php('cli/plugin_manage.php', '--plugin=compatibility_test', '--enable'), 'Plugin enable')
    require(h.sql("SELECT COUNT(*) FROM host_template WHERE hash='dddddddddddddddddddddddddddddddd'").strip() == '0', 'Custom template fixture already exists')
    h.sql("INSERT INTO host_template(hash,name) VALUES ('dddddddddddddddddddddddddddddddd','Rehearsal custom template'); INSERT INTO host_template_graph(host_template_id,graph_template_id) SELECT ht.id,gl.graph_template_id FROM host_template ht CROSS JOIN graph_local gl WHERE ht.hash='dddddddddddddddddddddddddddddddd' ORDER BY gl.id LIMIT 1")
    require(h.sql("SELECT COUNT(*) FROM host_template_graph htg INNER JOIN host_template ht ON ht.id=htg.host_template_id WHERE ht.hash='dddddddddddddddddddddddddddddddd'").strip() == '1', 'Representative custom template has no graph association')
    h.sql("REPLACE INTO settings(name,value) VALUES ('graph_watermark','Operations custom watermark');")
    # Include a real nested RRD so the rehearsal exercises structured paths.
    checked(h.php('-r', '$files=glob("rra/*.rrd"); if (!$files || !mkdir("rra/structured") || !copy($files[0], "rra/structured/fixture.rrd")) {exit(1);}'), 'Structured RRD fixture')
    h.sql("INSERT INTO aggregate_graphs(aggregate_template_id,template_propogation,local_graph_id,title_format,graph_template_id,gprint_prefix,gprint_format,graph_type,total,total_type,total_prefix,order_type,created,user_id) VALUES (1,'',1,'migration-fixture',1,'','',0,0,0,'',0,'2001-01-01 00:00:00',1)")
    before_rrd = rrd_manifest(h)
    require(len(before_rrd) == 6 and 'structured/fixture.rrd' in before_rrd, 'Expected six RRD snapshot members including the structured fixture')
    before_domain = domain_state(h)
    return before_rrd, before_domain


def prepare_baseline(baseline_revision, baseline):
    """Keep real revision metadata for the same validation used by normal captures."""
    harness.run(['git', 'clone', '--shared', '--no-checkout', '--', str(ROOT), str(baseline)])
    # A shallow source can hold the pinned revision only in FETCH_HEAD; clone
    # does not necessarily transfer that object. Fetch the exact revision too.
    harness.run(['git', '-C', str(baseline), 'fetch', '--no-tags', '--depth=1',
                 '--', str(ROOT), baseline_revision])
    harness.run(['git', '-C', str(baseline), 'checkout', '--detach', '--force', baseline_revision])
    gitdir = Path(harness.run(['git', '-C', str(baseline), 'rev-parse', '--absolute-git-dir'])['stdout'].strip())
    require(gitdir.resolve() == (baseline / '.git').resolve(), 'Baseline must own its Git metadata')
    require(not harness.run(['git', '-C', str(baseline), 'status', '--porcelain'])['stdout'].strip(),
            'Baseline checkout must be complete and clean before applying test inputs')
    original_ignore = baseline / '.dockerignore'
    candidate_ignore = (ROOT / '.dockerignore').read_bytes()
    require(not original_ignore.is_symlink(), 'Baseline .dockerignore must not be a symlink')
    original_bytes = original_ignore.read_bytes() if original_ignore.exists() else None
    require(original_bytes is None or original_bytes == candidate_ignore,
            'Baseline .dockerignore differs from the controller; refusing to overwrite it')
    # The test infrastructure is candidate-owned; the application and
    # schema are the exact baseline revision checked out above.
    # Replace candidate-owned inputs; merging leaves deleted baseline helpers active.
    for relative in ('tests/Support/Behavior', 'tests/Fixtures', 'tests/behavior'):
        destination = baseline / relative
        if destination.is_symlink():
            destination.unlink()
        elif destination.exists():
            shutil.rmtree(destination)
    shutil.copytree(ROOT / 'tests/Support/Behavior', baseline / 'tests/Support/Behavior', dirs_exist_ok=True)
    shutil.copytree(ROOT / 'tests/Fixtures', baseline / 'tests/Fixtures', dirs_exist_ok=True)
    shutil.copytree(ROOT / 'tests/behavior', baseline / 'tests/behavior', dirs_exist_ok=True,
            ignore=shutil.ignore_patterns('results', '__pycache__'))
    shutil.copy2(ROOT / '.dockerignore', baseline / '.dockerignore')
    return {'baseline_sha256': hashlib.sha256(original_bytes).hexdigest() if original_bytes is not None else None,
            'applied_sha256': hashlib.sha256(candidate_ignore).hexdigest(),
            'added': original_bytes is None}


def legacy_build_inputs(baseline):
    """Build the old release with its retained dependency-complete image recipe."""
    import zipfile
    evidence = ROOT / 'tests/behavior/evidence/historical-baseline'
    recorded = json.loads((evidence / 'first.json').read_text())['provenance']['harness_inputs_sha256']
    paths = {'tests/behavior/Dockerfile': baseline / '.behavior-legacy.Dockerfile',
             '.dockerignore': baseline / '.behavior-legacy.Dockerfile.dockerignore'}
    digests = {}
    with zipfile.ZipFile(evidence / 'controller-inputs.zip') as archive:
        for source, destination in paths.items():
            content = archive.read(source)
            digest = hashlib.sha256(content).hexdigest()
            require(digest == recorded[source], 'Archived baseline build input hash mismatch: ' + source)
            require(not destination.exists() and not destination.is_symlink(), 'Baseline build input destination already exists')
            destination.write_bytes(content)
            digests[source] = digest
    return digests


def pinned_runtime_references():
    """Require the provenance label to describe the actual pinned PHP stage."""
    import re
    source = (ROOT / 'tests/behavior/Dockerfile').read_text()
    stages = re.findall(
        r'^FROM (ghcr\.io/kadupulhq/kadupul-mirrors/php:(8\.[1-4])-apache@sha256:[0-9a-f]{64}) AS php-\2\n'
        r'LABEL org\.kadupul\.behavior\.php-base="([^"\n]+)"$',
        source, re.MULTILINE)
    require(len(re.findall(r'^FROM ghcr\.io/kadupulhq/kadupul-mirrors/php:', source, re.MULTILINE)) == 4 and
            len(stages) == 4 and {version for _, version, _ in stages} ==
            {'8.1', '8.2', '8.3', '8.4'}, 'Missing or ambiguous pinned runtime provenance')
    require(all(reference == label for reference, _, label in stages),
            'PHP runtime provenance label differs from its FROM reference')
    return {version: reference for reference, version, _ in stages}


def pin_legacy_runtime(baseline):
    """Pin the owned historical recipe without changing its archived evidence."""
    version = os.environ.get('PHP_VERSION', '8.4')
    references = [reference for selected, reference in pinned_runtime_references().items()
                  if selected == version]
    require(len(references) == 1, 'Missing or ambiguous pinned rehearsal runtime')
    recipe = baseline / '.behavior-legacy.Dockerfile'
    require(recipe.is_file() and not recipe.is_symlink(), 'Missing owned historical recipe')
    original = recipe.read_bytes()
    source = original.decode()
    anchor = 'FROM php:${PHP_VERSION}-apache'
    require(source.splitlines().count(anchor) == 1, 'Unexpected historical runtime stage')
    reference = references[0]
    source = source.replace(anchor, 'FROM ' + reference + '\nLABEL org.kadupul.behavior.php-base="' + reference + '"', 1)
    recipe.write_text(source)
    return {'base_ref': reference,
            'archived_recipe_sha256': hashlib.sha256(original).hexdigest(),
            'executed_recipe_sha256': hashlib.sha256(recipe.read_bytes()).hexdigest()}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--baseline', default='6482af547c204199e829b7a0df0b7a13db3e0a58')
    parser.add_argument('--output', type=Path)
    args = parser.parse_args()
    project = 'kadupul-release-' + uuid.uuid4().hex
    output = (args.output or ROOT / 'tests/behavior/results/release-readiness' / project).resolve()
    output.mkdir(parents=True, exist_ok=True)
    evidence = {'complete': False, 'baseline_requested': args.baseline,
                'project': project, 'php_requested': os.environ.get('PHP_VERSION', '8.4'), 'steps': {}}
    h = None
    try:
        baseline_revision = checked(harness.run(['git', '-C', str(ROOT), 'rev-parse', '--verify', '--end-of-options', args.baseline + '^{commit}'], check=False), 'Baseline revision')['stdout'].strip()
        evidence['baseline'] = baseline_revision
        evidence['candidate'] = checked(harness.run(['git', '-C', str(ROOT), 'rev-parse', 'HEAD'], check=False), 'Candidate revision')['stdout'].strip()
        with tempfile.TemporaryDirectory(prefix='kadupul-release-') as temporary:
            temp = Path(temporary)
            baseline = temp / 'baseline'
            evidence['baseline_dockerignore'] = prepare_baseline(baseline_revision, baseline)
            evidence['baseline_build_inputs'] = legacy_build_inputs(baseline)
            evidence['baseline_runtime_pin'] = pin_legacy_runtime(baseline)
            harness.ROOT = baseline
            h = harness.Harness(SimpleNamespace(target='release-readiness', only=None, update_golden=False, project=project))
            # Use a dedicated project and keep the baseline image for rollback.
            h.dc = ['docker', 'compose', '-p', project, '-f', str(baseline / 'tests/behavior/compose.yml')]
            override = temp / 'phase.json'
            def phase(tree, image):
                web_build = {'context': str(tree)}
                legacy_dockerfile = str(baseline / '.behavior-legacy.Dockerfile')
                if tree == baseline:
                    web_build['dockerfile'] = legacy_dockerfile
                override.write_text(json.dumps({'services': {
                    'web': {'image': image, 'build': web_build},
                    'snmp': {'build': {'dockerfile': legacy_dockerfile}},
                }}))
                h.dc = h.dc[:6] + ['-f', str(override)]
            phase(baseline, project + '-baseline:local')
            h.setup()
            evidence['database_runtime'] = h.sql('SELECT VERSION()').strip()
            before_rrd, before_domain = prepare_representative_snapshot(h)
            evidence['steps']['baseline'] = {'version': h.sql('SELECT cacti FROM version').strip(),
                                            'rrd': before_rrd, 'graph': assert_graph(h),
                                            'runtime': h.base_image_digest()}
            # All poller calls are synchronous; no scheduled poller service is
            # started. Stop Apache before snapshotting the database and files.
            h.compose('stop', 'web')
            dump = h.compose('exec', '-T', 'db', 'mariadb-dump', '-uroot', '-pbehavior-root',
                             '--skip-comments', '--skip-dump-date', '--hex-blob', 'cacti')['stdout']
            snapshot = temp / 'rra'
            h.compose('cp', 'web:/var/www/html/rra', str(snapshot))
            evidence['snapshot_sha256'] = hashlib.sha256(dump.encode()).hexdigest()
            phase(ROOT, project + '-candidate:local')
            h.compose('up', '-d', '--build', '--wait', '--no-deps', 'web', timeout=1200)
            h.compose('cp', str(snapshot) + '/.', 'web:/var/www/html/rra')
            h.compose('exec', '-T', 'web', 'chown', '-R', 'www-data:www-data', '/var/www/html/rra')
            # A code-only cutover must explicitly migrate the old volatile queue
            # before either the web or collector account resumes writes.
            h.sql("INSERT INTO poller_output(local_data_id,rrd_name,time,output) VALUES (16000003,'migration','2001-01-01','42')")
            queue_before = h.sql('SELECT local_data_id,rrd_name,time,output FROM poller_output ORDER BY local_data_id,rrd_name,time')
            old_engine = h.sql("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='poller_output'").strip()
            probe_before = h.php('cli/upgrade_database.php', '--check-rrd-storage')
            require(old_engine.upper() == 'MEMORY' and probe_before['exit'] == 1, 'Baseline volatile queue was not refused')
            migrations = []
            for attempt in range(2):
                migrations.append(checked(h.php('cli/upgrade_database.php', '--migrate-poller-queue'), 'Durable queue migration'))
                require(h.sql('SELECT local_data_id,rrd_name,time,output FROM poller_output ORDER BY local_data_id,rrd_name,time') == queue_before, 'Queue migration changed retained samples')
                checked(h.php('cli/upgrade_database.php', '--check-rrd-storage'), 'Storage and durable queue probe')
            evidence['steps']['queue_migration'] = {'before_engine': old_engine, 'refused_volatile_queue': probe_before,
                                                   'commands': migrations, 'retained_samples_preserved': True}
            h.sql("DELETE FROM poller_output WHERE local_data_id=16000003 AND rrd_name='migration' AND time='2001-01-01' AND output='42'")
            idle_status = prepare_offline_collector(h)
            upgrade = h.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=3', '--force', '--automationmode=0')
            require(upgrade['exit'] != 0, 'Upgrade with an unavailable collector incorrectly reported complete success')
            actual_version = h.sql('SELECT cacti FROM version').strip()
            evidence['steps']['upgrade_attempt'] = {'command': upgrade, 'database_version': actual_version, 'source_version': checked(h.php('-r', 'echo file_get_contents("include/cacti_version");'), 'Source version')['stdout'].strip()}
            require(actual_version == (ROOT / 'include/cacti_version').read_text().strip(), 'Upgrade version mismatch: ' + actual_version)
            evidence['steps']['installer_recovery'] = {}
            rehearse_installer_recovery(h, before_rrd, before_domain, idle_status, evidence['steps']['installer_recovery'])
            from audit_schema_scenarios import assert_clean_schema_audit
            evidence['steps']['upgrade_schema_audit'] = assert_clean_schema_audit(h, 'Upgraded database')
            require(rrd_manifest(h) == before_rrd, 'Upgrade modified RRD bytes')
            require(domain_state(h) == before_domain, 'Upgrade changed device, source, graph or plugin identities')
            require(h.sql("SELECT value FROM settings WHERE name='graph_watermark'").strip() == 'Operations custom watermark', 'Upgrade changed custom watermark')
            authenticate(h)
            graph = assert_graph(h)
            plugin = assert_plugin(h)
            repeat = checked(h.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=3', '--force', '--automationmode=0'), 'Repeated upgrade')
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Repeated upgrade changed persisted data')
            # Exercise compatibility with historical completion cleanup too;
            # current finalization runs in the authorized installation worker.
            h.sql("DELETE FROM settings WHERE name LIKE 'install!_%' ESCAPE '!'")
            after_cleanup = checked(h.php('cli/install_cacti.php', '--accept-eula', '--install', '--mode=3', '--force', '--automationmode=0'), 'Repeated upgrade after historical transient cleanup')
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Post-completion repeat changed persisted data')
            poll = assert_poll(h, 'Candidate poller')
            evidence['steps']['upgrade'] = {'command': upgrade, 'repeat': repeat, 'after_web_cleanup': after_cleanup, 'graph': graph, 'plugin': plugin,
                                            'poller': poll, 'rrd_preserved_before_poll': True,
                                            'cleanup_scope': 'Historical transient flags removed directly; current finalization already ran in the worker.'}
            evidence['steps']['unavailable_writer'] = assert_failed_writer_retains_queue(h)
            # Restore the old code AND its matching DB/RRD snapshot. A code-only
            # downgrade is not an acceptable rollback of a schema upgrade.
            h.compose('stop', 'web')
            h.sql('DROP DATABASE cacti; CREATE DATABASE cacti CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
            h.sql(dump)
            phase(baseline, project + '-baseline:local')
            h.compose('up', '-d', '--no-build', '--wait', '--no-deps', 'web', timeout=180)
            h.compose('cp', str(snapshot) + '/.', 'web:/var/www/html/rra')
            h.compose('exec', '-T', 'web', 'chown', '-R', 'www-data:www-data', '/var/www/html/rra')
            require(rrd_manifest(h) == before_rrd and domain_state(h) == before_domain, 'Rollback did not restore snapshot')
            require(h.sql('SELECT cacti FROM version').strip() == evidence['steps']['baseline']['version'], 'Rollback version mismatch')
            require(h.sql("SELECT value FROM settings WHERE name='graph_watermark'").strip() == 'Operations custom watermark', 'Rollback lost custom watermark')
            authenticate(h)
            evidence['steps']['rollback'] = {'graph': assert_graph(h), 'plugin': assert_plugin(h),
                                             'poller': assert_poll(h, 'Rollback poller'),
                                             'snapshot_restored': True}
            require_recovery_evidence(evidence['steps'].get('installer_recovery', {}))
            evidence['complete'] = True
    except Exception as error:
        evidence['error'] = str(error)
        if getattr(error, 'rehearsal_cleanup_errors', None):
            evidence['fault_cleanup_errors'] = error.rehearsal_cleanup_errors
        print(error, file=sys.stderr)
    finally:
        # The temporary compose tree can be gone after an exception. Project
        # containers are removed directly using their dedicated project label.
        try:
            cleanup = harness.run(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project], check=False)
            ids = cleanup['stdout'].split()
            if ids:
                harness.run(['docker', 'rm', '-fv', *ids], check=False)
            harness.run(['docker', 'network', 'rm', project + '_default'], check=False)
            harness.run(['docker', 'image', 'rm', project + '-baseline:local', project + '-candidate:local', project + '-snmp:latest'], check=False)
        except Exception as error:
            evidence['cleanup_error'] = str(error)
            evidence['complete'] = False
        if h is not None:
            evidence['baseline_observations'] = h.observed
        harness.write_json(output / 'observations.json', evidence)
    print(json.dumps({'complete': evidence['complete'], 'output': str(output)}))
    return 0 if evidence['complete'] else 1


if __name__ == '__main__':
    sys.exit(main())
