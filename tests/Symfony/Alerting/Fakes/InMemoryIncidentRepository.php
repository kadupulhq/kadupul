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
    /** @var array<int, int> the incident id of each active rule, once known */
    public array $activeIds = [];
    /** @var array<int, array{rule: int, opened: int, ended: ?int}> incidents by id */
    public array $incidents = [];
    public int $lastId = 0;
    /** Thrown by the next write, to model a failed insert or update. */
    public ?\Throwable $failure = null;

    public function open(int $ruleId, int $observedAt): int
    {
        $this->fail();
        if (isset($this->active[$ruleId])) {
            throw new UnusableRuleRecord($ruleId, 'it already has an active incident.');
        }
        $this->active[$ruleId] = true;
        $this->activeIds[$ruleId] = ++$this->lastId;
        $this->incidents[$this->lastId] = ['rule' => $ruleId, 'opened' => $observedAt, 'ended' => null];
        $this->events[] = ['open', $ruleId, $observedAt];

        return $this->lastId;
    }

    public function close(int $ruleId, int $observedAt): int
    {
        $this->fail();
        if (!isset($this->active[$ruleId])) {
            throw new UnusableRuleRecord($ruleId, 'it has no active incident to close.');
        }
        $id = $this->end($ruleId, $observedAt);
        $this->events[] = ['close', $ruleId, $observedAt];

        return $id;
    }

    public function orphan(array $ruleIds, int $at): int
    {
        $this->fail();
        $ended = 0;
        foreach ($ruleIds as $ruleId) {
            if (isset($this->active[$ruleId])) {
                $this->end($ruleId, $at);
                $this->events[] = ['orphan', $ruleId, $at];
                ++$ended;
            }
        }

        return $ended;
    }

    /** An incident a test marked active by rule id alone gets its id here. */
    private function end(int $ruleId, int $at): int
    {
        $id = $this->activeIds[$ruleId] ?? ++$this->lastId;
        $this->incidents[$id] = ['rule' => $ruleId, 'opened' => $this->incidents[$id]['opened'] ?? $at, 'ended' => $at];
        unset($this->active[$ruleId], $this->activeIds[$ruleId]);

        return $id;
    }

    private function fail(): void
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}
