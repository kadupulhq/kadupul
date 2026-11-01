<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Platform\Contract\LegacyWorkerRunner;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/** Scheduler handler that runs the Boost master in the legacy worker when Boost scheduling is enabled. */
#[AsMessageHandler]
final readonly class BoostRunHandler
{
    public function __construct(private LegacyWorkerRunner $worker, private ?string $enabled) {}

    /**
     * @throws \RuntimeException when Boost scheduling is disabled
     * @throws UnrecoverableMessageHandlingException when the master run fails; it is not retried
     */
    public function __invoke(BoostRun $message): void
    {
        if ($this->enabled !== '1') {
            throw new \RuntimeException('Boost scheduling is disabled.');
        }
        // No retry: the next tick runs the master again, and it applies its own due check.
        if (!$this->run(false, false)['ok']) {
            throw new UnrecoverableMessageHandlingException('Boost master run failed.');
        }
    }

    /**
     * No deadline here: bin/legacy-boost-master.php stops a master that outlives three times the update window.
     *
     * @param ?\Closure(string, string): void $progress
     * @return array{ok: bool}
     */
    public function run(bool $force, bool $debug, ?\Closure $progress = null): array
    {
        $run = $this->worker->run('legacy-boost-master.php', 'BOOST_MASTER', ['force' => $force, 'debug' => $debug], null, $progress);

        return ['ok' => $run['ok']];
    }
}
