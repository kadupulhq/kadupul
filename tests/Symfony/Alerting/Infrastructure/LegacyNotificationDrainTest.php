<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Infrastructure;

use Kadupul\Alerting\Application\DeliverNotifications;
use Kadupul\Alerting\Application\DeliveryReport;
use Kadupul\Alerting\Infrastructure\Legacy\LegacyNotificationDrain;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyNotificationDrainTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>, ?float}> */
    private array $calls = [];

    /** @param array{output: string, errors: string, ok: bool, result: array<mixed>} $outcome */
    private function worker(array $outcome): LegacyWorkerRunner
    {
        $calls = &$this->calls;

        return new class ($outcome, $calls) implements LegacyWorkerRunner {
            /** @param list<array{string, string, array<string, mixed>, ?float}> $calls */
            public function __construct(private array $outcome, private array &$calls) {}

            public function run(string $script, string $marker, array $command, ?float $timeout, ?\Closure $progress = null): array
            {
                $this->calls[] = [$script, $marker, $command, $timeout];

                return $this->outcome;
            }
        };
    }

    public function testRunsTheDeliveryWorkerAndReturnsItsReport(): void
    {
        $result = ['status' => 'ok', 'sent' => 3, 'retrying' => 2, 'failed' => 1, 'lost' => 0, 'suppressed' => 4];
        $drain = new LegacyNotificationDrain($this->worker(['output' => '', 'errors' => '', 'ok' => true, 'result' => $result]));

        self::assertEquals(new DeliveryReport(3, 2, 1, 0, 4), $drain->drain(20, 12.5));
        self::assertSame([[
            'legacy-alerting-deliver.php',
            LegacyNotificationDrain::MARKER,
            ['batch_size' => 20, 'budget_seconds' => 12.5],
            12.5 + DeliverNotifications::LEASE_SECONDS,
        ]], $this->calls);
    }

    /** @return iterable<string, array{array{output: string, errors: string, ok: bool, result: array<mixed>}}> */
    public static function failures(): iterable
    {
        $ok = ['status' => 'ok', 'sent' => 0, 'retrying' => 0, 'failed' => 0, 'lost' => 0, 'suppressed' => 0];
        yield 'worker failed' => [['output' => '', 'errors' => '', 'ok' => false, 'result' => ['status' => 'failed']]];
        yield 'no marker' => [['output' => '', 'errors' => '', 'ok' => false, 'result' => []]];
        yield 'ok marker but a failed exit' => [['output' => '', 'errors' => '', 'ok' => false, 'result' => $ok]];
        yield 'missing count' => [['output' => '', 'errors' => '', 'ok' => true, 'result' => array_diff_key($ok, ['lost' => true])]];
        yield 'negative count' => [['output' => '', 'errors' => '', 'ok' => true, 'result' => ['sent' => -1] + $ok]];
        yield 'string count' => [['output' => '', 'errors' => '', 'ok' => true, 'result' => ['sent' => '1'] + $ok]];
    }

    /** @param array{output: string, errors: string, ok: bool, result: array<mixed>} $outcome */
    #[DataProvider('failures')]
    public function testAnUnconfirmedOutcomeIsAnError(array $outcome): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('The alert notification worker did not report a delivery.');

        (new LegacyNotificationDrain($this->worker($outcome)))->drain(20, 0.0);
    }
}
