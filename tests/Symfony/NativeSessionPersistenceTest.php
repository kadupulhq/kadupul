<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__ . '/NativeSessionChildCoverage.php';

final class NativeSessionPersistenceTest extends TestCase
{
    public static function fixtures(): iterable
    {
        yield 'lifecycle and actual facade' => ['session-store-native.php', 60];
        yield 'transaction failures and storage guards' => ['session-store-fault-native.php', 73];
        yield 'bounded SQL and native cache capacity' => ['session-store-budget-native.php', 10];
        yield 'concurrent table metadata and GC rollback' => ['session-store-metadata-lock-native.php', 17];
        yield 'registered native engine callbacks' => ['session-engine-native.php', 33];
    }

    #[DataProvider('fixtures')]
    public function testRealDatabasePersistence(string $fixture, int $checks): void
    {
        if (getenv('KADUPUL_TEST_MYSQL_DSN') === false) {
            self::markTestSkipped('Set KADUPUL_TEST_MYSQL_DSN for isolated real-schema tests.');
        }
        $coverage = NativeSessionChildCoverage::start('persistence:' . $fixture, $fixture);
        try {
            $process = new Process([PHP_BINARY, ...($coverage?->arguments() ?? []), dirname(__DIR__) . '/Fixtures/' . $fixture, dirname(__DIR__, 2)], env: $coverage?->environment(), timeout: 60);
            $process->mustRun();
            self::assertSame('', $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertSame($checks, $result['checks'] ?? null);
            $coverage?->finish();
            if ($coverage !== null && $fixture === 'session-engine-native.php') {
                NativeSessionCoverageRegistration::requireMeasured(\PHPUnit\Runner\CodeCoverage::instance()->codeCoverage()->getData()->lineCoverage(), dirname(__DIR__, 2));
            }
        } finally {
            $coverage?->cleanup();
        }
    }
}
