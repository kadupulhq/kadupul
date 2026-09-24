<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace RemoteConnectRetriesTest;

$root = dirname(__DIR__, 4);

/**
 * Run one real db_connect_real() call out of include/global.php and report the
 * arguments it passed, with a stub standing in for the driver. The statements
 * are taken from the file, so a regression in the argument list fails here.
 *
 * @param string $needle Text identifying the statement to run.
 * @param array  $vars   The configuration variables in scope, as config.php sets them.
 * @param string $file   The file holding the statement.
 * @param string $after  Text the statement must follow, for a file with several.
 *
 * @return array<int, mixed> The positional arguments the statement passed.
 */
function connect_arguments($needle, array $vars, $file = 'include/global.php', $after = '')
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);
    expect($source)->not->toBeFalse();

    $from = 0;

    if ($after !== '') {
        $from = strpos($source, $after);
        expect($from)->not->toBeFalse();
    }

    $start = strpos($source, $needle, $from);
    expect($start)->not->toBeFalse();

    $end = strpos($source, ';', $start);
    expect($end)->not->toBeFalse();

    $statement = substr($source, $start, $end - $start + 1);

    $assignments = '';
    foreach ($vars as $name => $value) {
        $assignments .= '$' . $name . ' = ' . var_export($value, true) . ';';
    }

    $code = 'function db_connect_real() { echo json_encode(func_get_args()); exit(0); }'
        . $assignments . $statement;

    $pipes   = array();
    $process = proc_open(
        array(PHP_BINARY, '-r', $code),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes
    );
    expect($process)->not->toBeFalse();

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($err)->toBe('');

    $arguments = json_decode($out, true);
    expect($arguments)->toBeArray($out);

    return $arguments;
}

/* Deliberately different, so a call reading the wrong one is visible. */
$vars = array(
    'database_hostname'  => 'local.invalid',
    'database_username'  => 'localuser',
    'database_password'  => 'localpass',
    'database_default'   => 'cacti',
    'database_type'      => 'mysql',
    'database_port'      => '3306',
    'database_retries'   => 5,
    'database_ssl'       => false,
    'database_ssl_key'   => '',
    'database_ssl_cert'  => '',
    'database_ssl_ca'    => '',
    'rdatabase_hostname' => 'main.invalid',
    'rdatabase_username' => 'remoteuser',
    'rdatabase_password' => 'remotepass',
    'rdatabase_default'  => 'cacti',
    'rdatabase_type'     => 'mysql',
    'rdatabase_port'     => '3307',
    'rdatabase_retries'  => 1,
    'rdatabase_ssl'      => false,
    'rdatabase_ssl_key'  => '',
    'rdatabase_ssl_cert' => '',
    'rdatabase_ssl_ca'   => '',
);

/* Argument seven of db_connect_real() is the retry count. */
const RETRIES = 6;

test('the remote poller reaches the main server with the remote retry count', function () use ($vars) {
    $arguments = connect_arguments('$remote_db_cnn_id = db_connect_real(', $vars);

    expect($arguments[0])->toBe('main.invalid')
        ->and($arguments[RETRIES])->toBe(1);
});

test('the local connection still uses the local retry count', function () use ($vars) {
    $arguments = connect_arguments('$local_db_cnn_id = db_connect_real(', $vars);

    expect($arguments[0])->toBe('local.invalid')
        ->and($arguments[RETRIES])->toBe(5);
});

/*
 * Every other argument of the remote call already came from its rdatabase_
 * counterpart; the retry count was the one that did not. Check the whole list
 * so the next mismatch is caught wherever it appears.
 */
test('no argument of the remote call is taken from the local settings', function () use ($vars) {
    $arguments = connect_arguments('$remote_db_cnn_id = db_connect_real(', $vars);
    $expected  = array();

    foreach (array('hostname', 'username', 'password', 'default', 'type', 'port', 'retries',
        'ssl', 'ssl_key', 'ssl_cert', 'ssl_ca') as $field) {
        $expected[] = $vars['rdatabase_' . $field];
    }

    expect($arguments)->toBe($expected);
});

/*
 * The installer's own remote connect has always passed the remote value, which
 * is the precedent the fix follows.
 */
test('the installer already passed the remote retry count', function () use ($vars) {
    // Run the installer's own call, rather than counting a variable name that
    // also appears in the global declaration above it.
    // install/functions.php has three of these; the local one comes first.
    $arguments = connect_arguments(
        '$connection = db_connect_real(',
        $vars,
        'install/functions.php',
        'function install_test_remote_database_connection()'
    );

    expect($arguments[0])->toBe('main.invalid')
        ->and($arguments[RETRIES])->toBe(1);
});
