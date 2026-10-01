<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/ChildProcessCoverage.php';

test('first-use initialization retains a key committed after the initial read', function (string $initial) {
    $root = dirname(__DIR__, 4);
    $program = <<<'PHP'
$config=array('is_web'=>true,'include_path'=>$argv[1].'/include','base_path'=>$argv[1],'url_path'=>'/');
$_SESSION=array();
$_SERVER['REQUEST_METHOD']='GET';
$file=tempnam(sys_get_temp_dir(),'csrf-init-');
$db=new PDO('sqlite:'.$file,options:array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$racer=new PDO('sqlite:'.$file,options:array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT)');
if($argv[2]!==''){$q=$db->prepare('INSERT INTO settings VALUES(?,?)');$q->execute(array('csrf_secret',$argv[2]));}
$winner=str_repeat('ab',32);
function read_config_option($name,$force=false){
 $q=$GLOBALS['db']->prepare('SELECT value FROM settings WHERE name=?');$q->execute(array($name));$value=$q->fetchColumn();
 $q->closeCursor();
 if(empty($GLOBALS['read'])){
  $GLOBALS['read']=true;
  // Another request commits a valid key after this request reads the old row.
  $q=$GLOBALS['racer']->prepare('REPLACE INTO settings VALUES(?,?)');$q->execute(array('csrf_secret',$GLOBALS['winner']));
 }
 return $value===false?'':$value;
}
function db_execute_prepared($sql,$params){$sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);$q=$GLOBALS['db']->prepare($sql);return $q->execute($params);}
function set_config_option($name,$value){$q=$GLOBALS['db']->prepare('REPLACE INTO settings VALUES(?,?)');$q->execute(array($name,$value));}
function cacti_log(...$args){}
register_shutdown_function(function()use($file){
 while(ob_get_level()>0){ob_end_clean();}
 print json_encode(array('returned'=>$GLOBALS['csrf']['secret'],'stored'=>read_config_option('csrf_secret'),'winner'=>$GLOBALS['winner']));
 unlink($file);
});
require $argv[1].'/include/csrf.php';
PHP;
    $worker = proc_open(child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $root, $initial), $coverage_dir), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($worker))->toBe(0)->and($error)->toBe('');
    child_coverage_collect($coverage_dir);
    $result = json_decode($output, true);
    expect($result['returned'])->toBe($result['winner'])->and($result['stored'])->toBe($result['winner']);
})->with(array('missing' => array(''),'invalid existing' => array('short')));
