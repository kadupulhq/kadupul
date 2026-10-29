<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\FlushBoostPartition;
use Kadupul\Graphing\Application\Port\BoostPartitionResult;
use Kadupul\Graphing\Application\Port\BoostPartitionWriter;
use Kadupul\Graphing\Infrastructure\Symfony\FlushBoostPartitionHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class FlushBoostPartitionHandlerTest extends TestCase
{
    private const string RUN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testHandlerRejectsMalformedRunIdBeforeStartingAWorker(): void
    {
        $writer = $this->createMock(BoostPartitionWriter::class);
        $writer->expects(self::never())->method('flush');
        // A message restored by the serializer skips the constructor.
        $message = new \ReflectionClass(FlushBoostPartition::class)->newInstanceWithoutConstructor();
        (new \ReflectionProperty($message, 'runId'))->setValue($message, 'AAAA; rm');
        (new \ReflectionProperty($message, 'partition'))->setValue($message, 1);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        (new FlushBoostPartitionHandler($writer, 10800))($message);
    }

    public function testMessageRejectsBadRunAndPartition(): void
    {
        foreach ([['short', 1], [strtoupper(self::RUN), 1], [self::RUN . "\n", 1], [self::RUN, 0]] as [$run, $partition]) {
            try {
                new FlushBoostPartition($run, $partition);
                self::fail('Accepted ' . json_encode([$run, $partition]));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testStaleRunIsAcknowledgedWithoutWriting(): void
    {
        $writer = $this->createMock(BoostPartitionWriter::class);
        $writer->expects(self::once())->method('flush')->with(self::RUN, 2, 10800.0)
            ->willReturn(new BoostPartitionResult(true, 0, 'stale'));

        (new FlushBoostPartitionHandler($writer, 10800))(new FlushBoostPartition(self::RUN, 2));
    }

    public function testFailedPartitionIsNotRetried(): void
    {
        $writer = $this->createStub(BoostPartitionWriter::class);
        $writer->method('flush')->willReturn(new BoostPartitionResult(false, 0, 'timeout'));

        $this->expectException(UnrecoverableMessageHandlingException::class);
        (new FlushBoostPartitionHandler($writer, 10800))(new FlushBoostPartition(self::RUN, 1));
    }
}
