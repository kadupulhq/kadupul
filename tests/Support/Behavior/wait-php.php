<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// A separate completion channel distinguishes application exit 70 from failure.
$completion = isset($argv[1]) ? @fopen($argv[1], 'x') : false;
if ($completion === false) {
    fwrite(STDERR, "Cannot create the poller observation completion file.\n");
    exit(70);
}
chmod($argv[1], 0600);
$group = getmypid();
if (!function_exists('posix_setsid') || !is_dir('/proc') || (posix_getpgrp() !== $group && posix_setsid() < 0)) {
    fwrite(STDERR, "Cannot establish the poller observation process group.\n");
    exit(70);
}

function observation_members($group)
{
    $members = array();
    foreach (glob('/proc/[0-9]*/stat') as $path) {
        $pid = (int) basename(dirname($path));
        if ($pid === $group) {
            continue;
        }
        $stat = @file_get_contents($path);
        if ($stat === false) {
            continue;
        }
        $end = strrpos($stat, ')');
        $fields = explode(' ', substr($stat, $end + 2));
        if (isset($fields[2]) && (int) $fields[2] === $group && $fields[0] !== 'Z') {
            $members[] = $pid;
        }
    }
    return $members;
}

$child = proc_open(array_merge(array(PHP_BINARY), array_slice($argv, 2)), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
if (!is_resource($child)) {
    fwrite(STDERR, "Cannot start the observed PHP process.\n");
    exit(70);
}
$timeout = getenv('HARNESS_OBSERVATION_TIMEOUT');
$deadline = microtime(true) + ($timeout === false ? 30 : max(1, min(300, (int) $timeout)));
$exit_code = null;
do {
    $status = proc_get_status($child);
    if (!$status['running'] && $exit_code === null) {
        $exit_code = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
    }
    if (!$status['running'] && !observation_members($group)) {
        $closed = proc_close($child);
        if (fwrite($completion, "complete\n") !== 9 || !fclose($completion)) {
            fwrite(STDERR, "Cannot record the poller observation completion.\n");
            exit(70);
        }
        exit($exit_code !== null && $exit_code >= 0 ? $exit_code : $closed);
    }
    usleep(10000);
} while (microtime(true) < $deadline);

// Do not signal ourselves: retain control of the incomplete completion channel.
// Re-scan membership while terminating so late-forked descendants are included.
foreach (array(15, 9) as $signal) {
    $stop = microtime(true) + 1;
    do {
        $members = observation_members($group);
        foreach ($members as $pid) {
            @posix_kill($pid, $signal);
        }
        if (!$members) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $stop);
}
if (proc_get_status($child)['running']) {
    proc_terminate($child, 9);
}
proc_close($child);
fclose($completion);
fwrite(STDERR, "Poller descendants did not finish before observation capture; remaining workers terminated.\n");
exit(70);
