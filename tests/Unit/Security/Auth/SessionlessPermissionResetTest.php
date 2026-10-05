<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('permission invalidation works without an authenticated operator session', function (array $session) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/auth.php');
    $program = test_php_function_source($source, 'reset_user_perms');
    $program .= <<<'PHP'
$_SESSION=json_decode($argv[1],true);
$writes=array();
function db_execute_prepared($sql,$params){$GLOBALS['writes'][]=$params;return true;}
function kill_session_var($name){unset($_SESSION[$name]);}
reset_user_perms(42);
print json_encode(array('writes'=>$writes,'session'=>$_SESSION));
PHP;
    $worker = proc_open(array(PHP_BINARY,'-d','display_errors=stderr','-r',$program,json_encode($session)), array(1 => array('pipe','w'),2 => array('pipe','w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($worker))->toBe(0)->and($error)->toBe('');
    $result = json_decode($output, true);
    expect($result['writes'])->toBe(array(array(42)));
    if (($session['sess_user_id'] ?? null) === 42) {
        expect($result['session'])->not->toHaveKey('sess_user_realms');
    } else {
        expect($result['session'])->toBe($session);
    }
})->with(array('CLI/provisioning' => array(array()),'same user' => array(array('sess_user_id' => 42,'sess_user_realms' => array(1))),'different operator' => array(array('sess_user_id' => 7,'sess_user_realms' => array(1)))));
