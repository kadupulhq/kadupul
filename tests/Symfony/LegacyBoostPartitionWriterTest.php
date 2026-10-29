<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Infrastructure\Legacy\LegacyBoostPartitionWriter;
use Kadupul\Platform\Contract\LegacyWorkerRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class LegacyBoostPartitionWriterTest extends TestCase
{
    private const string RUN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @return iterable<string, array{bool, array<mixed>, bool, int, string}> */
    public static function outcomes(): iterable
    {
        yield 'ok' => [true, ['status' => 'ok', 'rows' => 7], true, 7, ''];
        yield 'zero rows' => [true, ['status' => 'ok', 'rows' => 0], true, 0, ''];
        yield 'stale' => [false, ['status' => 'stale'], true, 0, 'stale'];
        yield 'failed' => [false, ['status' => 'failed'], false, 0, 'failed'];
        yield 'no marker' => [false, [], false, 0, 'failed'];
        yield 'ok without rows' => [true, ['status' => 'ok'], false, 0, 'failed'];
        yield 'negative rows' => [true, ['status' => 'ok', 'rows' => -1], false, 0, 'failed'];
        yield 'string rows' => [true, ['status' => 'ok', 'rows' => '7'], false, 0, 'failed'];
        yield 'ok marker, bad exit' => [false, ['status' => 'ok', 'rows' => 7], false, 0, 'failed'];
    }

    /** @param array<mixed> $result */
    #[DataProvider('outcomes')]
    public function testWorkerResultIsMapped(bool $workerOk, array $result, bool $ok, int $rows, string $detail): void
    {
        $worker = $this->createMock(LegacyWorkerRunner::class);
        $worker->expects(self::once())->method('run')
            ->with('legacy-boost-partition.php', 'BOOST_PARTITION', ['run' => self::RUN, 'partition' => 3], 12.5)
            ->willReturn(['output' => '', 'errors' => '', 'ok' => $workerOk, 'result' => $result]);

        $outcome = (new LegacyBoostPartitionWriter($worker))->flush(self::RUN, 3, 12.5);

        self::assertSame([$ok, $rows, $detail], [$outcome->ok, $outcome->rows, $outcome->detail]);
    }

    public function testTimeoutIsAFailure(): void
    {
        $worker = $this->createStub(LegacyWorkerRunner::class);
        $worker->method('run')->willThrowException(new ProcessTimedOutException(new Process(['true']), ProcessTimedOutException::TYPE_GENERAL));

        $outcome = (new LegacyBoostPartitionWriter($worker))->flush(self::RUN, 1, 1.0);

        self::assertFalse($outcome->ok);
        self::assertSame('timeout', $outcome->detail);
    }
}
