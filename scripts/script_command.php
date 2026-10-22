<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Runs commands for the PHP ports of the Perl data input scripts. Each port
 * ran its commands through a shell with this fixed PATH; here they run from
 * an argument array, so no shell ever parses an argument.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SCRIPT_COMMAND_PATH = '/usr/bin:/bin:/usr/sbin:/sbin:/usr/local/bin:/usr/local/sbin';

/** Output beyond this is not a plausible result from the commands these scripts run. */
const SCRIPT_COMMAND_OUTPUT_LIMIT = 8_388_608;

/** Finds an executable the way the shell would on $path, or returns an absolute name as is. */
function script_command_find(string $name, string $path = SCRIPT_COMMAND_PATH): ?string
{
    if (str_contains($name, '/')) {
        return is_file($name) && is_executable($name) ? $name : null;
    }

    foreach (explode(':', $path) as $directory) {
        $candidate = rtrim($directory, '/') . '/' . $name;
        if ($directory !== '' && is_file($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Returns the command's standard output, with standard error merged in when
 * asked, or false when it cannot start, runs past $timeout seconds or prints
 * more than any of these commands could. Standard error is otherwise passed
 * through, as the Perl scripts left it.
 *
 * @param list<string>          $command
 * @param array<string, string> $environment added to the inherited environment
 */
function script_command_output(array $command, array $environment = [], float $timeout = 10.0, bool $mergeStderr = false, string $path = SCRIPT_COMMAND_PATH): string|false
{
    $executable = $command === [] ? null : script_command_find($command[0], $path);
    if ($executable === null) {
        return false;
    }
    $command[0] = $executable;

    $inherited = getenv();
    $inherited['PATH'] = $path;
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['pipe', 'w'],
        2 => $mergeStderr ? ['redirect', 1] : STDERR,
    ];
    $process = proc_open($command, $descriptors, $pipes, null, array_replace($inherited, $environment));
    if (!is_resource($process)) {
        return false;
    }

    $deadline = hrtime(true) + (int) ($timeout * 1e9);
    $output = script_command_read($pipes[1], $deadline);
    fclose($pipes[1]);

    // A command that closed its output may still be running; never wait past the deadline for it.
    while (($status = proc_get_status($process))['running'] && hrtime(true) < $deadline) {
        usleep(5_000);
    }
    if ($status['running']) {
        proc_terminate($process, 9);
        $output = false;
    }
    proc_close($process);

    return $output;
}

/** @param resource $pipe */
function script_command_read($pipe, int $deadline): string|false
{
    stream_set_blocking($pipe, false);
    $output = '';
    while (!feof($pipe)) {
        $remaining = $deadline - hrtime(true);
        if ($remaining <= 0) {
            return false;
        }
        $read = [$pipe];
        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, intdiv($remaining, 1_000_000_000), intdiv($remaining % 1_000_000_000, 1_000));
        if ($ready === false) {
            return false;
        }
        if ($ready === 0) {
            continue;
        }
        $chunk = fread($pipe, 65_536);
        if ($chunk === false) {
            return false;
        }
        $output .= $chunk;
        if (strlen($output) > SCRIPT_COMMAND_OUTPUT_LIMIT) {
            return false;
        }
    }

    return $output;
}
