<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\NativeCoverageReportParity;

test('native admission preserves complete final coverage and strict evidence', function (string $vendor, string $uncovered): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'pcov.directory=' . $root, '-d', 'pcov.exclude=~/(include/vendor|tests)/~', '-d', 'display_errors=stderr', $root . '/tests/Fixtures/native-coverage-parity.php', $vendor, $uncovered],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new \RuntimeException('Cannot start native coverage parity process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    expect($stderr)->toBe('')->and($exit)->toBe(0)->and($stdout)->toBeString();
    $result = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expect($result['driver'])->toBe(extension_loaded('pcov') || extension_loaded('xdebug') ? 'native' : 'recorded-library-data')
        ->and($result['version'])->toStartWith($vendor === 'tests' ? '12.' : '10.')
        ->and($result['parity'])->toBeTrue()
        ->and($result['rawFiles'])->toBe(1)
        ->and($result['finalFiles'])->toBe(3)
        ->and($result['filterFiles'])->toBe(3)
        ->and($result['uncovered'])->toBeTrue()
        ->and($result['controls'])->toBe(14)
        ->and($result['restored'])->toBeTrue()
        ->and($result['filteredRejected'])->toBeTrue();
})->with([
    'Pest coverage 12 with uncovered sources' => ['tests', 'included'],
    'Pest coverage 12 without child expansion' => ['tests', 'excluded'],
    'application coverage 10 with uncovered sources' => ['include', 'included'],
    'application coverage 10 without child expansion' => ['include', 'excluded'],
]);
