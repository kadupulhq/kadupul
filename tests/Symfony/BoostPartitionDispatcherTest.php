<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Graphing\Application\Command\FlushBoostPartition;
use Kadupul\Graphing\Infrastructure\Symfony\BoostPartitionDispatcher;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

final class BoostPartitionDispatcherTest extends TestCase
{
    private const string RUN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testDispatchesOneMessagePerPartitionNumberedFromOne(): void
    {
        $transport = new InMemoryTransport();
        (new BoostPartitionDispatcher($this->bus($transport)))->dispatch(self::RUN, 3);

        $sent = array_map(static fn(Envelope $envelope): array => [$envelope->getMessage()->runId, $envelope->getMessage()->partition], $transport->getSent());
        self::assertSame([[self::RUN, 1], [self::RUN, 2], [self::RUN, 3]], $sent);
    }

    public function testUnreachableTransportRaisesRuntimeExceptionWithoutLeakingTheCause(): void
    {
        $sender = new class implements SenderInterface {
            public int $calls = 0;

            public function send(Envelope $envelope): Envelope
            {
                ++$this->calls;
                if ($this->calls === 2) {
                    throw new TransportException('SQLSTATE[HY000] [2002] user:secret@db');
                }

                return $envelope;
            }
        };
        $dispatcher = new BoostPartitionDispatcher($this->bus($sender));

        try {
            $dispatcher->dispatch(self::RUN, 3);
            self::fail('A failed send was reported as success.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('partition 2 of 3', $failure->getMessage());
            self::assertStringNotContainsString('secret', $failure->getMessage());
        }
        self::assertSame(2, $sender->calls);
    }

    public function testRejectsAnEmptyRun(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new BoostPartitionDispatcher($this->bus(new InMemoryTransport())))->dispatch(self::RUN, 0);
    }

    public function testContainerExposesTheDispatcherToLegacyCode(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        try {
            self::assertInstanceOf(BoostPartitionDispatcher::class, $kernel->getContainer()->get(BoostPartitionDispatcher::class));
        } finally {
            $kernel->shutdown();
        }
    }

    private function bus(SenderInterface $sender): MessageBus
    {
        return new MessageBus([new SendMessageMiddleware(new SendersLocator([FlushBoostPartition::class => ['boost']], new ServiceLocator(['boost' => static fn() => $sender])))]);
    }
}
