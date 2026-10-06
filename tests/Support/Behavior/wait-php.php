<?php

declare(strict_types=1);

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

/** @param array{pid: int, running: bool, ...} $status */
function observation_diagnostics(int $group, array $status, ?int $exit_code): void
{
    $processes = array();
    $known_scripts = array('poller.php', 'cmd.php', 'poller_commands.php', 'poller_boost.php',
        'poller_maintenance.php', 'poller_reindex_hosts.php', 'poller_output_empty.php',
        'script_server.php', 'ss_host.php');
    foreach (observation_members($group) as $pid) {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) {
            continue;
        }
        $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        if (!isset($fields[2]) || (int) $fields[2] !== $group || $fields[0] === 'Z') {
            continue;
        }
        $script = null;
        // Only a recognized first script argument may be rendered. Later
        // arguments and environment values can contain credentials.
        $command = @file_get_contents('/proc/' . $pid . '/cmdline', false, null, 0, 8192);
        if ($command !== false) {
            $arguments = explode("\0", $command);
            $php_interpreter = preg_match('/^php(?:[0-9]+(?:\.[0-9]+)*)?$/D', basename($arguments[0])) === 1;
            for ($index = 1; $php_interpreter && $index < count($arguments); $index++) {
                $argument = $arguments[$index];
                if (in_array($argument, array('-d', '-c'), true)) {
                    $index++;
                    continue;
                }
                if (in_array($argument, array('-r', '-B', '-R', '-E'), true)) {
                    break;
                }
                if ($argument === '' || $argument[0] === '-') {
                    continue;
                }
                $basename = basename($argument);
                $script = in_array($basename, $known_scripts, true) ? $basename : null;
                break;
            }
        }
        $processes[] = array('pid' => $pid, 'parent' => (int) $fields[1], 'group' => (int) $fields[2],
            'state' => $fields[0], 'role' => $pid === $status['pid'] ? 'observed-parent' : 'descendant',
            'script' => $script);
    }
    fwrite(STDERR, 'POLLER_OBSERVATION_DIAGNOSTIC ' . json_encode(array(
        'group' => $group, 'observed_pid' => $status['pid'], 'parent_running' => $status['running'],
        'parent_exit' => $exit_code, 'processes' => $processes), JSON_THROW_ON_ERROR) . "\n");
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

observation_diagnostics($group, $status, $exit_code);

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
