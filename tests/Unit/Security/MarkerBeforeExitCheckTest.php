<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

function marker_check(array $arguments): array
{
    $root = dirname(__DIR__, 3);

    return test_php_run(array_merge(
        [PHP_BINARY, $root . '/tests/security/check_marker_before_exit.php', '--root', $root . '/tests/Fixtures/marker-before-exit'],
        $arguments
    ));
}

function marker_baseline(array $rows): string
{
    $file = tempnam(sys_get_temp_dir(), 'marker-baseline-');
    expect($file)->not->toBeFalse();
    file_put_contents($file, "# reviewed\n" . implode("\n", $rows) . "\n");

    return $file;
}

const MARKER_CASES = [
    "cases.php\t(file)\t'poller_mibsrun_' . \$poller_id\t1",
    "cases.php\tdie_in_an_expression\t'job_stamp'\t1",
    "cases.php\tearly_exit_in_branch\t'job_lastrun'\t1",
    "cases.php\texit_earlier_in_the_loop\t'job_lastrun_' . \$item\t1",
    "cases.php\texit_in_a_loop\t'job_last_run_time'\t1",
    "cases.php\texit_in_the_other_branch_of_a_loop\t'job_last_poll'\t1",
    "cases.php\texit_through_a_helper\t'job_stamp'\t1",
    "cases.php\texit_through_a_never_function\t'job_lastrun'\t1",
];

test('early exits after a run marker are reported and safe shapes are not', function () {
    $result = marker_check(['--baseline', '/dev/null', 'cases.php']);

    expect($result['status'])->toBe(1, $result['err'])
        ->and($result['out'])->toContain('1 files, 8 matches, 8 new, 0 stale')
        ->and($result['out'])->toContain("cases.php:10: 'job_lastrun' is recorded before the exit at line 12 in early_exit_in_branch")
        ->and($result['out'])->toContain("cases.php:85: 'poller_mibsrun_' . \$poller_id is recorded before the exit at line 89 in (file)")
        // The next iteration reaches an exit earlier in the loop body, or in the if's other branch.
        ->and($result['out'])->toContain("cases.php:98: 'job_lastrun_' . \$item is recorded before the exit at line 96 in exit_earlier_in_the_loop")
        ->and($result['out'])->toContain("cases.php:106: 'job_last_poll' is recorded before the exit at line 108 in exit_in_the_other_branch_of_a_loop")
        // A function that ends with exit, or is declared never, exits when called.
        ->and($result['out'])->toContain("cases.php:136: 'job_stamp' is recorded before the exit at line 138 in exit_through_a_helper")
        ->and($result['out'])->toContain("cases.php:145: 'job_lastrun' is recorded before the exit at line 146 in exit_through_a_never_function");
    foreach (['terminal_exit_ends_the_run', 'exit_in_the_other_branch', 'nothing_runs_after_a_terminal_exit', 'ordinary_setting_is_ignored', 'nested_scope_is_separate', 'exit_before_the_marker',
        'exit_before_a_loop_that_holds_no_marker', 'a_helper_that_ends_the_run', 'bail_out', 'fail_validation'] as $safe) {
        expect($result['out'])->not->toContain(' in ' . $safe . "\n");
    }
});

test('a matching baseline passes', function () {
    $baseline = marker_baseline(MARKER_CASES);
    try {
        $result = marker_check(['--baseline', $baseline, 'cases.php']);
    } finally {
        unlink($baseline);
    }

    expect($result['status'])->toBe(0, $result['out'] . $result['err'])
        ->and($result['out'])->toContain('8 matches, 0 new, 0 stale');
});

test('a new match and a stale baseline row both fail', function () {
    $rows = array_slice(MARKER_CASES, 1);
    $rows[] = "cases.php\tremoved_function\t'job_lastrun'\t1";
    $baseline = marker_baseline($rows);
    try {
        $result = marker_check(['--baseline', $baseline, 'cases.php']);
    } finally {
        unlink($baseline);
    }

    expect($result['status'])->toBe(1)
        ->and($result['out'])->toContain('1 new, 1 stale')
        ->and($result['out'])->toContain('baseline row no longer matches: cases.php | removed_function');
});

test('the baseline writer records the current matches', function () {
    $baseline = tempnam(sys_get_temp_dir(), 'marker-baseline-');
    expect($baseline)->not->toBeFalse();
    try {
        $write = marker_check(['--baseline', $baseline, '--write-baseline', 'cases.php']);
        $check = marker_check(['--baseline', $baseline, 'cases.php']);
        $rows = array_values(array_filter(file($baseline, FILE_IGNORE_NEW_LINES), static fn(string $line): bool => !str_starts_with($line, '#')));
    } finally {
        unlink($baseline);
    }

    expect($write['status'])->toBe(0, $write['err'])->and($check['status'])->toBe(0)->and($rows)->toBe(MARKER_CASES);
});

test('a file that does not parse or a missing baseline is a tool error, not a pass', function () {
    $parse = marker_check(['--baseline', '/dev/null', 'broken-php.txt']);
    $missing = marker_check(['--baseline', sys_get_temp_dir() . '/missing-marker-baseline.tsv', 'cases.php']);

    expect($parse['status'])->toBe(2)->and($parse['err'])->toContain('Unable to parse broken-php.txt')
        ->and($missing['status'])->toBe(2)->and($missing['err'])->toContain('Unable to read');
});
