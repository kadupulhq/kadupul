<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('recovery metadata does not require remote database connections', function (string $flag, int $expected, string $message) {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/recovery-metadata-' . bin2hex(random_bytes(8));
    foreach (array('', '/include', '/lib') as $suffix) {
        mkdir($directory . $suffix, 0700);
    }
    try {
        copy($root . '/poller_recovery.php', $directory . '/poller_recovery.php');
        foreach (array('poller', 'boost', 'dsstats') as $library) {
            file_put_contents($directory . '/lib/' . $library . '.php', '<?php');
        }
        file_put_contents($directory . '/include/cli_check.php', '<?php
$config = array("base_path" => dirname(__DIR__), "poller_id" => 1);
define("COPYRIGHT_YEARS", "2004-2026");
function get_cacti_version() { return "fixture"; }
function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
function cacti_log($message, ...$args) { print $message; }
function db_fetch_cell(...$args) { throw new RuntimeException("Unexpected database lookup"); }
');
        $arguments = array(PHP_BINARY, $directory . '/poller_recovery.php');
        if ($flag !== '') {
            $arguments[] = $flag;
        }
        $process = proc_open($arguments, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        expect($exit)->toBe($expected)->and($error)->toBe('')->and($output)->toContain($message);
        if ($expected === 0) {
            expect($output)->not->toContain('Database connection unavailable');
        }
    } finally {
        foreach (array('/include', '/lib', '') as $suffix) {
            foreach (glob($directory . $suffix . '/*') as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory . $suffix);
        }
    }
})->with(array(
    array('--version', 0, 'Version fixture'),
    array('-V', 0, 'Version fixture'),
    array('-v', 0, 'Version fixture'),
    array('--help', 0, 'usage: poller_recovery.php'),
    array('-H', 0, 'usage: poller_recovery.php'),
    array('-h', 0, 'usage: poller_recovery.php'),
    array('', 1, 'Database connection unavailable; recovery samples were retained'),
));
