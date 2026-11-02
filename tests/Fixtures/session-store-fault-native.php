<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
$argv[1] = $argv[1] ?? dirname(__DIR__, 2);
require $argv[1].'/lib/session_store.php';
final class PrivateSessionFaultStatement extends PDOStatement {
 protected function __construct(private PrivateSessionFaultPdo $owner) {}
 public function execute(?array $params=null):bool {
  if(str_starts_with($this->queryString,'INSERT INTO sessions')) {
   if($this->owner->fault==='execute_false'){$this->owner->fault='';return false;}
   if($this->owner->fault==='executed_false'){$this->owner->fault='';parent::execute($params);return false;}
   if($this->owner->fault==='readback'){$this->owner->fault='';$this->owner->corruptReadback=true;}
   if($this->owner->fault==='cursor_false'){$this->owner->fault='';$this->owner->failCursor=true;}
  }
  return parent::execute($params);
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
  $row=parent::fetch($mode,$cursorOrientation,$cursorOffset);
  if($this->owner->corruptReadback&&str_starts_with($this->queryString,'SELECT id,data,user_id,access')){$this->owner->corruptReadback=false;if(is_array($row))$row['data']='mismatched';}
  return$row;
 }
 public function closeCursor():bool {
  $result=parent::closeCursor();if($this->owner->failCursor&&str_starts_with($this->queryString,'INSERT INTO sessions')){$this->owner->failCursor=false;return false;}return$result;
 }
}
final class PrivateSessionFaultPdo extends PDO {
 public string $fault='';public bool $corruptReadback=false;public bool $failCursor=false;
 public function exec(string $statement):int|false {
  if(str_starts_with($statement,'SAVEPOINT ')&&in_array($this->fault,['first_savepoint_false','first_savepoint_throw','first_savepoint_ackfalse'],true)){
   $fault=$this->fault;$this->fault='';
   if($fault==='first_savepoint_throw')throw new RuntimeException('Injected initial marker failure');
   if($fault==='first_savepoint_ackfalse')parent::exec($statement);
   return false;
  }
  return parent::exec($statement);
 }
 public function prepare(string $query,array $options=[]):PDOStatement|false {if($this->fault==='prepare'&&str_starts_with($query,'INSERT INTO sessions')){$this->fault='';return false;}return parent::prepare($query,$options);}
 public function commit():bool {if($this->fault==='commit_false'){$this->fault='';return false;}if($this->fault==='commit_throw'){$this->fault='';throw new RuntimeException('Injected commit');}if($this->fault==='committed_false'){$this->fault='';parent::commit();return false;}if($this->fault==='replacement'){$this->fault='';parent::commit();parent::beginTransaction();$this->exec('INSERT INTO caller_work VALUES(9)');return false;}return parent::commit();}
}
$dsn=getenv('KADUPUL_TEST_MYSQL_DSN');$db=new PrivateSessionFaultPdo($dsn,getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PrivateSessionFaultStatement::class,[$db]]);$schema='session_fault_test_'.bin2hex(random_bytes(8));$created=false;$checks=[];$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks[]=$label;};
try{
 $db->exec('CREATE DATABASE `'.$schema.'`');$created=true;$db->exec('USE `'.$schema.'`');$sql=file_get_contents($argv[1].'/cacti.sql');if(preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s',$sql,$match)!==1)throw new RuntimeException('DDL');$ddl=$match[0];$db->exec($ddl);$db->exec('CREATE TABLE caller_work(id int PRIMARY KEY) ENGINE=InnoDB');
 $data='sess_user_id|i:42;sess_last_activity|i:2000;';
 foreach(['first_savepoint_false','first_savepoint_throw','first_savepoint_ackfalse']as$initialFault){
  $store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);
  $check($store->open(),'initial marker setup '.$initialFault);$db->fault=$initialFault;
  $check($store->read('initial-marker')===false,'initial marker failure '.$initialFault);
  $check(!$db->inTransaction()&&!$store->writeConfirmed('initial-marker'),'initial marker owned transaction cleaned '.$initialFault);
  $check($store->read('initial-marker')==='','initial marker retry '.$initialFault);
  $db->beginTransaction();$db->exec('INSERT INTO caller_work VALUES(1)');
  $store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);
  $check($store->open(),'borrowed marker setup '.$initialFault);$db->fault=$initialFault;
  $check($store->read('initial-marker')===false&&$db->inTransaction(),'borrowed marker failure retains caller transaction '.$initialFault);
  $check((int)$db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn()===1,'borrowed marker preserves earlier caller work '.$initialFault);$db->rollBack();
 }

 foreach(['prepare','execute_false','executed_false','readback','cursor_false','commit_false','commit_throw','committed_false','replacement']as$fault){
  $db->exec('DELETE FROM sessions');$store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);$check($store->open()&&$store->read('fault')==='','admitted '.$fault);$db->fault=$fault;$check(!$store->write('fault',$data,'127.0.0.1','private'),'failed '.$fault);$check(!$store->writeConfirmed('fault'),'not confirmed '.$fault);
  $count=(int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();$check($count===(in_array($fault,['committed_false','replacement'],true)?1:0),'persistence '.$fault);
  if($fault==='replacement'){$check($db->inTransaction()&&(int)$db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn()===1,'replacement transaction preserved');$db->rollBack();}else{$check(!$db->inTransaction(),'owned cleanup '.$fault);}
 }
 $db->exec('DELETE FROM sessions');$db->beginTransaction();$db->exec('INSERT INTO caller_work VALUES(1)');$store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);$check($store->open()&&$store->read('borrowed')==='','borrowed admitted');$db->fault='prepare';$check(!$store->write('borrowed',$data,'127.0.0.1','private')&&$db->inTransaction(),'borrowed failure retains transaction');$check((int)$db->query('SELECT COUNT(*) FROM caller_work')->fetchColumn()===1,'borrowed earlier work retained');$db->rollBack();
 $db->exec('ALTER TABLE sessions ENGINE=MyISAM');$store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);$check(!$store->open(),'nontransactional refused');$db->exec('ALTER TABLE sessions ENGINE=InnoDB');
 $db->exec(str_replace('CREATE TABLE `sessions`','CREATE TEMPORARY TABLE `sessions`',$ddl));$store=new CactiSessionStore($db,$schema,'php',static fn():PDO=>$db);$check(!$store->open(),'temporary shadow refused');$db->exec('DROP TEMPORARY TABLE sessions');
 $selected=$db;$store=new CactiSessionStore($db,$schema,'php',static function()use(&$selected):PDO|false{return$selected;});$check($store->open()&&$store->read('target')==='','target admitted');$selected=false;$check(!$store->write('target',$data,'127.0.0.1','private')&&!$store->writeConfirmed('target'),'selected target drift refused');
 define('NATIVE_SESSION_SCENARIO_COMPLETED', true);echo json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{if($db->inTransaction())$db->rollBack();if($created)$db->exec('DROP DATABASE `'.$schema.'`');}
