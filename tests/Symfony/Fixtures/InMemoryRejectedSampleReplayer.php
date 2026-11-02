<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\RejectedSampleReplayer;
use Kadupul\Graphing\Application\Port\RejectedSampleReplayResult;

/** A replayer that records its calls, relays the text it is told to, and ends as it is told to. */
final class InMemoryRejectedSampleReplayer implements RejectedSampleReplayer
{
    /** @var list<array{?int, bool}> */
    public array $calls = [];

    /** @param list<array{string, string}> $printed stream and text relayed before it ends */
    public function __construct(private readonly RejectedSampleReplayResult $result = new RejectedSampleReplayResult(RejectedSampleReplayResult::OK, 5), private readonly array $printed = [], private readonly bool $fails = false) {}

    #[\Override]
    public function replay(?int $localDataId, bool $dryRun, ?\Closure $relay = null): RejectedSampleReplayResult
    {
        $this->calls[] = [$localDataId, $dryRun];
        foreach ($this->printed as [$stream, $text]) {
            if ($relay !== null) {
                $relay($stream, $text);
            }
        }
        if ($this->fails) {
            throw new \RuntimeException('The rejected sample replay outcome is unknown.');
        }

        return $this->result;
    }
}
