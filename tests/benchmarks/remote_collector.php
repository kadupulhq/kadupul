<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace RemoteCollectorProfile;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$root = getenv('PROFILE_SOURCE_ROOT') ?: dirname(__DIR__, 2);
$kind = getenv('PROFILE_KIND') ?: 'recovery';
$count = (int) (getenv('PROFILE_ROWS') ?: 10000);
$per_source = max(1, (int) (getenv('PROFILE_SAMPLES_PER_SOURCE') ?: 1));
$output_bytes = max(1, min(512, (int) (getenv('PROFILE_OUTPUT_BYTES') ?: 2)));
if (!in_array($kind, array('recovery', 'replacement', 'resources'), true) || $count < 1 || $count > 1000000) {
	throw new \RuntimeException('Expected recovery/replacement/resources and 1..1000000 rows');
}
$poller = file_get_contents($root . '/lib/poller.php');
$recovery = file_get_contents($root . '/poller_recovery.php');
$boost = file_get_contents($root . '/lib/boost.php');
foreach (array('replicate_out_table', 'replicate_out_execute', 'replicate_out_replace_execute', 'replicate_out_replace_finish', 'cache_in_path', 'update_db_from_path', 'md5sum_path', 'should_ignore_from_replication') as $name) {
	eval('namespace ' . __NAMESPACE__ . '; use \\Throwable;' . test_php_function_source($poller, $name));
}
foreach (array('recovery_delete_acknowledged_rows', 'recovery_owned_data_source_ids') as $name) {
	eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($recovery, $name));
}
eval('namespace ' . __NAMESPACE__ . '; use \\Throwable;' . test_php_function_source($boost, 'boost_flush_output_batch'));
if (!preg_match('/^\$record_limit = ([0-9]+);/m', $recovery, $limit_match)) throw new \RuntimeException('Missing production batch limit');
$limit = (int)$limit_match[1];
eval('namespace ' . __NAMESPACE__ . '; function run_recovery($local_db_cnn_id, $remote_db_cnn_id) {
    $run = true; $poller_id = 2; $record_limit = ' . $limit . '; $sleep_time = 1;
    $records_inserted = 0; $transfer_failed = false;' . test_php_block_source($recovery, 'if ($run)') . '
    if ($transfer_failed) throw new \\RuntimeException("Recovery failed");
    return $records_inserted;
}');
foreach (array('POLLER_VERBOSITY_NONE'=>0, 'POLLER_VERBOSITY_LOW'=>2, 'POLLER_VERBOSITY_MEDIUM'=>3) as $name=>$value) define($name, $value);
function connect() : \PDO {
	return new \PDO('mysql:host=' . (getenv('BOOST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('BOOST_DB_PORT') ?: '3306') . ';dbname=' . (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract'), getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION));
}
function query($sql, $params = array(), $conn = false) {
	$conn = $conn ?: $GLOBALS['profile_local']; $start = hrtime(true);
	$stmt = $conn->prepare($sql); $stmt->execute($params);
	$key = str_contains($sql, 'MAX(time)') ? 'queue_cutoff' : (str_contains($sql, 'ORDER BY time') ? 'queue_fetch' : strtok($sql, ' '));
	$GLOBALS['profile_queries'][$key]['count'] = ($GLOBALS['profile_queries'][$key]['count'] ?? 0) + 1;
	$GLOBALS['profile_queries'][$key]['seconds'] = ($GLOBALS['profile_queries'][$key]['seconds'] ?? 0) + (hrtime(true) - $start) / 1e9;
	$GLOBALS['profile_affected'][spl_object_id($conn)] = $stmt->rowCount();
	return $stmt;
}
function db_fetch_cell($sql, $col='', $log=true, $conn=false) { return query($sql, array(), $conn)->fetchColumn(); }
function db_fetch_cell_prepared($sql, $params=array(), $col='', $log=true, $conn=false) { return query($sql, $params, $conn)->fetchColumn(); }
function db_fetch_row($sql, $log=true, $conn=false) { return query($sql, array(), $conn)->fetch(\PDO::FETCH_ASSOC); }
function db_fetch_assoc($sql, $log=true, $conn=false) { return query($sql, array(), $conn)->fetchAll(\PDO::FETCH_ASSOC); }
function db_fetch_assoc_prepared($sql, $params=array(), $log=true, $conn=false) { return query($sql, $params, $conn)->fetchAll(\PDO::FETCH_ASSOC); }
function db_execute($sql, $log=true, $conn=false) { query($sql, array(), $conn); return true; }
function db_execute_prepared($sql, $params=array(), $log=true, $conn=false) { query($sql, $params, $conn); return true; }
function db_affected_rows($conn=false) { return $GLOBALS['profile_affected'][spl_object_id($conn ?: $GLOBALS['profile_local'])] ?? 0; }
function db_table_exists($table, $log=true, $conn=false) { return query('SHOW CREATE TABLE ' . $table, array(), $conn)->fetchColumn() !== false; }
function db_column_exists($table, $col, $log=true, $conn=false) { return in_array($col, array_column(db_fetch_assoc('SHOW COLUMNS FROM ' . $table, $log, $conn), 'Field'), true); }
function db_qstr($value, $conn=false) { return $value === null ? 'NULL' : ($conn ?: $GLOBALS['profile_local'])->quote((string)$value); }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function array_rekey($v, $key, $value) { return array_column($v, $value, $key); }
function cacti_log(...$args) {}
function replicate_log(...$args) {}
function sleep($seconds) { $GLOBALS['profile_sleep'] += $seconds; }
function poller_push_reindex_data_to_poller(...$args) { return true; }
function read_config_option($key) { return $GLOBALS['profile_settings'][$key] ?? ''; }
function set_config_option($key, $value) { $GLOBALS['profile_settings'][$key] = $value; }
function sql_save($row, $table) {
	query('INSERT INTO poller_resource_cache(path,md5sum,contents) VALUES(?,?,?) ON DUPLICATE KEY UPDATE md5sum=VALUES(md5sum),contents=VALUES(contents)', array($row['path'],$row['md5sum'],$row['contents']));
	return 1;
}
$local = connect(); $main = connect(); $GLOBALS['profile_local'] = $local;
$GLOBALS['profile_queries'] = array(); $GLOBALS['profile_sleep'] = 0; $GLOBALS['profile_settings'] = array();
$GLOBALS['replicate_out_success'] = true;
$owned_files = array(); $directory = false;
try {
	if ($kind === 'recovery') {
		foreach (array($local, $main) as $conn) {
			$conn->exec('CREATE TEMPORARY TABLE poller_output_boost(local_data_id INT UNSIGNED, rrd_name VARCHAR(19), time TIMESTAMP, output VARCHAR(512), PRIMARY KEY(local_data_id,time,rrd_name)) ENGINE=InnoDB');
		}
		$local->exec('CREATE TEMPORARY TABLE poller_output LIKE poller_output_boost');
		$local->exec('CREATE TEMPORARY TABLE settings(name VARCHAR(255) PRIMARY KEY,value VARCHAR(255)) ENGINE=InnoDB');
		$main->exec('CREATE TEMPORARY TABLE poller(id INT PRIMARY KEY,status INT) ENGINE=InnoDB');
		$main->exec('INSERT INTO poller VALUES(2,1)');
		$main->exec('CREATE TEMPORARY TABLE poller_item(local_data_id INT PRIMARY KEY,poller_id INT) ENGINE=InnoDB');
		for ($first=1; $first <= $count; $first+=1000) {
			$values = $ids = array();
			for ($id=$first; $id < min($first+1000,$count+1); $id++) {
				$values[] = "(" . (intdiv($id-1,$per_source)+1) . ",'value','" . gmdate('Y-m-d H:i:s', 1793491200 + (($id-1)%$per_source)*60) . "','" . str_repeat('4', $output_bytes) . "')";
                if (($id-1)%$per_source === 0) $ids[] = '(' . (intdiv($id-1,$per_source)+1) . ',2)';
			}
			$local->exec('INSERT INTO poller_output_boost VALUES ' . implode(',', $values));
			if ($ids) $main->exec('INSERT INTO poller_item VALUES ' . implode(',', $ids));
		}
	} elseif ($kind === 'replacement') {
		foreach (array($local,$main) as $conn) $conn->exec('CREATE TEMPORARY TABLE data_input(id INT PRIMARY KEY,name VARCHAR(200) NOT NULL DEFAULT \'\') ENGINE=InnoDB');
		for ($first=1; $first <= $count; $first+=1000) {
			$values=array(); for ($id=$first; $id<min($first+1000,$count+1); $id++) $values[]="($id,'name-$id')";
			$local->exec('INSERT INTO data_input VALUES ' . implode(',',$values));
		}
	} else {
		$directory = sys_get_temp_dir() . '/collector-profile-' . bin2hex(random_bytes(8));
		mkdir($directory); $GLOBALS['config'] = array('base_path'=>$directory);
		for ($i=0; $i<$count; $i++) { $file=$directory . '/resource-' . $i . '.txt'; file_put_contents($file,str_repeat('data',256)); $owned_files[]=$file; }
		$local->exec('CREATE TEMPORARY TABLE poller_resource_cache(id INT AUTO_INCREMENT PRIMARY KEY,path VARCHAR(255) UNIQUE,md5sum CHAR(32),contents TEXT) ENGINE=InnoDB');
	}
	$cpu = getrusage(); $start = hrtime(true);
	if ($kind === 'recovery') {
		$delivered = run_recovery($local, $main);
		if ($delivered !== $count || (int)$local->query('SELECT COUNT(*) FROM poller_output_boost')->fetchColumn() !== 0 || (int)$main->query('SELECT COUNT(*) FROM poller_output_boost')->fetchColumn() !== $count) throw new \RuntimeException('Incomplete recovery profile');
	} elseif ($kind === 'replacement') {
		$data = db_fetch_assoc('SELECT * FROM data_input');
		if (!replicate_out_table($main,$data,'data_input',2) || (int)$main->query('SELECT COUNT(*) FROM data_input')->fetchColumn() !== $count) throw new \RuntimeException('Incomplete replacement profile');
	} else {
		cache_in_path($directory,'scripts');
		if ((int)$local->query('SELECT COUNT(*) FROM poller_resource_cache')->fetchColumn() !== $count) throw new \RuntimeException('Incomplete resource profile');
		$first_seconds=(hrtime(true)-$start)/1e9;
		$unchanged_start=hrtime(true); cache_in_path($directory,'scripts');
		$unchanged_seconds=(hrtime(true)-$unchanged_start)/1e9;
	}
	$seconds=(hrtime(true)-$start)/1e9; $after=getrusage();
	$cpu_seconds=($after['ru_utime.tv_sec']-$cpu['ru_utime.tv_sec'])+($after['ru_utime.tv_usec']-$cpu['ru_utime.tv_usec'])/1e6+($after['ru_stime.tv_sec']-$cpu['ru_stime.tv_sec'])+($after['ru_stime.tv_usec']-$cpu['ru_stime.tv_usec'])/1e6;
	print json_encode(array('kind'=>$kind,'source_root'=>$root,'php'=>PHP_VERSION,'database'=>$local->query('SELECT VERSION()')->fetchColumn(),'rows'=>$count,'samples_per_source'=>$per_source,'output_bytes'=>$output_bytes,'batch_rows'=>$limit,'seconds'=>$seconds,'php_cpu_seconds'=>$cpu_seconds,'peak_bytes'=>memory_get_peak_usage(true),'requested_sleep_seconds'=>$GLOBALS['profile_sleep'],'queries'=>$GLOBALS['profile_queries'],'resource_first_seconds'=>$first_seconds??null,'resource_unchanged_seconds'=>$unchanged_seconds??null,'scope'=>'Actual extracted production functions; temporary native tables; metadata handoff and scheduler sleep excluded; warm local database, no network latency simulation.'), JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
	foreach ($owned_files as $file) unlink($file);
	if ($directory !== false) rmdir($directory);
}
