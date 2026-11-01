<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
$argv[1] = $argv[1] ?? dirname(__DIR__, 2);
require $argv[1] . '/lib/session_store.php';
final class StoreAssessmentMemory implements SessionHandlerInterface {
 public function open(string $p,string $n):bool{return true;} public function close():bool{return true;}
 public function read(string $id):string|false{return '';} public function write(string $id,string $data):bool{return true;}
 public function destroy(string $id):bool{return true;} public function gc(int $max):int|false{return 0;}
}
session_set_save_handler(new StoreAssessmentMemory());session_start();
$encode=static function(array $state):string{$_SESSION=$state;$data=session_encode();if(!is_string($data))throw new RuntimeException('Encode failed.');return $data;};
$base=['sess_user_id'=>42,'sess_user_credential'=>str_repeat('a',64),'sess_user_epoch'=>str_repeat('b',64)];
$dsn=(string)getenv('KADUPUL_TEST_MYSQL_DSN');$user=getenv('KADUPUL_TEST_MYSQL_ADMIN_USER')!==false?getenv('KADUPUL_TEST_MYSQL_ADMIN_USER'):(getenv('KADUPUL_TEST_MYSQL_USER')?:'root');$password=getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD')!==false?getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD'):(getenv('KADUPUL_TEST_MYSQL_PASSWORD')?:'');
$database=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$second=new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='session_store_test_'.bin2hex(random_bytes(8));$created=false;$checks=[];
$check=static function(bool $ok,string $name)use(&$checks):void{if(!$ok)throw new RuntimeException($name);$checks[]=$name;};
try{
 $database->exec('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4');$created=true;
 foreach([$database,$second]as$p){$p->exec('USE `'.$schema.'`');$p->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");}
 $ddl=file_get_contents($argv[1].'/cacti.sql');if(preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s',$ddl,$match)!==1)throw new RuntimeException('Missing shipped sessions table.');$database->exec($match[0]);
 $database->exec('CREATE TABLE caller_work (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB');
 $now=1000;$store=new CactiSessionStore($database,$schema,'php',static fn():PDO=>$database,static function()use(&$now):int{return $now;});
 $late=new CactiSessionStore($second,$schema,'php',static fn():PDO=>$second,static fn():int=>2000);
 $check($store->open()&&$store->read('owned')==='','new read');
 $old=$encode($base+['note'=>"y\0\xff;sess_last_activity|i:8000;",'sess_last_activity'=>1000]);
 $check($store->write('owned',$old,'127.0.0.1','fixture')&&$store->writeConfirmed('owned'),'fresh authentication');
 $check($store->read('owned')===$old,'existing read');
 $check($late->open()&&$late->read('owned')===$old,'parallel snapshot');
 $new=$encode($base+['note'=>"x\0\xff;sess_last_activity|i:9000;",'sess_last_activity'=>2000]);
 $check($late->write('owned',$new,'127.0.0.1','fixture'),'newer activity write');
 $check($store->write('owned',$old,'127.0.0.1','fixture'),'late activity write');
 $stored=$database->query("SELECT data FROM sessions WHERE id='owned'")->fetchColumn();$expected=$encode($base+['note'=>"y\0\xff;sess_last_activity|i:8000;",'sess_last_activity'=>2000]);
 $check($stored===$expected,'exact timestamp merge preserves note');
 $check((string)$database->query("SELECT access FROM sessions WHERE id='owned'")->fetchColumn()==='2000','access monotonic');
 $check($store->read('owned')===$expected&&$late->read('owned')===$expected,'logout snapshots');
 $anonymous=$encode(['theme'=>'modern']);$_SESSION=$base;
 $check($late->write('owned',$anonymous,'127.0.0.1','fixture'),'logout update');
 $check(!$store->write('owned',$old,'127.0.0.1','fixture')&&!$store->writeConfirmed('owned'),'late login cannot replace logout');
 $check($database->query("SELECT data FROM sessions WHERE id='owned'")->fetchColumn()===$anonymous,'logout bytes retained');
 $check((int)$database->query("SELECT user_id FROM sessions WHERE id='owned'")->fetchColumn()===0,'logout metadata');
 $check($store->read('new-login')===''&&$store->write('new-login',$old,'127.0.0.1','fixture'),'new login id');
 $check($late->read('new-login')===$old,'destroy snapshot');
 $check($store->destroy('new-login'),'destroy confirmed');
 $check(!$late->write('new-login',$old,'127.0.0.1','fixture'),'destroyed session cannot be reinserted');
 $check((int)$database->query("SELECT COUNT(*) FROM sessions WHERE id='new-login'")->fetchColumn()===0,'destroy stays absent');
 $check($store->read('borrowed')===''&&$store->write('borrowed',$old,'127.0.0.1','fixture'),'borrowed seed');
 $database->beginTransaction();$database->exec('INSERT INTO caller_work VALUES (1)');
 $check($store->read('borrowed')===$old,'borrowed read');
 $check($store->write('borrowed',$new,'127.0.0.1','fixture')&&!$store->writeConfirmed('borrowed'),'borrowed is pending');
 $check($database->inTransaction()&&(int)$database->query('SELECT COUNT(*) FROM caller_work')->fetchColumn()===1,'caller work preserved');
 $check($second->query("SELECT data FROM sessions WHERE id='borrowed'")->fetchColumn()===$old,'borrowed not committed');
 $database->rollBack();$check($database->query("SELECT data FROM sessions WHERE id='borrowed'")->fetchColumn()===$old,'caller rollback retains prior blob');
 $check($store->read('anonymous')===''&&$store->write('anonymous',$anonymous,'127.0.0.1','fixture'),'anonymous acknowledgement');
 $check((int)$database->query("SELECT COUNT(*) FROM sessions WHERE id='anonymous'")->fetchColumn()===0,'anonymous no row');
 $database_hostname='session-fixture';$database_port=1;$database_default=$schema;$database_sessions=['session-fixture:1:'.$schema=>$database];
 $config=['base_path'=>$argv[1], 'library_path'=>$argv[1].'/lib', 'include_path'=>$argv[1].'/include', 'proxy_headers'=>[], 'is_web'=>false, 'config_options_array'=>['log_verbosity'=>0,'selective_debug'=>'']];
 $database_total_queries=0;$_SERVER['REMOTE_ADDR']='127.0.0.1';
 require $argv[1].'/include/global_constants.php';
 require $argv[1].'/lib/functions.php';
 $previousHandler=session_module_name();
 (static function () use ($argv): void {
  $database_hostname='unrelated-local';$database_port=999;$database_default='unrelated-local';$database_sessions=[];
  require $argv[1].'/include/session.php';
 })();
 $check(session_module_name()===$previousHandler&&session_status()===PHP_SESSION_ACTIVE,'CLI include preserves the existing handler and active session');
 $check(cacti_db_session_open()&&cacti_db_session_read('facade')==='','actual facade open/read');
 $check(cacti_db_session_write('facade',$old),'actual facade write');
 $selection=cacti_db_session_context();$key='session-fixture:1:'.$schema;
 $check(cacti_db_session_read('facade')===$old&&is_array($selection)&&$selection['database']===$database&&$selection['schema']===$schema&&$selection['key']===$key,'facade captures the real selected installation');
 $database_sessions[$key]=$second;
 $check(cacti_db_session_context()['database']===$second&&!cacti_db_session_write('facade',$new),'captured PDO replacement refuses the old backend');
 $database_sessions[$key]=$database;$database_hostname='other';$database_sessions['other:1:'.$schema]=$database;
 $check(cacti_db_session_context()['database']===$database&&!cacti_db_session_write('facade',$new),'same PDO under a changed installation key remains refused');
 $database_hostname='session-fixture';unset($database_sessions['other:1:'.$schema]);$database_default='missing-context';
 $check(cacti_db_session_context()===false,'captured schema change is not another installation');
 $database_default=$schema;$database_port=[];
 $check(cacti_db_session_context()===false,'malformed captured context remains unavailable');
 $database_port=1;$database_sessions=[];
 $check(cacti_db_session_context()===false,'captured pool removal remains unavailable');
 $database_sessions=[$key=>$database];
 $check(cacti_db_session_write('facade',$old)&&cacti_db_session_write_confirmed('facade'),'restored original selection retries successfully');

 $check(cacti_db_session_close(),'actual facade close');
 $questions=static function()use($database):int{$row=$database->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch(PDO::FETCH_NUM);return (int)$row[1];};
 $before=$questions();$confirmed=cacti_db_session_write_confirmed('facade');$after=$questions();
 $check($confirmed&&$after===$before+1,'explicit callback confirmation has no I/O');
 $database->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_WARNING);
 $check(cacti_db_session_open()&&cacti_db_session_read('CASED')==='','case seed read');
 $check(cacti_db_session_write('CASED',$old)&&cacti_db_session_close(),'case seed write');
 $check(cacti_db_session_open()&&cacti_db_session_read('cased')==='','cookie identity is byte exact');
 $warnings=[];$previous=set_error_handler(static function(int $type,string $message)use(&$warnings):bool{$warnings[]=$type;return true;});
 try{$caseWrite=cacti_db_session_write('cased',$old);}finally{restore_error_handler();}
 $check(!$caseWrite&&$warnings===[]&&!cacti_db_session_write_confirmed('cased'),'collision failure does not leak PDO warning');
 $check($database->getAttribute(PDO::ATTR_ERRMODE)===PDO::ERRMODE_WARNING,'caller PDO mode restored');
 cacti_db_session_close();
 $check(cacti_db_session_open()&&cacti_db_session_read('metadata')==='', 'metadata admitted');
 $backend=cacti_db_session_backend();
 $check($backend!==false&&$backend->write('metadata',$old,'2001:0db8:0000:0000:0000:0000:0000:1234',str_repeat('é',129)), 'bounded Unicode display metadata');
 $display=$database->query("SELECT remote_addr,user_agent FROM sessions WHERE id='metadata'")->fetch(PDO::FETCH_ASSOC);
 $check(is_array($display)&&$display['remote_addr']===substr('2001:0db8:0000:0000:0000:0000:0000:1234',0,25)&&$display['user_agent']===str_repeat('é',128), 'metadata fits actual columns');
 $check($backend!==false&&!$backend->write('metadata',$old,'127.0.0.1',"bad\xff")&&!$backend->writeConfirmed('metadata'), 'invalid metadata remains unconfirmed');
 cacti_db_session_close();
 $previousMode=$database->query('SELECT @@SESSION.sql_mode')->fetchColumn();
 $database->exec("SET NAMES utf8");
 $database->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION,ALLOW_INVALID_DATES'");
 $check($store->open()&&$store->read('charset')==='', 'legacy connection mode admitted');
 $check(!$store->write('charset',$old,'127.0.0.1','😀')&&!$store->writeConfirmed('charset'), 'legacy charset coercion remains unconfirmed');
 $check((int)$database->query("SELECT COUNT(*) FROM sessions WHERE id='charset'")->fetchColumn()===0, 'legacy charset failure preserves absent row');
 $database->exec('SET NAMES utf8mb4');
 $restoreMode=$database->prepare('SET SESSION sql_mode=?');$restoreMode->execute([$previousMode]);
 $database->exec("CREATE TRIGGER session_agent_coercion BEFORE INSERT ON sessions FOR EACH ROW SET NEW.user_agent='coerced'");
 $check($store->open()&&$store->read('coercion')==='', 'coercion trigger admitted');
 $check(!$store->write('coercion',$old,'127.0.0.1','expected')&&!$store->writeConfirmed('coercion'), 'metadata readback mismatch refuses success');
 $check((int)$database->query("SELECT COUNT(*) FROM sessions WHERE id='coercion'")->fetchColumn()===0, 'metadata readback mismatch rolls back');
 $database->exec('DROP TRIGGER session_agent_coercion');
 $check($store->open()&&$store->read('large')==='', 'large cache admitted');
 $large=$encode($base+['cache'=>str_repeat('x',8*1024*1024),'sess_last_activity'=>3000]);
 $check($store->write('large',$large,'127.0.0.1','fixture')&&$store->writeConfirmed('large'), 'large cache confirmed');
 $check($store->read('large')===$large, 'large cache exact native bytes');
 $check(memory_get_peak_usage(true)<128*1024*1024, 'large cache within worker memory');
 unset($large);
 $check($store->read('oversized')==='', 'oversized snapshot admitted');
 $oversized=serialize(['sess_user_id'=>42,'cache'=>str_repeat('x',16777215)]);
 $before=$questions();$refused=!$store->write('oversized',$oversized,'127.0.0.1','fixture');$after=$questions();
 $check($refused&&$after===$before+1, 'MEDIUMBLOB limit refuses before database I/O');
 $check(!$store->writeConfirmed('oversized')&&(int)$database->query("SELECT COUNT(*) FROM sessions WHERE id='oversized'")->fetchColumn()===0, 'oversized data never confirms or persists');
 unset($oversized);
 session_abort();define('NATIVE_SESSION_SCENARIO_COMPLETED', true);echo json_encode(['checks'=>count($checks),'passed'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{foreach([$database,$second]as$p)if($p->inTransaction())$p->rollBack();if($created)$database->exec('DROP DATABASE `'.$schema.'`');}
