<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
namespace CommandsReview;
$repo=dirname(__DIR__, 2);
require $repo.'/include/vendor/autoload.php';
require $repo.'/tests/Helpers/PhpSource.php';
$source=file_get_contents($repo.'/poller_commands.php');
if ($source===false) { throw new \RuntimeException('Missing source'); }
$block=\test_php_block_source($source,'} else', 'NOTE: No Poller Commands found for processing');
$port=getenv('BOOST_DB_PORT') ?: '3306';
$pdo=new \PDO('mysql:host=127.0.0.1;port='.$port.';dbname=cacti_boost_contract',getenv('BOOST_DB_USER') ?: 'root',getenv('BOOST_DB_PASSWORD') ?: '',[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
$pdo->exec("SET timestamp=1794139200");
$pdo->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='poller_command'")->fetchColumn()!==0) { throw new \RuntimeException('Existing table'); }
$schema=file_get_contents($repo.'/cacti.sql');
if (preg_match('/CREATE TABLE poller_command \(.*?\) ENGINE=InnoDB[^;]*;/s',$schema,$m)!==1) { throw new \RuntimeException('Missing DDL'); }
$pdo->exec($m[0]);
define('POLLER_COMMAND_REINDEX',1); define('POLLER_COMMAND_PURGE',3); define('MAX_RECACHE_RUNTIME',1800);
function statement($sql,$args=[]) { global $pdo; $s=$pdo->prepare($sql); $s->execute($args); return $s; }
function db_fetch_cell_prepared($sql,$args=[],$default='',$log=true,$cnn=false) { return statement($sql,$args)->fetchColumn(); }
function db_fetch_assoc_prepared($sql,$args=[],$log=true,$cnn=false) { return statement($sql,$args)->fetchAll(\PDO::FETCH_ASSOC); }
function db_execute_prepared($sql,$args=[],$log=true,$cnn=false) { try { statement($sql,$args); return true; } catch (\PDOException $e) { return false; } }
function cacti_sizeof($a) { return is_array($a)?count($a):0; }
function read_config_option($k) { return 300; }
function register_process_start(...$a) { return true; }
function unregister_process(...$a) {}
function cacti_log(...$a) { global $logs; $logs[]=$a[0]; }
function run_data_query($h,$q,$automation=false,$force=false) {
    global $scenario,$calls,$pdo;
    $calls[]=$q;
    if ($scenario==='arrival' && count($calls)===1) {
        $pdo->exec("INSERT INTO poller_command(poller_id,action,command,time,last_updated) VALUES(1,1,'1:3',NOW(),NOW())");
    }
    if (in_array($scenario, ['replacement', 'same-second'], true) && count($calls)===1) {
        $pdo->exec("REPLACE INTO poller_command(poller_id,action,command,time) VALUES(1,1,'1:2',NOW())");
    }
    if ($scenario==='ack-failure' && count($calls)===1) {
        $pdo->exec('ALTER TABLE poller_command RENAME COLUMN last_updated TO unavailable_updated');
    }
    if ($scenario==='exception') { throw new \RuntimeException('Reindex failed'); }
    return $scenario!=='failure';
}
$results=[];
set_error_handler(function($severity,$message) { global $warnings; $warnings[]=$message; return true; });
try {
    $scenario=$argv[1]??'';
    $expected=['success'=>0,'failure'=>2,'exception'=>2,'deadline'=>1,'arrival'=>3,'replacement'=>1,'same-second'=>2,'ack-failure'=>2];
    if (!array_key_exists($scenario,$expected)) { throw new \RuntimeException('Unknown queue scenario'); }
    $time=in_array($scenario,['arrival','same-second'],true)?'2026-11-08 12:00:00':'2026-11-08 11:59:59';
    $pdo->exec("INSERT INTO poller_command(poller_id,action,command,time,last_updated) VALUES(1,1,'1:1','$time','$time'),(1,1,'1:2','$time','$time')");
    $poller_id=1; $host_id=1; $poller_db_cnn_id=$pdo; $verbosity=0;
    $config=['poller_id'=>1]; $database_hostname='127.0.0.1'; $database_port=$port; $database_default='cacti_boost_contract';
    $database_sessions=["$database_hostname:$database_port:$database_default"=>$pdo];
    $calls=[]; $logs=[]; $warnings=[]; $start=microtime(true)-($scenario==='deadline'?1801:0);
    eval('namespace CommandsReview; use Throwable; use PDO; use RuntimeException; if(false){'.$block);
    $left=statement('SELECT command FROM poller_command ORDER BY command')->fetchAll(\PDO::FETCH_COLUMN);
    $successLogs=count(array_filter($logs,fn($l)=>str_contains($l,'Recached successfully')));
    if (count($left)!==$expected[$scenario] || count($calls)!==($scenario==='deadline'?1:2) || $warnings!==[]) {
        throw new \RuntimeException('Queue outcome: '.json_encode([$scenario,$calls,$left,$logs,$warnings]));
    }
    if (in_array($scenario,['failure','exception'],true) && $successLogs!==0) { throw new \RuntimeException('False success log'); }
    if ($scenario==='ack-failure' && count(array_filter($logs,fn($l)=>str_contains($l,'acknowledgement could not be confirmed')))!==2) { throw new \RuntimeException('Acknowledgement error hidden'); }
    if (in_array($scenario, ['arrival', 'replacement', 'same-second'], true)) {
        $pdo->exec('SET timestamp=1794139201');
        $saved = $scenario; $scenario = 'success'; $calls = []; $start = microtime(true);
        eval('namespace CommandsReview; use Throwable; use PDO; use RuntimeException; if(false){'.$block);
        if ((int) statement('SELECT COUNT(*) FROM poller_command')->fetchColumn() !== 0) { throw new \RuntimeException('Retry did not acknowledge retained work'); }
        $scenario = $saved;
    }
    $results[]=['scenario'=>$scenario,'executed'=>$calls,'remaining'=>$left,'success_logs'=>$successLogs,'checks'=>5];

} finally { restore_error_handler(); $pdo->exec('DROP TABLE poller_command'); }
print json_encode(['php'=>PHP_VERSION,'mysql'=>$pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),'results'=>$results],JSON_PRETTY_PRINT) . "\n";
