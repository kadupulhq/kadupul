<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\FlushBoostPartition;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/** Queues the partitions of one Boost master run for Messenger consumers. */
final readonly class BoostPartitionDispatcher
{
    public function __construct(private MessageBusInterface $bus) {}

    /**
     * @throws \RuntimeException when a message cannot be queued. Messages queued
     *     before the failure stay queued; the master clears its active run, so a
     *     consumer that receives one acknowledges it as stale.
     */
    public function dispatch(string $runId, int $partitions): void
    {
        if ($partitions < 1) {
            throw new \InvalidArgumentException('A Boost run has at least one partition.');
        }
        for ($partition = 1; $partition <= $partitions; ++$partition) {
            try {
                $this->bus->dispatch(new FlushBoostPartition($runId, $partition));
            } catch (ExceptionInterface $failure) {
                // The transport error can carry the DSN, which holds credentials.
                throw new \RuntimeException(sprintf('Boost partition %d of %d could not be queued (%s).', $partition, $partitions, $failure::class));
            }
        }
    }
}
