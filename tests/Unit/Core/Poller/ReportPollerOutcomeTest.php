<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('report poller reports actual outcomes and preserves scheduling', function (string $scenario, int $status, int $accepted, int $attempted, int $failed, int $skipped): void {
    $root = dirname(__DIR__, 4);
    $directory = sys_get_temp_dir() . '/report-poller-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    try {
        expect(mkdir($directory . '/include', 0700))->toBeTrue();
        expect(mkdir($directory . '/lib', 0700))->toBeTrue();
        expect(copy($root . '/poller_reports.php', $directory . '/poller_reports.php'))->toBeTrue();
        expect(copy($root . '/tests/Fixtures/report-poller-native.php', $directory . '/include/cli_check.php'))->toBeTrue();
        foreach (['poller', 'rrd', 'reports', 'time', 'html_reports'] as $library) {
            expect(file_put_contents($directory . '/lib/' . $library . '.php', '<?php'))->not->toBeFalse();
        }
        $environment = getenv();
        $environment['REPORT_POLLER_SOURCE_ROOT'] = $root;
        $environment['REPORT_POLLER_SCENARIO'] = $scenario;
        $command = [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', $directory . '/poller_reports.php'];
        if ($scenario === 'forced') {
            $command[] = '--force';
        }
        $pipes = [];
        $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory . '/stdout', 'w'], 2 => ['file', $directory . '/stderr', 'w']], $pipes, $directory, $environment);
        expect(is_resource($process))->toBeTrue();
        $exit = proc_close($process);
        $errors = file_get_contents($directory . '/stderr');
        expect($errors)->toBe('');
        expect($exit)->toBe($status, file_get_contents($directory . '/stdout'));
        $result = json_decode(file_get_contents($directory . '/result.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($result['registered'])->toBe($scenario === 'forced' ? 0 : 1);
        expect($result['unregistered'])->toBe(in_array($scenario, ['forced', 'busy'], true) ? 0 : 1);
        if (!in_array($scenario, ['missing-table', 'busy', 'stats-write-failure'], true)) {
            expect($result['stats'])->toMatch('/^Time:[0-9.]+ Reports:' . $accepted . ' Attempted:' . $attempted . ' Failed:' . $failed . ' Skipped:' . $skipped . '$/');
        }
        if (in_array($scenario, ['smtp-failure', 'mixed', 'missing-owner', 'owner-read-failure', 'report-read-failure'], true)) {
            expect($result['reports'][0]['mailtime'])->toBe($result['due'])->and($result['reports'][0]['lastsent'])->toBe(0);
        }
        if ($status === 1) {
            expect(implode('\n', $result['logs']))->toContain('ERROR:');
        }
        if (in_array($scenario, ['missing-table', 'busy', 'stats-write-failure'], true)) {
            expect($result['stats'])->toBeFalse();
        }
        if ($scenario === 'report-read-failure') {
            expect(implode('\n', $result['logs']))->not->toContain('reports found: 0');
        }
        if ($scenario === 'forced') {
            expect($result['reports'][1]['mailtime'])->toBe($result['due'] + 86400 + 3600);
        }
        if ($scenario === 'missing-owner') {
            expect($result['reports'][0]['enabled'])->toBe('');
        }
        $shouldAdvance = match ($scenario) {
            'success', 'stats-write-failure' => [0],
            'mixed', 'missing-owner', 'owner-read-failure' => [1],
            'forced' => [0, 1],
            default => [],
        };
        foreach ($shouldAdvance as $index) {
            expect($result['reports'][$index]['lastsent'])->toBeGreaterThan($result['due']);
            expect($result['reports'][$index]['mailtime'])->toBeGreaterThan($result['due']);
        }
        expect($result['mailer'])->toHaveCount(match ($scenario) {
            'success', 'smtp-failure', 'stats-write-failure', 'missing-owner', 'owner-read-failure' => 1,
            'mixed', 'forced' => 2,
            default => 0,
        });
    } finally {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
})->with([
    'accepted' => ['success', 0, 1, 1, 0, 0],
    'SMTP rejected' => ['smtp-failure', 1, 0, 1, 1, 0],
    'mixed batch continues after failure' => ['mixed', 1, 1, 2, 1, 0],
    'missing owner disables only its report' => ['missing-owner', 1, 1, 2, 1, 0],
    'empty due set records zero counts' => ['empty', 0, 0, 0, 0, 0],
    'force includes future reports' => ['forced', 0, 2, 2, 0, 0],
    'failed owner read stops that report' => ['owner-read-failure', 1, 1, 2, 1, 0],
    'failed due-report query' => ['report-read-failure', 1, 0, 0, 0, 0],
    'failed statistics write' => ['stats-write-failure', 1, 1, 1, 0, 0],
    'upgrade missing table releases registration' => ['missing-table', 0, 0, 0, 0, 0],
    'another poller owns registration' => ['busy', 0, 0, 0, 0, 0],
]);
