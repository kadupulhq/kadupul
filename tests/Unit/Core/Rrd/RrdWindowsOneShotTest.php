<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdWindowsCoverageRegistration.php';
require_once dirname(__DIR__, 3) . '/Helpers/NativeChildCoverageEvidence.php';

test('Windows one-shot transport preserves production output and enforces deadlines', function () {
    $root = dirname(__DIR__, 4);
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    $directory = sys_get_temp_dir() . '/rrd-windows-coverage-' . bin2hex(random_bytes(12));
    $command = [PHP_BINARY, '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
        $root . '/' . RrdWindowsCoverageRegistration::PRODUCER];
    if ($coverage !== null) {
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot create owned coverage directory');
        }
        $command = [...$command, '--coverage', $directory];
    }
    try {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        expect(is_resource($process))->toBeTrue();
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);
        expect($status)->toBe(0);
        expect($output)->toContain('Verified 21 Windows one-shot checks');
        if ($coverage !== null) {
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $arguments = [$reports[0], $root, RrdWindowsCoverageRegistration::PRODUCER,
                RrdWindowsCoverageRegistration::SCENARIO, RrdWindowsCoverageRegistration::SOURCES,
                RrdWindowsCoverageRegistration::MARKERS, RrdWindowsCoverageRegistration::HITS];
            $measured = NativeChildCoverageEvidence::load(...$arguments);
            expect(NativeChildCoverageEvidence::verifyRejections(...[...$arguments, RrdWindowsCoverageRegistration::HITS[1]]))
                ->toBe(count(RrdWindowsCoverageRegistration::SOURCES) + count(RrdWindowsCoverageRegistration::MARKERS) + 10);
            $coverage->merge($measured);
        }
    } finally {
        if (is_dir($directory)) {
            foreach (glob($directory . '/*') as $report) {
                unlink($report);
            }
            rmdir($directory);
        }
    }
});
