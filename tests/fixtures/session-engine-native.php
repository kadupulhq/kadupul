<?php
declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
$root=$argv[1] ?? dirname(__DIR__, 2);$dsn=getenv('KADUPUL_TEST_MYSQL_DSN');
$fixtureUser=getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
$fixturePassword=getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
$db=new PDO($dsn,$fixtureUser,$fixturePassword,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$schema='session_engine_test_'.bin2hex(random_bytes(8));$created=false;$process=null;$pipes=[];$checks=0;
try{
 $db->exec('CREATE DATABASE `'.$schema.'`');$created=true;$db->exec('USE `'.$schema.'`');$sql=file_get_contents($root.'/cacti.sql');if(preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s',$sql,$match)!==1)throw new RuntimeException('DDL');$db->exec($match[0]);
 $socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);if(!$socket)throw new RuntimeException('Socket');$address=stream_socket_get_name($socket,false);fclose($socket);
 $environment=getenv();$environment['KADUPUL_TEST_MYSQL_USER']=$fixtureUser;$environment['KADUPUL_TEST_MYSQL_PASSWORD']=$fixturePassword;$environment['PRIVATE_SESSION_SOURCE']=$root;$environment['PRIVATE_SESSION_DSN']=preg_replace('/;dbname=[^;]*/', '', $dsn).';dbname='.$schema;
 $log=tempnam(sys_get_temp_dir(),'native-session-private-');$process=proc_open([PHP_BINARY,'-S',$address,__DIR__ . '/session-engine-endpoint.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,null,$environment);
 if(!is_resource($process))throw new RuntimeException('Server');fclose($pipes[0]);
 $request=static function(string $mode,string $action)use($address):array{$body=file_get_contents('http://'.$address.'/?'.http_build_query(['mode'=>$mode,'action'=>$action]),false,stream_context_create(['http'=>['timeout'=>5,'ignore_errors'=>true]]));$data=json_decode((string)$body,true,flags:JSON_THROW_ON_ERROR);if(!is_array($data))throw new RuntimeException('Response');return $data;};
 $ready=false;for($i=0;$i<50;$i++){usleep(20000);$s=@stream_socket_client('tcp://'.$address,$e,$m,.1);if($s){fclose($s);$ready=true;break;}}if(!$ready)throw new RuntimeException('Readiness');
 foreach(['php','php_binary','php_serialize']as$mode){
  $db->exec('DELETE FROM sessions');
  $a=$request($mode,'login');if($a['row_user']!==42||!$a['confirmed'])throw new RuntimeException('Login');$checks+=2;
  $a=$request($mode,'older');if($a['before_user']!==42||$a['before_activity']!==2000||!$a['confirmed'])throw new RuntimeException('Read/write');$checks+=3;
  $a=$request($mode,'read');if($a['before_activity']!==2000||$a['before_note']!==base64_encode("opaque\0\xff|sess_last_activity|i:8000;"))throw new RuntimeException('Monotonic');$checks+=2;
  $a=$request($mode,'logout');if($a['row_user']!==0||!$a['confirmed'])throw new RuntimeException('Logout');$checks+=2;
  $a=$request($mode,'read');if($a['before_user']!==0)throw new RuntimeException('Logged out');$checks++;
  $a=$request($mode,'destroy');if($a['row_user']!==null)throw new RuntimeException('Destroy');$checks++;
 }
 echo json_encode(['checks'=>$checks,'source'=>$root,'runtime'=>PHP_VERSION,'modes'=>3],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
}finally{if(is_resource($process)){proc_terminate($process);proc_close($process);}if(isset($log)&&is_file($log))unlink($log);if($created)$db->exec('DROP DATABASE `'.$schema.'`');}
