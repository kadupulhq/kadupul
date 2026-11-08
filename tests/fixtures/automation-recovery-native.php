<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
namespace AutomationRecoveryFixture;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;
use OutOfRangeException;
use DateTimeZone;
use DateTimeImmutable;

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
require $root . '/tests/Helpers/PhpSource.php';
$runtime = file_get_contents($root . '/lib/automation_runtime.php');
$api = file_get_contents($root . '/lib/api_automation.php');
$names = array();
preg_match_all('/^function (\w+)\(/m', $runtime, $names);
foreach ($names[1] as $name) {
	if (!in_array($name, array('automation_start_child','automation_default_connection'), true)) {
		eval('namespace AutomationRecoveryFixture; use PDO; use PDOStatement; use RuntimeException; use Throwable; use OutOfRangeException; use DateTimeZone; use DateTimeImmutable; ' . \test_php_function_source($runtime, $name));
	}
}
foreach (array('automation_primeIPAddressTable','calculateNextStartForYear') as $name) {
	eval('namespace AutomationRecoveryFixture; use PDO; use RuntimeException; ' . \test_php_function_source($api, $name));
}
function automation_debug($message) {}
function cacti_log(...$args) {}
function getNetworkDevices($network) { return array(); }
$reports = 0;
function reportNetworkStatus($network, $old) { $GLOBALS['reports']++; }
function automation_start_child(array $arguments) {
	if ($GLOBALS['scenario'] === 'launch-failure' && in_array('--thread=2', $arguments, true)) { throw new RuntimeException('fixture launch failure'); }
	$args = array(PHP_BINARY, __FILE__, '--child', $GLOBALS['scenario']);
	foreach ($arguments as $argument) {
		if (str_starts_with($argument, '--network=') || str_starts_with($argument, '--thread=') || str_starts_with($argument, '--run=')) {
			$args[] = $argument;
		}
	}
	$null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
	$process = proc_open($args, array(0=>array('file',$null,'r'),1=>array('file',$null,'a'),2=>array('file',$null,'a')), $pipes);
	if (!is_resource($process)) { throw new RuntimeException('fixture child creation failed'); }
	return $process;
}
$host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$port = getenv('BOOST_DB_PORT') ?: '3306';
$database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
if (!in_array($host, array('127.0.0.1','localhost'), true) || !in_array($database, array('cacti_boost_contract','cacti_automation_contract'), true)) {
	throw new RuntimeException('Use only the isolated automation database.');
}
$dsn = "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4";
$connection = new PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_FOUND_ROWS=>true));
$connection->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$scenario = $argv[1] ?? '';
if ($scenario === '--child') {
	$scenario = $argv[2];
	$options = array();
	foreach (array_slice($argv,3) as $arg) { list($key,$value)=explode('=',$arg,2); $options[$key]=$value; }
	$network=(int)$options['--network']; $thread=(int)$options['--thread']; $run=$options['--run'];
	$lock=automation_lock_name($connection,$network,$thread);
	if (!automation_lock($connection,$lock) || !automation_run_active($connection,$network,$run)) { exit(2); }
	automation_query($connection,"INSERT INTO automation_processes (pid,poller_id,network_id,run_id,connection_id,task,status,heartbeat,command) VALUES (?,1,?,?,CONNECTION_ID(),'collector','running',NOW(),'start')",array(getmypid(),$network,$run));
	if ($scenario === 'schedule-edit' && $thread === 1) { $connection->exec("UPDATE automation_networks SET next_start='2035-01-01 09:00:00' WHERE id=1"); }
	if ($scenario === 'child-failure') { exit(7); }
	if ($scenario === 'cancel') { automation_query($connection, "UPDATE automation_processes SET command='cancel' WHERE network_id=? AND run_id=? AND task='tmaster'", array($network,$run)); sleep(20); exit(0); }
	if ($scenario === 'read-failure' && $thread === 1) { $connection->exec('ALTER TABLE automation_processes RENAME COLUMN command TO unavailable_command'); sleep(20); exit(0); }
	if (in_array($scenario, array('deadline', 'launch-failure'), true)) { sleep(20); exit(0); }
	while ($row=automation_claim_address($connection,$network,$run,getmypid(),$thread)) {
		automation_query($connection,'UPDATE automation_ips SET status=2 WHERE network_id=? AND run_id=? AND ip_address=? AND pid=?',array($network,$run,$row['ip_address'],getmypid()));
	}
	automation_query($connection,"UPDATE automation_processes SET status='done' WHERE network_id=? AND run_id=? AND pid=?",array($network,$run,getmypid()));
	automation_unlock($connection,$lock);
	exit(0);
}
$checks=0; $created=array();
function check(bool $condition, string $message): void {
	$GLOBALS['checks']++;
	if (!$condition) { throw new RuntimeException($message); }
}
function seed(PDO $connection, int $id, string $range): array {
	automation_query($connection,"INSERT INTO automation_networks (id,name,poller_id,subnet_range,enabled,snmp_id,threads,run_limit,sched_type,recur_every,start_at,next_start,day_of_week,month,day_of_month,monthly_week,monthly_day)
		VALUES (?,'fixture',1,?,'on',1,2,10,2,1,'2020-01-01 09:00:00','2020-01-01 09:00:00','2','1','1','1','1')",array($id,$range));
	return automation_query($connection,'SELECT * FROM automation_networks WHERE id=?',array($id))->fetch(PDO::FETCH_ASSOC);
}
try {
	$schema=file_get_contents($root.'/cacti.sql');
	foreach (array('automation_ips','automation_processes','automation_networks') as $table) {
		if (!preg_match('/CREATE TABLE `'.$table.'` \(.*?;/s',$schema,$m)) { throw new RuntimeException('Missing production schema'); }
		$connection->exec($m[0]); $created[]=$table;
	}
	$network=seed($connection,1,'192.0.2.0/29,192.0.2.0/30');
	$other=new PDO($dsn,getenv('BOOST_DB_USER') ?: 'root',getenv('BOOST_DB_PASSWORD') ?: '',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::MYSQL_ATTR_FOUND_ROWS=>true));
	if ($scenario==='ownership') {
		$lock=automation_acquire_master($connection,1);
		check(is_string($lock),'first owner admitted');
		check(automation_acquire_master($other,1)===null,'second owner denied');
		automation_unlock($connection,$lock);
		$child=automation_lock_name($connection,1,1);
		check(automation_lock($connection,$child),'old child holds its lock');
		check(automation_acquire_master($other,1)===null,'successor blocked by live predecessor child');
		automation_unlock($connection,$child);
		$lock=automation_acquire_master($other,1);
		check(is_string($lock),'successor admitted after predecessor release');
		automation_unlock($other,$lock);
	} elseif ($scenario==='queue') {
		$run=str_repeat('a',32); $run2=str_repeat('b',32);
		seed($connection,2,'192.0.2.0/29');
		$connection->beginTransaction();
		check(automation_primeIPAddressTable(1,$run,$connection,microtime(true)+10)===6,'deduplicated first network');
		check(automation_primeIPAddressTable(2,$run2,$connection,microtime(true)+10)===6,'overlapping second network retained');
		$connection->commit();
		$lock=automation_acquire_master($connection,1);
		automation_query($connection,"INSERT INTO automation_processes (pid,network_id,run_id,connection_id,task,status,heartbeat,command) VALUES (101,1,?,CONNECTION_ID(),'tmaster','running',NOW(),'start')",array($run));
		$row=automation_claim_address($connection,1,$run,501,1);
		check((int)$row['network_id']===1,'claim stays in network');
		$row2=automation_claim_address($connection,1,$run,502,2);
		check($row['ip_address']!==$row2['ip_address'],'claims are distinct');
		automation_release_worker($connection,1,$run,501);
		check((int)automation_query($connection,'SELECT COUNT(*) FROM automation_ips WHERE network_id=1')->fetchColumn()===6,'worker cleanup retains entire queue');
		check((int)automation_query($connection,'SELECT COUNT(*) FROM automation_ips WHERE network_id=1 AND pid=502')->fetchColumn()===1,'sibling claim survives');
		check(!automation_run_active($connection,1,$run2),'stale run is rejected');
		$rejected=false; try { automation_claim_address($connection,2,$run,501,1); } catch (RuntimeException $expected) { $rejected=true; } check($rejected,'wrong network rejected before read');
		automation_unlock($connection,$lock);
	} elseif ($scenario==='borrowed-transaction') {
		$connection->beginTransaction();
		$connection->exec("UPDATE automation_networks SET name='caller work' WHERE id=1");
		$rejected=false;
		try { automation_run_network($connection,1,1,true,false,false); } catch (RuntimeException $error) { $rejected=true; }
		check($rejected,'caller transaction is rejected');
		check($connection->inTransaction(),'caller transaction remains open');
		check($connection->query('SELECT name FROM automation_networks WHERE id=1')->fetchColumn()==='caller work','caller writes preserved');
		$connection->rollBack();
	} elseif ($scenario==='priming-write') {
		$oldRun=str_repeat('c',32);
		$connection->beginTransaction();
		automation_primeIPAddressTable(1,$oldRun,$connection,microtime(true)+10);
		$connection->commit();
		$connection->exec("UPDATE automation_networks SET subnet_range='192.0.0.0/21' WHERE id=1");
		$connection->exec("ALTER TABLE automation_ips ADD CONSTRAINT reject_later_batch CHECK (ip_address <> '192.0.4.1')");
		check(!automation_run_network($connection,1,1,true,false,false),'second priming batch failure is returned');
		check((int)$connection->query('SELECT COUNT(*) FROM automation_ips')->fetchColumn()===6,'earlier insert batch and deletion rolled back');
		check($connection->query('SELECT DISTINCT run_id FROM automation_ips')->fetchColumn()===$oldRun,'previous queue identity retained');
		check($connection->query('SELECT next_start FROM automation_networks WHERE id=1')->fetchColumn()===$network['next_start'],'due time retained');
		check($GLOBALS['reports']===0,'failed preparation does not report');
		$connection->exec('ALTER TABLE automation_ips DROP CONSTRAINT reject_later_batch');
		$GLOBALS['scenario']='success';
		check(automation_run_network($connection,1,1,true,false,false),'full queue publishes on retry');
	} elseif ($scenario==='schema') {
		automation_require_schema($connection); check(true,'fresh schema admitted');
		$connection->exec('ALTER TABLE automation_ips DROP INDEX claim');
		$rejected=false; try { automation_require_schema($connection); } catch (RuntimeException $expected) { $rejected=true; } check($rejected,'missing claim index rejected');
		$connection->exec('ALTER TABLE automation_ips ADD INDEX claim (network_id,run_id,status,pid)');
		$connection->exec('ALTER TABLE automation_ips MODIFY run_id char(8) NOT NULL DEFAULT \'\'');
		$rejected=false; try { automation_require_schema($connection); } catch (RuntimeException $expected) { $rejected=true; } check($rejected,'truncated run identity rejected');
		$connection->exec('ALTER TABLE automation_ips MODIFY run_id char(32) NOT NULL DEFAULT \'\'');
		$connection->exec('ALTER TABLE automation_ips ENGINE=MEMORY');
		$rejected=false; try { automation_require_schema($connection); } catch (RuntimeException $expected) { $rejected=true; } check($rejected,'unsafe engine rejected');
	} else {
		if ($scenario==='disabled-force') { $connection->exec("UPDATE automation_networks SET enabled='' WHERE id=1"); }
		if ($scenario==='deadline') { $connection->exec('UPDATE automation_networks SET run_limit=1 WHERE id=1'); }
		// CHECK constraints inject real server failures without binary-log trigger privileges.
		if ($scenario==='completion-write') {
			$connection->exec("ALTER TABLE automation_networks ADD CONSTRAINT reject_completion CHECK (last_status <> 'completed')");
		}
		if ($scenario==='schedule-write') {
			$connection->exec("ALTER TABLE automation_networks ADD CONSTRAINT reject_schedule CHECK (next_start = '2020-01-01 09:00:00')");
		}
		$started=microtime(true);
		// The installed connection uses silent errors and matched-row counts.
		$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
		$success=automation_run_network($connection,1,1,!in_array($scenario,array('manual-success','disabled-force'),true),$scenario==='disabled-force',false);
		$state=automation_query($connection,'SELECT * FROM automation_networks WHERE id=1')->fetch(PDO::FETCH_ASSOC);
		if (in_array($scenario,array('success','manual-success','schedule-edit','disabled-force'),true)) {
			check($success,'complete run succeeds');
			if (in_array($scenario,array('manual-success','disabled-force'),true)) {
				check($state['next_start']===$network['next_start'],'manual run does not consume scheduled occurrence');
			} elseif ($scenario==='schedule-edit') {
				check($state['next_start']==='2035-01-01 09:00:00','administrator schedule edit survives completion');
			} else {
				check(strtotime($state['next_start'])>time(),'successful run advances schedule');
			}
			check((int)automation_query($connection,'SELECT COUNT(*) FROM automation_ips')->fetchColumn()===0,'completed queue cleaned');
			check($GLOBALS['reports']===1,'completed run reports once');
		} else {
			check(!$success,'failed run must fail');
			check($state['next_start']===$network['next_start'],'failed run preserves due occurrence');
			check((int)automation_query($connection,'SELECT COUNT(*) FROM automation_ips')->fetchColumn()===6,'failed run retains queued work');
			check($GLOBALS['reports']===0,'failed run does not report success');
			if ($scenario==='deadline') { check(microtime(true)-$started<8,'parent enforces a real child deadline'); }
			if ($scenario==='completion-write') { $connection->exec('ALTER TABLE automation_networks DROP CONSTRAINT reject_completion'); }
			if ($scenario==='schedule-write') { $connection->exec('ALTER TABLE automation_networks DROP CONSTRAINT reject_schedule'); }
			if ($scenario==='read-failure') { $connection->exec('ALTER TABLE automation_processes RENAME COLUMN unavailable_command TO command'); }
			$GLOBALS['scenario']='success';
			check(automation_run_network($connection,1,1,true,false,false),'retained work succeeds on retry');
		}
	}
	print json_encode(array('scenario'=>$scenario,'checks'=>$checks,'php'=>PHP_VERSION,'database'=>$connection->query('SELECT VERSION()')->fetchColumn()))."\n";
} finally {
	if ($connection->inTransaction()) { $connection->rollBack(); }
	foreach (array_reverse($created) as $table) { $connection->exec('DROP TABLE '.$table); }
}
