<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use NativeChildCoverageEvidence;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;

require_once __DIR__ . '/NativeCoverageStack.php';
require_once dirname(__DIR__) . '/Helpers/NativeChildCoverageEvidence.php';

/** The copied workers share their real parser, not their stand-in application bootstrap. */
final class WorkerIoCoverage
{
    public const string PRODUCER = 'tests/Fixtures/worker-io-coverage.php';
    public const string MARKER = 'copied-worker-io-terminated';
    /** Each worker's producing test and the fixtures that stand in for its bootstrap. */
    private const array WORKERS = [
        'bin/legacy-poller-output-empty.php' => ['tests/Unit/Core/Cli/PollerDrainNativeTest.php'],
        'bin/legacy-rejected-samples-replay.php' => ['tests/Unit/Core/Cli/ReplayRejectedSamplesCliTest.php'],
        'bin/legacy-alerting-deliver.php' => ['tests/Unit/Core/Cli/AlertingDeliveryWorkerNativeTest.php',
            'tests/Fixtures/alerting-delivery-bootstrap.php', 'tests/Fixtures/alerting-delivery-kernel.php'],
    ];

    /** @return list<string> */
    public static function sources(string $worker): array
    {
        if (!isset(self::WORKERS[$worker])) {
            throw new RuntimeException('Unknown copied worker coverage producer.');
        }
        return ['composer.lock', 'tests/composer.lock', 'tests/Fixtures/rrd-process-coverage.php',
            'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Symfony/NativeCoverageStack.php',
            'tests/Symfony/WorkerIoCoverage.php', 'tests/Symfony/WorkerIoCoverageTest.php',
            'tests/Fixtures/drain-cli-bootstrap.php', ...self::WORKERS[$worker], $worker, 'bin/worker-io.php'];
    }

    /** @return array<string, string> Copy => canonical source. */
    public static function copies(string $root, string $copy, string $worker): array
    {
        self::sources($worker);
        $copies = [$copy => $root . '/' . $worker, dirname($copy) . '/worker-io.php' => $root . '/bin/worker-io.php'];
        foreach ($copies as $file => $source) {
            $actual = is_file($file) ? hash_file('sha256', $file) : false;
            $expected = is_file($source) ? hash_file('sha256', $source) : false;
            if ($actual === false || $expected === false || realpath($file) === realpath($source)
                || !hash_equals($expected, $actual)) {
                throw new RuntimeException('Copied CLI changed while measuring coverage');
            }
        }
        return $copies;
    }

    /** @return array{list<string>, array<string, string>} */
    public static function configuration(string $root, string $directory, string $worker, string $scenario): array
    {
        self::sources($worker);
        return [['-d', 'auto_prepend_file=' . $root . '/' . self::PRODUCER], [
            'KADUPUL_WORKER_COVERAGE_DIRECTORY' => $directory,
            'KADUPUL_WORKER_COVERAGE_WORKER' => $worker,
            'KADUPUL_WORKER_COVERAGE_SCENARIO' => $scenario,
            'KADUPUL_WORKER_COVERAGE_STACK' => json_encode(NativeCoverageStack::capture($root), JSON_THROW_ON_ERROR),
        ]];
    }

    /**
     * @param bool $helperExecuted false for a request the worker refuses
     *                             before it loads bin/worker-io.php
     */
    public static function load(string $report, string $root, string $worker, string $scenario, bool $helperExecuted = true): CodeCoverage
    {
        return NativeChildCoverageEvidence::load(
            $report,
            $root,
            self::PRODUCER,
            $scenario,
            self::sources($worker),
            [self::MARKER],
            $helperExecuted ? [$worker, 'bin/worker-io.php'] : [$worker],
            NativeCoverageStack::capture($root)
        );
    }
}
