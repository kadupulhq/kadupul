<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\IncidentRepository;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;

final class InMemoryIncidentRepository implements IncidentRepository
{
    /** @var list<array{string, int, int}> action, rule id, time */
    public array $events = [];
    /** @var array<int, true> rules with an open or acknowledged incident */
    public array $active = [];
    /** Thrown by the next write, to model a failed insert or update. */
    public ?\Throwable $failure = null;

    public function open(int $ruleId, int $observedAt): void
    {
        $this->fail();
        if (isset($this->active[$ruleId])) {
            throw new UnusableRuleRecord($ruleId, 'it already has an active incident.');
        }
        $this->active[$ruleId] = true;
        $this->events[] = ['open', $ruleId, $observedAt];
    }

    public function close(int $ruleId, int $observedAt): void
    {
        $this->fail();
        if (!isset($this->active[$ruleId])) {
            throw new UnusableRuleRecord($ruleId, 'it has no active incident to close.');
        }
        unset($this->active[$ruleId]);
        $this->events[] = ['close', $ruleId, $observedAt];
    }

    public function orphan(array $ruleIds, int $at): int
    {
        $this->fail();
        $ended = 0;
        foreach ($ruleIds as $ruleId) {
            if (isset($this->active[$ruleId])) {
                unset($this->active[$ruleId]);
                $this->events[] = ['orphan', $ruleId, $at];
                ++$ended;
            }
        }

        return $ended;
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
