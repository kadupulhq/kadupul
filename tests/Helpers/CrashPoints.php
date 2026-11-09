<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Bounded crash testing for file writers, after Mohan et al., "Finding
 * Crash-Consistency Bugs with Bounded Black-Box Crash Testing" (OSDI 2018).
 *
 * A child process evaluates a production writer inside a namespace where each
 * filesystem call below is wrapped. Every wrapped call is two crash points,
 * before and after the real call. When KADUPUL_CRASH_AT names a point, the
 * child exits there with CRASH_POINT_STATUS, skipping finally blocks and
 * cleanup the way a killed process does. The parent runs the child once per
 * point until a run completes, checking the files after every crash.
 *
 * This models a process crash. It does not model power loss or the kernel
 * reordering writes that were not synced (Pillai et al., OSDI 2014).
 */

namespace Kadupul\Tests\CrashPoints;

const CRASH_POINT_STATUS = 97;

const WRAPPED = [
    'chgrp', 'chmod', 'chown', 'fclose', 'fflush', 'file_put_contents', 'flock', 'fopen', 'fsync',
    'ftruncate', 'fwrite', 'rename', 'tempnam', 'touch', 'unlink',
];

/** Record one crash point; exit the process when it is the selected one. */
function point(string $call, string $when): void
{
    static $count = 0;
    $count++;
    $selected = (int) (getenv('KADUPUL_CRASH_AT') ?: 0);
    if ($selected === $count) {
        fwrite(STDERR, "crash at {$count}: {$when} {$call}\n");
        exit(CRASH_POINT_STATUS);
    }
}

/** Define the wrapped filesystem calls in a namespace that production code is then evaluated in. */
function define_wrappers(string $namespace): void
{
    $code = 'namespace ' . $namespace . ';';
    foreach (WRAPPED as $name) {
        $code .= 'function ' . $name . '(...$arguments) {'
            . '\Kadupul\Tests\CrashPoints\point(' . var_export($name, true) . ', "before");'
            . '$result = \\' . $name . '(...$arguments);'
            . '\Kadupul\Tests\CrashPoints\point(' . var_export($name, true) . ', "after");'
            . 'return $result; }';
    }
    // Test-only: the code is built from the constant WRAPPED list and a namespace the test names.
    eval($code); // nosemgrep: php.lang.security.eval-use.eval-use
}

/**
 * Run a child scenario at every crash point until it completes.
 *
 * @param callable(int): array{0: int, 1: string, 2: string} $run runs the child for a point (0 = no crash)
 * @param callable(int, string): void $afterCrash checks the files after the crash at a point
 *
 * @return int the number of crash points exercised
 */
function exhaust(callable $run, callable $afterCrash, int $limit = 400): int
{
    for ($point = 1; $point <= $limit; $point++) {
        [$status, $out, $err] = $run($point);
        if ($status === 0) {
            if (!str_contains($out, 'completed')) {
                throw new \RuntimeException("Scenario exited 0 without completing at point {$point}: {$out}{$err}");
            }

            return $point - 1;
        }
        if ($status !== CRASH_POINT_STATUS) {
            throw new \RuntimeException("Scenario failed outside a crash point at {$point} (status {$status}): {$out}{$err}");
        }
        $afterCrash($point, $err);
    }

    throw new \RuntimeException("Scenario did not complete within {$limit} crash points.");
}
