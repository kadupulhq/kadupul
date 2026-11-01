<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\PollerOutputDrain;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;

/** A drain that records its calls, relays the lines it is told to, and ends as it is told to. */
final class InMemoryPollerOutputDrain implements PollerOutputDrain
{
    public int $drained = 0;

    /** @param list<array{string, string}> $printed stream and text relayed before it ends */
    public function __construct(private readonly PollerOutputDrainResult $result = new PollerOutputDrainResult(PollerOutputDrainResult::OK, 3), private readonly array $printed = [], private readonly bool $fails = false) {}

    #[\Override]
    public function drain(?\Closure $relay = null): PollerOutputDrainResult
    {
        ++$this->drained;
        foreach ($this->printed as [$stream, $text]) {
            if ($relay !== null) {
                $relay($stream, $text);
            }
        }
        if ($this->fails) {
            throw new \RuntimeException('The poller output outcome is unknown.');
        }

        return $this->result;
    }
}
