<?php
declare(strict_types=1);
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,2);
require $root.'/tests/Helpers/PhpSource.php';
require $root.'/include/global_constants.php';
require $root.'/lib/database.php';
foreach (array('db_install_execute','db_install_add_column','db_install_add_key','db_install_drop_key','db_install_add_cache') as $function) {
	eval(test_php_function_source(file_get_contents($root.'/install/functions.php'),$function));
}
require $root.'/lib/automation_runtime.php';
foreach (array('cacti_sizeof','cacti_count','clean_up_lines','cacti_has_control_chars','array_rekey') as $function) {
	eval(test_php_function_source(file_get_contents($root.'/lib/functions.php'),$function));
}
function __($message,...$args) { return $args ? vsprintf($message,$args) : $message; }
function cacti_log(...$args) {}
function cacti_debug_backtrace(...$args) {}
function log_install_always(...$args) {}
function log_install_high(...$args) {}
function log_install_medium(...$args) {}
function log_install_debug(...$args) {}
function read_config_option(...$args) { return ''; }
function set_config_option(...$args) {}
function set_install_config_option($key,$value) { if ($key==='install_cache_db') { $GLOBALS['fixture_cache']=$value; } }
function get_cacti_cli_version() { return db_fetch_cell('SELECT cacti FROM version'); }
function cacti_version_compare($a,$b,$operator) { return version_compare($a,$b,$operator); }
final class UpgradeFailureConnection extends PDO {
	public bool $rejectColumn=false;
	public function prepare(string $query, array $options=array()): PDOStatement|false {
		if ($this->rejectColumn && str_contains($query,'ALTER TABLE `automation_ips` ADD `run_id`')) {
			return parent::prepare('SELECT missing_fixture_column FROM automation_ips',$options);
		}
		return parent::prepare($query,$options);
	}
}
$database_hostname=getenv('BOOST_DB_HOST') ?: '127.0.0.1';
$database_port=getenv('BOOST_DB_PORT') ?: '3306';
$database_default=getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
if (!in_array($database_hostname,array('127.0.0.1','localhost'),true) || !in_array($database_default,array('cacti_boost_contract','cacti_automation_contract'),true)) { throw new RuntimeException('Use an isolated test database.'); }
$pdo=new UpgradeFailureConnection("mysql:host=$database_hostname;port=$database_port;dbname=$database_default;charset=utf8mb4",getenv('BOOST_DB_USER') ?: 'root',getenv('BOOST_DB_PASSWORD') ?: '',array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$pdo->exec("SET SESSION sql_mode='STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
$database_sessions=array("$database_hostname:$database_port:$database_default"=>$pdo);
$config=array('base_path'=>$root,'poller_id'=>1,'connection'=>'online','is_web'=>($argv[1]??'')==='web');
$created=array(); $checks=0;
function upgrade_check($condition,$message) { $GLOBALS['checks']++; if (!$condition) { throw new RuntimeException($message); } }
function run_upgrade() {
	global $root,$cacti_version_codes;
	$source=file_get_contents($root.'/include/global_arrays.php');
	// The real registered version list controls the production Installer loop.
	if (!preg_match('/\$cacti_version_codes = array\((.*?)\);/s',$source,$m)) { throw new RuntimeException('Version registry unavailable'); }
	eval('$cacti_version_codes = array('.$m[1].');');
	require_once $root.'/lib/installer.php';
	$class=new ReflectionClass('Installer'); $installer=$class->newInstanceWithoutConstructor();
	$property=$class->getProperty('old_cacti_version');$property->setAccessible(true);$property->setValue($installer,'1.2.32');
	$method=$class->getMethod('upgradeDatabase');$method->setAccessible(true);
	ob_start();try{return $method->invoke($installer);}finally{ob_end_clean();if(!empty($GLOBALS['fixture_cache']) && is_file($GLOBALS['fixture_cache'])){unlink($GLOBALS['fixture_cache']);}}
}
try {
	$old=file_get_contents(__DIR__.'/automation-1.2.32.sql');
	preg_match_all('/CREATE TABLE `(\w+)` \(.*?;/s',$old,$tables,PREG_SET_ORDER);
	foreach($tables as $table){$pdo->exec($table[0]);$created[]=$table[1];}
	$pdo->exec("CREATE TABLE version (cacti varchar(20) NOT NULL)");$created[]='version';$pdo->exec("INSERT INTO version VALUES ('1.2.32')");
	$pdo->exec('CREATE TABLE poller_output (id int PRIMARY KEY) ENGINE=InnoDB');$created[]='poller_output';
	$pdo->exec("INSERT INTO automation_ips VALUES ('192.0.2.1','retained',1,0,0,0)");
	if (($argv[2]??'')==='failure') {
		$pdo->rejectColumn=true;
		$result=run_upgrade();
		upgrade_check($result!==false,'failed migration must report failure');
		upgrade_check($pdo->query('SELECT cacti FROM version')->fetchColumn()==='1.2.32','failed migration must not advance version');
		$pdo->rejectColumn=false;
	}
	upgrade_check(run_upgrade()===false,'production Installer upgrade succeeds');
	automation_require_schema($pdo);
	upgrade_check($pdo->query('SELECT cacti FROM version')->fetchColumn()==='1.2.33','version advances only after upgrade');
	upgrade_check($pdo->query('SELECT hostname FROM automation_ips')->fetchColumn()==='retained','upgrade preserves existing queue row');
	upgrade_check(run_upgrade()===false,'repeated upgrade is idempotent');
	upgrade_check($pdo->query('SELECT COUNT(*) FROM automation_ips')->fetchColumn()==1,'retry preserves row count');
	$fresh=file_get_contents($root.'/cacti.sql');
	foreach (array('automation_ips','automation_processes','automation_networks') as $table) {
		if (!preg_match('/CREATE TABLE `'.$table.'` \(.*?;/s',$fresh,$match)) { throw new RuntimeException('Fresh automation schema is missing'); }
		$reference=$table.'_fresh';
		$pdo->exec(str_replace('CREATE TABLE `'.$table.'`','CREATE TABLE `'.$reference.'`',$match[0]));
		$created[]=$reference;
		upgrade_check($pdo->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_ASSOC)===$pdo->query('SHOW COLUMNS FROM '.$reference)->fetchAll(PDO::FETCH_ASSOC),'upgraded columns match fresh schema: '.$table);
		$indexes=array();
		foreach (array($table,$reference) as $name) {
			$rows=$pdo->query('SHOW INDEX FROM '.$name)->fetchAll(PDO::FETCH_ASSOC);
			$normalized=array();
			foreach ($rows as $row) {
				$normalized[$row['Key_name'].':'.$row['Seq_in_index']]=array_intersect_key($row,array_flip(array('Non_unique','Column_name','Collation','Sub_part','Null','Index_type')));
			}
			ksort($normalized); $indexes[]=$normalized;
		}
		upgrade_check($indexes[0]===$indexes[1],'upgraded indexes match fresh schema: '.$table);
	}

	$database_fail_fast=true;
	$rejected=false;
	try { db_execute_prepared('UPDATE automation_networks SET missing_fixture_column=1',array(),false); } catch (RuntimeException $expected) { $rejected=true; }
	upgrade_check($rejected,'owned CLI queries throw instead of hiding a rejected write');
	$database_fail_fast=false;
	upgrade_check(db_execute_prepared('UPDATE automation_networks SET missing_fixture_column=1',array(),false)===false,'ordinary callers retain their false-return contract');
	print json_encode(array('checks'=>$checks,'mode'=>$argv[1]??'cli','failure'=>$argv[2]??'none'))."\n";
} finally {
	foreach(array_reverse($created) as $table){$pdo->exec('DROP TABLE '.$table);}
}
