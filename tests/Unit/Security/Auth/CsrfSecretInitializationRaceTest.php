<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('first-use initialization retains a key committed after the initial read', function (string $initial) {
    $root = dirname(__DIR__, 4);
    $source = file_get_contents($root . '/include/csrf.php');
    $program = '';
    foreach (array('cacti_csrf_load_secret', 'cacti_csrf_secret_is_valid', 'cacti_csrf_install_pending') as $function) {
        $program .= test_php_function_source($source, $function) . "\n";
    }
    $program .= <<<'PHP'
$config=array('is_web'=>true);
$_SESSION=array();
$db=new PDO('sqlite::memory:',options:array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT)');
if($argv[1]!==''){$q=$db->prepare('INSERT INTO settings VALUES(?,?)');$q->execute(array('csrf_secret',$argv[1]));}
$winner=str_repeat('ab',32);
function read_config_option($name,$force=false){
 $q=$GLOBALS['db']->prepare('SELECT value FROM settings WHERE name=?');$q->execute(array($name));$value=$q->fetchColumn();
 if(empty($GLOBALS['read'])){
  $GLOBALS['read']=true;
  // Another request commits a valid key after this request reads the old row.
  $q=$GLOBALS['db']->prepare('REPLACE INTO settings VALUES(?,?)');$q->execute(array('csrf_secret',$GLOBALS['winner']));
 }
 return $value===false?'':$value;
}
function db_execute_prepared($sql,$params){$sql=str_replace('INSERT IGNORE','INSERT OR IGNORE',$sql);$q=$GLOBALS['db']->prepare($sql);return $q->execute($params);}
function set_config_option($name,$value){$q=$GLOBALS['db']->prepare('REPLACE INTO settings VALUES(?,?)');$q->execute(array($name,$value));}
$secret=cacti_csrf_load_secret();
print json_encode(array('returned'=>$secret,'stored'=>read_config_option('csrf_secret'),'winner'=>$winner));
PHP;
    $worker = proc_open(array(PHP_BINARY, '-r', $program, $initial), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($worker))->toBe(0)->and($error)->toBe('');
    $result = json_decode($output, true);
    expect($result['returned'])->toBe($result['winner'])->and($result['stored'])->toBe($result['winner']);
})->with(array('missing' => array(''),'invalid existing' => array('short')));
