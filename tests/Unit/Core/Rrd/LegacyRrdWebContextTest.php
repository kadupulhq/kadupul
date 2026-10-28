<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

test('legacy RRD web context uses explicit viewer state and ignores ambient cookies', function () {
    $root = dirname(__DIR__, 4);
    $program = '<?php ';
    $program .= '$events = array();';
    $program .= 'function cacti_session_close() { $GLOBALS["events"][] = "session"; }';
    $program .= 'function cacti_time_zone_set($timezone) { $GLOBALS["events"][] = array("timezone", $timezone); }';
    $program .= 'require ' . var_export($root . '/src/Graphing/Infrastructure/Legacy/LegacyRrdWebContext.php', true) . ';';
    $program .= <<<'SOURCE'
$context = new \Kadupul\Graphing\Infrastructure\Legacy\LegacyRrdWebContext();
$_COOKIE['CactiTimeZone'] = '999';
$context->releaseSession();
$context->prepareProcess(true, 330);
$_COOKIE['CactiTimeZone'] = '-999';
$context->releaseSession();
$context->prepareProcess(true, -240);
$context->prepareProcess(false, 180);
$context->prepareProcess(true, null);
$context->prepareProcess(true, 0);
$context->prepareProcess(true, '0');
echo json_encode($GLOBALS['events']);
SOURCE;

    $process = proc_open(array(PHP_BINARY, '-r', substr($program, 6)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $error . $output)
        ->and($error)->toBe('')
        ->and(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array('session', array('timezone', 330), 'session', array('timezone', -240), array('timezone', 0), array('timezone', '0')));
});
