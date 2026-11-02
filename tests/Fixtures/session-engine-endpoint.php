<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
$root=getenv('PRIVATE_SESSION_SOURCE');
$database=new PDO(getenv('PRIVATE_SESSION_DSN'),getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root'),getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: ''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$database_hostname='native-fixture';$database_port=1;$database_default=$database->query('SELECT DATABASE()')->fetchColumn();$database_sessions=['native-fixture:1:'.$database_default=>$database];
$config=['base_path'=>$root,'library_path'=>$root.'/lib','include_path'=>$root.'/include','proxy_headers'=>[],'is_web'=>false,'config_options_array'=>['log_verbosity'=>0,'selective_debug'=>'']];$database_total_queries=0;
require $root.'/include/global_constants.php';require $root.'/lib/functions.php';
$mode=$_GET['mode']??'php';if(!in_array($mode,['php','php_binary','php_serialize'],true))throw new RuntimeException('Mode');
ini_set('session.serialize_handler',$mode);ini_set('session.use_cookies','0');ini_set('session.cache_limiter','');
set_error_handler(static function(int $level,string $message):never{throw new RuntimeException('Native session warning '.$level);});
require $root.'/include/session.php';
session_id('native-'.str_replace('_','-',$mode));if(!session_start())throw new RuntimeException('Start');
$before=$_SESSION;$action=$_GET['action']??'read';
if($action==='login')$_SESSION=['sess_user_id'=>42,'sess_user_credential'=>str_repeat('a',64),'sess_user_epoch'=>str_repeat('b',64),'sess_last_activity'=>2000,'note'=>"opaque\0\xff|sess_last_activity|i:8000;"];
elseif($action==='logout')unset($_SESSION['sess_user_id'],$_SESSION['sess_user_credential'],$_SESSION['sess_user_epoch']);
elseif($action==='destroy'){if(!session_destroy())throw new RuntimeException('Destroy');}
elseif($action==='older')$_SESSION['sess_last_activity']=1000;
elseif($action!=='read')throw new RuntimeException('Action');
$id=session_id();if(session_status()===PHP_SESSION_ACTIVE&&!session_write_close())throw new RuntimeException('Close');
$row=$database->query('SELECT user_id,data FROM sessions')->fetch(PDO::FETCH_ASSOC);
restore_error_handler();header('Content-Type: application/json');define('NATIVE_SESSION_SCENARIO_COMPLETED', true);echo json_encode(['before_user'=>$before['sess_user_id']??0,'before_activity'=>$before['sess_last_activity']??null,'before_note'=>isset($before['note'])?base64_encode($before['note']):null,'confirmed'=>cacti_db_session_write_confirmed($id),'row_user'=>$row===false?null:(int)$row['user_id']],JSON_THROW_ON_ERROR);
