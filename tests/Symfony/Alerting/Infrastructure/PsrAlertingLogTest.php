<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Kadupul\Alerting\Infrastructure\Logging\PsrAlertingLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class PsrAlertingLogTest extends TestCase
{
    public function testRecordsKeepTheirLevelMessageAndContext(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, (string) $message, $context];
            }
        };
        $log = new PsrAlertingLog($logger);

        $log->warning('Alert rule {rule} was skipped: {reason}', ['rule' => 3, 'reason' => 'r']);
        $log->error('{count} failed', ['count' => 2]);

        self::assertSame([
            [LogLevel::WARNING, 'Alert rule {rule} was skipped: {reason}', ['rule' => 3, 'reason' => 'r']],
            [LogLevel::ERROR, '{count} failed', ['count' => 2]],
        ], $logger->records);
    }
}
