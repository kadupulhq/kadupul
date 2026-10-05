<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs the shipped include/auth.php and lib/auth.php in a child PHP process
 * against an in-memory user_auth table; see Fixtures/auth-entry-probe-child.php. The
 * child's stubs live in their own file because PHP declares a file's
 * functions when it compiles the file, and other tests in the same run
 * declare the same names.
 */

require_once __DIR__ . '/ChildProcessCoverage.php';

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
            child_coverage_command(array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-d', 'xdebug.mode=off', '-r', 'require ' . var_export(__DIR__ . '/../Fixtures/auth-entry-probe-child.php', true) . ';'), $coverage_dir, child_coverage_registration(__FILE__, 'auth-entry-probe', $scenario, array('auth-entry-result-readback'), array('lib/auth.php'), array('tests/Fixtures/auth-entry-probe-child.php', 'tests/Unit/Security/Auth/RememberMePasswordChangeTest.php', 'tests/Unit/Security/Auth/PermissionDeniedPageTest.php', 'tests/Unit/Security/Auth/TemplateUserFailureTest.php', 'tests/Unit/Security/Auth/NoAuthenticationRecoveryTest.php', 'tests/Unit/Security/Auth/LocalLoginTimingTest.php', 'tests/Unit/Security/Auth/GuestProfileAccessTest.php'))),
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
        child_coverage_collect($coverage_dir);

        $decoded = json_decode((string) $stdout, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('child process failed: ' . $stdout . $stderr);
        }

        $decoded['stderr'] = $stderr;

        return $decoded;
    }
}
