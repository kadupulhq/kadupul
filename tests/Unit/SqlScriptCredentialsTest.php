<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The MySQL polling scripts must not put the database user or password in
 * mysqladmin's arguments, which other local accounts can read from the
 * process list. They pass a private option file instead and remove it when
 * the client exits, and their poller output must not change. Each case runs
 * the shipped script against a stand-in mysqladmin found through PATH.
 * Upstream Cacti #8545 and #8450.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

const SQL_SCRIPT_STATUS = 'Uptime: 10  Threads: 1  Questions: 9  Slow queries: 0  Opens: 5  Flush tables: 1  Open tables: 4  Queries per second avg: 0.900';

function sql_script_run(string $script, string $password, string $mode = 'ok'): array
{
    $root = dirname(__DIR__, 2);
    $work = sys_get_temp_dir() . '/kadupul-sqlscript-' . bin2hex(random_bytes(6));
    mkdir($work . '/scripts', 0700, true);
    mkdir($work . '/include', 0700);
    mkdir($work . '/bin', 0700);
    $work = realpath($work);

    $functions = file_get_contents($root . '/lib/functions.php');
    $bootstrap = '<?php' . "\n"
        . 'function cacti_log(...$arguments) {}' . "\n"
        . test_php_function_source($functions, 'cacti_exec') . "\n"
        . test_php_function_source($functions, 'cacti_exec_string') . "\n"
        . 'require_once ' . var_export($root . '/lib/database.php', true) . ';' . "\n"
        . '$database_hostname = "db.example.test";' . "\n"
        . '$database_username = "poller";' . "\n"
        . '$database_password = base64_decode(getenv("SQL_SCRIPT_PASSWORD"), true);' . "\n";
    file_put_contents($work . '/include/cli_check.php', $bootstrap);

    // sql.php includes ../include/cli_check.php beside itself, so it runs as a
    // byte-identical copy; ss_sql.php runs in place in script server mode.
    copy($root . '/scripts/sql.php', $work . '/scripts/sql.php');
    expect(hash_file('sha256', $work . '/scripts/sql.php'))->toBe(hash_file('sha256', $root . '/scripts/sql.php'));

    $client = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
$call = array('argv' => array_slice($argv, 1));
if (isset($argv[1]) && str_starts_with($argv[1], '--defaults-extra-file=')) {
    $path = substr($argv[1], strlen('--defaults-extra-file='));
    $call += array('path' => $path, 'mode' => fileperms($path) & 0777, 'contents' => file_get_contents($path));
}
file_put_contents(getenv('SQL_SCRIPT_LOG'), json_encode($call) . "\n", FILE_APPEND);
if (getenv('SQL_SCRIPT_MODE') === 'fail') {
    exit(1);
}
print getenv('SQL_SCRIPT_STATUS') . "\n";
PHP;
    file_put_contents($work . '/bin/mysqladmin', $client);
    chmod($work . '/bin/mysqladmin', 0700);

    if ($script === 'sql.php') {
        $code = 'require ' . var_export($work . '/scripts/sql.php', true) . ';';
    } else {
        $code = 'require ' . var_export($work . '/include/cli_check.php', true) . ';'
            . '$called_by_script_server = true;'
            . 'require ' . var_export($root . '/scripts/ss_sql.php', true) . ';'
            . 'print ss_sql();';
    }

    $environment = getenv();
    $environment['PATH'] = $work . '/bin' . PATH_SEPARATOR . ($environment['PATH'] ?? '');
    $environment['TMPDIR'] = $work;
    $environment['SQL_SCRIPT_PASSWORD'] = base64_encode($password);
    $environment['SQL_SCRIPT_LOG'] = $work . '/calls.jsonl';
    $environment['SQL_SCRIPT_MODE'] = $mode;
    $environment['SQL_SCRIPT_STATUS'] = SQL_SCRIPT_STATUS;

    try {
        $pipes = array();
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $work, $environment);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $calls = array();
        if (is_file($work . '/calls.jsonl')) {
            foreach (file($work . '/calls.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
                $calls[] = json_decode($line, true);
            }
        }

        // Only the stand-in client, its log and the bootstrap may remain.
        $left = array_values(array_diff(scandir($work), array('.', '..', 'scripts', 'include', 'bin', 'calls.jsonl')));
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($work);
    }

    expect($stderr)->toBe('')->and($status)->toBe(0);

    return array('output' => $stdout, 'calls' => $calls, 'left' => $left);
}

function sql_script_expected(string $script): string
{
    return $script === 'sql.php' ? '9' : 'Uptime:10 Threads:1 Questions:9 SlowQueries:0 Opens:5 FlushTables:1 OpenTables:4 QPS:0.900';
}

test('mysqladmin receives the credentials in a private option file, not its arguments', function (string $script, string $password) {
    $result = sql_script_run($script, $password);

    expect($result['output'])->toBe(sql_script_expected($script))
        ->and($result['calls'])->toHaveCount(1);

    $call = $result['calls'][0];
    $quoted = '"' . addcslashes($password, '\\"') . '"';

    expect($call['argv'])->toBe(array('--defaults-extra-file=' . $call['path'], '--host=db.example.test', 'status'))
        ->and($call['mode'])->toBe(0600)
        ->and($call['contents'])->toBe("[client]\nuser=\"poller\"\n" . ($password === '' ? '' : 'password=' . $quoted . "\n"))
        ->and($result['left'])->toBe(array());

    foreach ($call['argv'] as $argument) {
        expect($argument)->not->toContain('poller');
        if ($password !== '') {
            expect($argument)->not->toContain($password);
        }
    }
})->with(array('sql.php', 'ss_sql.php'))->with(array(
    'no password' => '',
    'quotes, backslashes, comment and spaces' => "p\"q\\x #y z'w caf\u{e9}",
));

test('a password that cannot be written to an option file returns U without starting mysqladmin', function (string $script, string $password) {
    $result = sql_script_run($script, $password);

    expect($result['output'])->toBe('U')
        ->and($result['calls'])->toBe(array())
        ->and($result['left'])->toBe(array());
})->with(array('sql.php', 'ss_sql.php'))->with(array(
    'line feed' => "line\nbreak",
    'carriage return' => "line\rbreak",
    'NUL' => "nul\0byte",
));

test('a failed mysqladmin returns U and still removes the option file', function (string $script) {
    $result = sql_script_run($script, 'secret', 'fail');

    expect($result['output'])->toBe('U')
        ->and($result['calls'])->toHaveCount(1)
        ->and($result['left'])->toBe(array());
})->with(array('sql.php', 'ss_sql.php'));
