<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped include/auth.php and lib/auth.php in a child PHP process
 * against an in-memory user_auth table; see AuthEntryProbeChild.php. The
 * child's stubs live in their own file because PHP declares a file's
 * functions when it compiles the file, and other tests in the same run
 * declare the same names.
 */

if (!function_exists('auth_entry_probe_run')) {
    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    function auth_entry_probe_run(array $scenario): array
    {
        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'xdebug.mode=off', __DIR__ . '/AuthEntryProbeChild.php'),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException('unable to start the PHP child process');
        }

        fwrite($pipes[0], json_encode($scenario));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('child process failed: ' . $stdout . $stderr);
        }

        $decoded['stderr'] = $stderr;

        return $decoded;
    }
}
