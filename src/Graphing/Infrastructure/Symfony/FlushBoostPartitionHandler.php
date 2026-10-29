<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Graphing\Application\Command\FlushBoostPartition;
use Kadupul\Graphing\Application\Port\BoostPartitionWriter;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class FlushBoostPartitionHandler
{
    /**
     * @param int $timeout seconds. The worker enforces its own deadline on the
     *     child; this is the outer guard so a hung worker cannot hold the
     *     consumer forever.
     */
    public function __construct(private BoostPartitionWriter $writer, private int $timeout) {}

    public function __invoke(FlushBoostPartition $message): void
    {
        // A message restored by the serializer does not pass the constructor.
        if (preg_match(FlushBoostPartition::RUN_PATTERN, $message->runId) !== 1 || $message->partition < 1) {
            throw new UnrecoverableMessageHandlingException('Malformed Boost partition message.');
        }
        $result = $this->writer->flush($message->runId, $message->partition, (float) $this->timeout);
        // No retry: the master counts a partition without an ok status as failed.
        if (!$result->ok) {
            throw new UnrecoverableMessageHandlingException(sprintf('Boost partition %d failed (%s).', $message->partition, $result->detail));
        }
    }
}
