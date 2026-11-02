<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Fixtures;

use Kadupul\Graphing\Application\Port\PollerCacheOutcome;
use Kadupul\Graphing\Application\Port\PollerCacheRebuilder;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\Graphing\Application\Port\PollerCacheSettings;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/**
 * A setting of "4" and a rebuilder that records each request, prints the
 * lines it is given through the progress closure, and ends with the given
 * outcome, or throws when it has none.
 */
final class InMemoryPollerCache implements PollerCacheSettings, PollerCacheRebuilder
{
    /** @var list<PollerCacheRequest> */
    public array $requests = [];

    /** @var list<int> */
    public array $signals = [];

    public int $settingReads = 0;

    /** @param list<array{string, string}> $printed */
    public function __construct(private readonly ?PollerCacheOutcome $outcome = new PollerCacheOutcome(PollerCacheOutcome::REBUILT, 2), private readonly array $printed = [], public bool $running = false) {}

    #[\Override]
    public function concurrentProcesses(OperatorDatabase $database): string
    {
        $this->settingReads++;

        return '4';
    }

    #[\Override]
    public function rebuild(OperatorDatabase $database, PollerCacheRequest $request, ?\Closure $progress = null): PollerCacheOutcome
    {
        $this->requests[] = $request;
        foreach ($this->printed as [$type, $text]) {
            if ($progress !== null) {
                $progress($type, $text);
            }
        }
        if ($this->outcome === null) {
            throw new \RuntimeException('The poller cache rebuild failed.');
        }

        return $this->outcome;
    }

    #[\Override]
    public function interrupt(int $signal): bool
    {
        $this->signals[] = $signal;

        return $this->running;
    }
}
