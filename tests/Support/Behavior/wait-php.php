<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// A separate completion channel distinguishes application exit 70 from a
// supervisor failure. The caller supplies a fresh private filename per run.
$completion = isset($argv[1]) ? @fopen($argv[1], 'x') : false;
if ($completion === false) {
    fwrite(STDERR, "Cannot create the poller observation completion file.\n");
    exit(70);
}
chmod($argv[1], 0600);

// Own a process group before launching PHP so shells and not-yet-execed descendants
// are visible even after the application parent exits. Never match by name.
$group = getmypid();
if (!function_exists('posix_setsid') || !is_dir('/proc') || (posix_getpgrp() !== $group && posix_setsid() < 0)) {
    fwrite(STDERR, "Cannot establish the poller observation process group.\n");
    exit(70);
}
$child = proc_open(array_merge(array(PHP_BINARY), array_slice($argv, 2)), array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
if (!is_resource($child)) {
    fwrite(STDERR, "Cannot start the observed PHP process.\n");
    exit(70);
}
$status = proc_close($child);
$deadline = microtime(true) + 30;
do {
    $active = false;
    foreach (glob('/proc/[0-9]*/stat') as $path) {
        if ((int) basename(dirname($path)) === $group) {
            continue;
        }
        $stat = @file_get_contents($path);
        if ($stat === false) {
            continue; // A process that disappeared has completed.
        }
        $end = strrpos($stat, ')');
        $fields = explode(' ', substr($stat, $end + 2));
        if (isset($fields[2]) && (int) $fields[2] === $group && $fields[0] !== 'Z') {
            $active = true;
            break;
        }
    }
    if (!$active) {
        if (fwrite($completion, "complete\n") !== 9 || !fclose($completion)) {
            fwrite(STDERR, "Cannot record the poller observation completion.\n");
            exit(70);
        }
        exit($status);
    }
    usleep(10000);
} while (microtime(true) < $deadline);
fwrite(STDERR, "Poller descendants did not finish before observation capture.\n");
exit(70);
