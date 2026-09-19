<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = dirname(__DIR__, 4);

$runPing = function (array $request) use ($root) {
    $program = <<<'PHP'
namespace PollersDatabasePingRuntime;

$GLOBALS['request'] = json_decode($argv[1], true);

$source = file_get_contents(getcwd() . '/pollers.php');

$code = array();
foreach (array('test_database_connection', 'pollers_valid_db_endpoint') as $name) {
    if (preg_match('/^function ' . $name . '\(.*?^}\n/ms', $source, $match)) {
        $code[] = $match[0];
    } elseif ($name === 'test_database_connection') {
        exit(2);
    }
}

function get_nfilter_request_var($name) { return $GLOBALS['request'][$name]; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function __($text) { return $text; }
function db_close($connection) {}
function db_connect_real($host, $user, $pass, $name, $type, $port, $retries) {
    echo 'CONNECT:' . $host . ':' . $port . ':' . $retries . "\n";
    return false;
}

eval('namespace PollersDatabasePingRuntime; ' . implode("\n", $code));

test_database_connection();
PHP;

    $process = proc_open(
        array(PHP_BINARY, '-r', $program, json_encode($request)),
        array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
        $pipes,
        $root
    );

    expect(is_resource($process))->toBeTrue();

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return array(proc_close($process), $stdout, $stderr);
};

$request = function (array $overrides) {
    return array_merge(array(
        'dbhost'    => 'db2.example.com',
        'dbuser'    => 'cactiuser',
        'dbpass'    => 'secret',
        'dbdefault' => 'cacti',
        'dbport'    => '3306',
        'dbretries' => '2',
        'dbssl'     => '',
        'dbsslkey'  => '',
        'dbsslcert' => '',
        'dbsslca'   => '',
    ), $overrides);
};

test('the connection test refuses hosts that are not a bare name or address', function () use ($runPing, $request) {
    $hosts = array(
        'mysql://db2.example.com',
        'db2.example.com/cacti',
        '/var/run/mysqld/mysqld.sock',
        'user@db2.example.com',
        'db2.example.com;port=22',
        '',
        str_repeat('a', 60) . '.' . str_repeat('b', 60),
        '[2001:db8::10]',
        'db_primary:3306',
        "db_primary\n",
        'db2..example.com',
        '.db2.example.com',
        '-db2.example.com',
        'db2-.example.com',
        str_repeat('a', 64) . '.example.com',
    );

    foreach ($hosts as $host) {
        list($exit, $stdout, $stderr) = $runPing($request(array('dbhost' => $host)));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->not->toContain('CONNECT:')
            ->and($stdout)->toContain('Invalid Database Hostname or Port');
    }
});

test('the connection test refuses ports outside the TCP range', function () use ($runPing, $request) {
    foreach (array('0', '65536', '99999', '-1', '33o6', '') as $port) {
        list($exit, $stdout, $stderr) = $runPing($request(array('dbport' => $port)));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->not->toContain('CONNECT:');
    }
});

test('the connection test caps the number of retries', function () use ($runPing, $request) {
    $cases = array('100000' => '3', '3' => '3', '1' => '1', '' => '0', '-4' => '0');

    foreach ($cases as $retries => $expected) {
        list($exit, $stdout, $stderr) = $runPing($request(array('dbretries' => (string) $retries)));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain('CONNECT:db2.example.com:3306:' . $expected . "\n");
    }
});

test('the connection test still reaches names and addresses', function () use ($runPing, $request) {
    foreach (array('db2.example.com', 'db2', '192.0.2.10', '2001:db8::10', 'db_primary', 'project_db_1', 'db2.example.com.') as $host) {
        list($exit, $stdout, $stderr) = $runPing($request(array('dbhost' => $host)));

        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain('CONNECT:' . $host . ':3306:2')
            ->and($stdout)->toContain('Connection Failed');
    }
});

test('the connection test does not raise the 900 second execution limit', function () use ($root) {
    $source = file_get_contents($root . '/pollers.php');

    expect(preg_match('/^\/\* performing a full sync.*?^}\n/ms', $source, $prelude))->toBe(1);

    foreach (array('ping' => false, 'actions' => true, '' => true) as $action => $raised) {
        $program = 'namespace PollersPreludeRuntime;'
            . ' function get_nfilter_request_var($name) { return $GLOBALS["argv"][1]; }'
            . ' function ini_set($name, $value) { echo $name . "=" . $value . "\n"; }'
            . ' ' . $prelude[0];

        $process = proc_open(
            array(PHP_BINARY, '-r', $program, $action),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes,
            $root
        );

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        expect(proc_close($process))->toBe(0, $stderr);

        if ($raised) {
            expect($stdout)->toContain('max_execution_time=900');
        } else {
            expect($stdout)->not->toContain('max_execution_time=900');
        }
    }
});
