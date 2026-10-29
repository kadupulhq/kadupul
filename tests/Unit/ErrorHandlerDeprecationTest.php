<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Run the production CactiErrorHandler on real diagnostics and return what it logged. */
function errorHandlerDeprecationRun(int $level): array
{
    $root = dirname(__DIR__, 2);
    $program = <<<'CHILD'
        $root = $argv[1];
        $level = (int) $argv[2];
        require $root . '/tests/Helpers/PhpSource.php';
        $source = file_get_contents($root . '/lib/functions.php');
        eval(test_php_function_source($source, 'CactiErrorHandler')); // nosemgrep: php.lang.security.eval-use.eval-use
        $logs = array();
        $backtraces = 0;
        function IgnoreErrorHandler($message) { return false; }
        function cacti_log($string, $output = false, $environ = 'CMDPHP') { $GLOBALS['logs'][] = array($environ, $string); }
        function cacti_debug_backtrace() { $GLOBALS['backtraces']++; }
        $phperrors = array(E_DEPRECATED => 'DEPRECATED', E_USER_DEPRECATED => 'USER_DEPRECATED');
        $returned = null;
        set_error_handler(function (...$args) use (&$returned) { return $returned = CactiErrorHandler(...$args); });
        if ($level === E_USER_DEPRECATED) {
            trigger_error('library is deprecated', E_USER_DEPRECATED);
        } else {
            // A real engine E_DEPRECATED: passing null to a non-nullable internal parameter.
            strlen(null);
        }
        echo json_encode(array('logs' => $logs, 'backtraces' => $backtraces, 'returned' => $returned));
        CHILD;
    $process = proc_open(array(PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'error_reporting=32767', '-d', 'display_errors=0', '-r', $program, $root, (string) $level), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error);

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}

test('deprecations are logged below ERROR without a backtrace', function (int $level, string $prefix) {
    $result = errorHandlerDeprecationRun($level);

    expect($result['logs'])->toHaveCount(1)
        ->and($result['logs'][0][0])->not->toBe('ERROR')
        ->and($result['logs'][0][0])->toBe('WARNING')
        ->and($result['logs'][0][1])->toStartWith($prefix)
        ->and($result['backtraces'])->toBe(0)
        ->and($result['returned'])->toBeFalse();
})->with(array(
    'E_USER_DEPRECATED' => array(E_USER_DEPRECATED, 'PHP USER_DEPRECATED: library is deprecated'),
    'E_DEPRECATED' => array(E_DEPRECATED, 'PHP DEPRECATED: '),
));
