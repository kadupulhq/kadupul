<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('legacy RRD web context releases sessions and applies only a web timezone', function () {
    $root = dirname(__DIR__, 4);
    $program = '<?php ';
    $program .= '$events = array();';
    $program .= 'function cacti_session_close() { $GLOBALS["events"][] = "session"; }';
    $program .= 'function cacti_time_zone_set($timezone) { $GLOBALS["events"][] = "timezone:" . $timezone; }';
    $program .= 'require ' . var_export($root . '/src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php', true) . ';';
    $program .= <<<'SOURCE'
$context = new \Kadupul\Graphing\Infrastructure\Legacy\LegacyRrdWebContext();
$_COOKIE['CactiTimeZone'] = '-07:00';
$context->releaseSession();
$context->prepareProcess(array('is_web' => true));
$context->releaseSession();
$context->prepareProcess(array('is_web' => false));
echo json_encode($GLOBALS['events']);
SOURCE;

    $process = proc_open(array(PHP_BINARY, '-r', substr($program, 6)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error . $output)
        ->and($error)->toBe('')
        ->and(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array('session', 'timezone:-07:00', 'session'));
});
