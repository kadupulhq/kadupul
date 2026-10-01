<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function credential_preference_probe(string $action): array
{
    $root = dirname(__DIR__, 4);
    $program = '';
    foreach (array(
        'lib/auth.php' => array('auth_session_credential_key', 'auth_session_credential_generation', 'auth_rehash_password_preserving_sessions', 'auth_session_bind_credentials', 'auth_session_credentials_valid', 'user_copy'),
        'auth_profile.php' => array('api_auth_clear_user_settings', 'api_auth_clear_user_setting', 'api_auth_update_user_setting'),
        'lib/functions.php' => array('set_user_setting', 'clear_user_setting'),
    ) as $file => $functions) {
        $source = file_get_contents($root . '/' . $file);
        foreach ($functions as $function) {
            $program .= test_php_function_source($source, $function) . "\n";
        }
    }
    $program .= <<<'PHP'
$db = new PDO('sqlite::memory:', options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$db->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, username TEXT, realm INTEGER, enabled TEXT, locked TEXT, password TEXT, full_name TEXT, email_address TEXT, must_change_password TEXT)');
$db->exec('CREATE TABLE settings_user(user_id INTEGER,name TEXT,value TEXT,PRIMARY KEY(user_id,name))');
$db->exec("INSERT INTO user_auth VALUES(42,'alice',0,'on','','old-hash','','',''),(7,'template',0,'on','','new-hash','','','')");
function db_fetch_row_prepared($sql,$params=array()) { $q=$GLOBALS['db']->prepare($sql);$q->execute($params);return $q->fetch(PDO::FETCH_ASSOC) ?: array(); }
function db_fetch_cell_prepared($sql,$params=array()) { $q=$GLOBALS['db']->prepare($sql);$q->execute($params);return $q->fetchColumn(); }
function db_fetch_assoc_prepared($sql,$params=array()) { if(!str_contains($sql,'FROM settings_user'))return array();$q=$GLOBALS['db']->prepare($sql);$q->execute($params);return $q->fetchAll(PDO::FETCH_ASSOC); }
function db_execute_prepared($sql,$params=array()) { if(!str_contains($sql,'settings_user'))return true;$sql=preg_replace('/ON DUPLICATE KEY UPDATE.*$/s','',$sql);$sql=str_replace('INSERT INTO settings_user','REPLACE INTO settings_user',$sql);$q=$GLOBALS['db']->prepare($sql);return $q->execute($params); }
function sql_save($row,$table,...$args) { if($table==='user_auth'){$keys=array_keys($row);$sql='UPDATE user_auth SET '.implode(',',array_map(fn($key)=>$key.'=?',$keys)).' WHERE id=?';$params=array_values($row);$params[]=$row['id'];}else{$sql='REPLACE INTO '.$table.' ('.implode(',',array_keys($row)).') VALUES('.implode(',',array_fill(0,count($row),'?')).')';$params=array_values($row);} $q=$GLOBALS['db']->prepare($sql);$q->execute($params);return $row['id']??true; }
function isset_request_var($name) { return $name==='tab'; }
function get_nfilter_request_var($name) { return 'general'; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function raise_message(...$args) {}
function read_config_option($name) { return ''; }
function is_view_allowed($name) { return true; }
function user_setting_value_allowed(...$args) { return true; }
function db_table_exists($name) { return true; }
function input_validate_input_number($value) {}
function cacti_sizeof($rows) { return count($rows); }
function api_plugin_hook_function($name,$value) { return $value; }
$settings_user=array('general'=>array('auth_credential_generation'=>array('default'=>'forged')));
$_SESSION=array('sess_user_id'=>42);
auth_session_bind_credentials(42);
$rehash=auth_rehash_password_preserving_sessions(42,'old-hash','new-hash',$db);
$db->exec("INSERT INTO settings_user VALUES(42,'page_refresh','60')");
$template_mapping=hash('sha256','new-hash').':'.hash('sha256','template-generation');
$q=$db->prepare("INSERT INTO settings_user VALUES(7,'auth_credential_generation',?),(7,'page_refresh','300')");$q->execute(array($template_mapping));
$original=db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='auth_credential_generation'");
ob_start();
switch($argv[1]) {
 case 'reset': api_auth_clear_user_settings(); break;
 case 'overwrite': user_copy('template','alice',0,0,true); break;
 case 'reset_one': api_auth_clear_user_setting('auth_credential_generation'); break;
 case 'update_one': api_auth_update_user_setting('auth_credential_generation','forged'); break;
 case 'generic_reset': clear_user_setting('auth_credential_generation'); break;
 case 'generic_update': set_user_setting('auth_credential_generation','forged'); break;
}
ob_end_clean();
$mapping=db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='auth_credential_generation'");
$preference=db_fetch_cell_prepared("SELECT value FROM settings_user WHERE user_id=42 AND name='page_refresh'");
print json_encode(array('rehash'=>$rehash,'mapping_preserved'=>$mapping===$original,'valid'=>auth_session_credentials_valid('new-hash'),'preference'=>$preference,'not_template'=>$mapping!==$template_mapping));
PHP;
    $worker = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $program, $action), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($worker))->toBe(0)->and($error)->toBe('');
    return json_decode($output, true);
}

test('preference operations preserve the credential generation of a rehashed session', function (string $action) {
    $result = credential_preference_probe($action);
    expect($result['rehash'])->toBeTrue()->and($result['mapping_preserved'])->toBeTrue()->and($result['valid'])->toBeTrue()->and($result['not_template'])->toBeTrue();
    if ($action === 'reset') {
        expect($result['preference'])->toBeFalse();
    } elseif ($action === 'overwrite') {
        expect($result['preference'])->toBe('300');
    }
})->with(array('reset', 'overwrite', 'reset_one', 'update_one', 'generic_reset', 'generic_update'));
