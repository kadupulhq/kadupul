<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

function per_cs_review_run($test, string $mode, string $argument = ''): array
{
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/per-cs-review-' . bin2hex(random_bytes(8));
    foreach (array('', '/include', '/lib', '/cli') as $folder) {
        mkdir($directory . $folder, 0700);
    }
    foreach (array('include/auth.php', 'include/cli_check.php', 'lib/functions.php', 'lib/rrd.php', 'lib/poller.php', 'lib/utility.php') as $file) {
        file_put_contents($directory . '/' . $file, '<?php');
    }
    copy($root . '/cli/refresh_csrf.php', $directory . '/cli/refresh_csrf.php');
    $coverage = $test->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(array(PHP_BINARY, '-d', 'error_reporting=24575', '-d', 'display_errors=stderr', '-d', 'pcov.directory=/', '-d', 'pcov.exclude=~/(include/vendor|tests)/~', $root . '/tests/Fixtures/per-cs-review-native.php', $mode, $directory, $argument, $coverage === null ? '' : 'coverage'), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($coverage !== null) {
            foreach (glob($directory . '/*.coverage') as $report) {
                $coverage->merge(unserialize(file_get_contents($report)));
            }
        }
        return array($status, $output, $error);
    } finally {
        foreach (glob($directory . '/*/*') as $file) {
            unlink($file);
        }
        foreach (glob($directory . '/*') as $folder) {
            is_dir($folder) ? rmdir($folder) : unlink($folder);
        }
        rmdir($directory);
    }
}

test('RRD cleaner pagination retains the exact search filter', function ($filter) {
    list($status, $output, $error) = per_cs_review_run($this, 'cleaner', $filter);
    expect($status)->toBe(0)->and($error)->toBe('');
    $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    parse_str(parse_url($result['url'], PHP_URL_QUERY), $parameters);
    expect($parameters)->toBe(array('filter' => $filter));
})->with(array('traffic', 'device name', 'foo&bar', 'x+y', ''));

test('CSRF metadata identifies its utility and never rotates a secret', function ($argument) {
    list($status, $output, $error) = per_cs_review_run($this, 'csrf', $argument);
    expect($status)->toBe(0)->and($error)->toBe('')->and($output)->toContain('Kadupul CSRF Refresh Utility, Version fixture-version');
    expect($output)->not->toContain('Rebuild Poller Cache', 'on a the', 'Updating csrf_secret file');
})->with(array('--version', '--help'));

test('clog title caching accepts repeated scalar and list identifiers without warnings', function () {
    list($status, $output, $error) = per_cs_review_run($this, 'clog');
    expect($status)->toBe(0)->and($error)->toBe('');
    expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array(array(7 => 'Title 7', 8 => 'Title 8'), array(7 => 'Title 7'), array(7, 8)));
});
