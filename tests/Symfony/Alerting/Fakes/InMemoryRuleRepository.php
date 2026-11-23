<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Rule\AlertRule;

final class InMemoryRuleRepository implements RuleRepository
{
    /** @var array<int, AlertRule> */
    private array $rules = [];
    /**
     * Orphan candidates. Like the database query, only those that still have
     * a state, readable or not, or an active incident are returned.
     *
     * @var list<int>
     */
    public array $orphaned = [];
    /** @var array<int, string> rule rows that cannot be read, by id, with the reason */
    public array $unusable = [];
    /** @var list<int> the limit of each orphanedRuleIds() call */
    public array $orphanLimits = [];
    /** @var array<int, true> rules deleted after batchAfter() read them, before their transaction */
    public array $deletedMidRun = [];
    /** @var array<int, true> rules whose data source, device or RRD name was deleted after batchAfter() read them */
    public array $seriesGoneMidRun = [];
    /** @var list<int> the rule id of each lock() call */
    public array $locked = [];
    /**
     * Runs once at the first lock() of each rule id it names, before the
     * lock is taken, as an edit another session commits between batchAfter()
     * and the rule's transaction would.
     *
     * @var array<int, \Closure(): void>
     */
    public array $beforeLock = [];

    public function __construct(
        private readonly ?InMemoryRuleStateStore $states = null,
        private readonly ?InMemoryIncidentRepository $incidents = null,
    ) {}

    public function add(AlertRule ...$rules): void
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->id] = $rule;
        }
        ksort($this->rules);
    }

    public function batchAfter(int $afterRuleId, int $limit): array
    {
        $all = $this->rules;
        foreach ($this->unusable as $id => $reason) {
            $all[$id] = new UnusableRuleRecord($id, $reason);
        }
        ksort($all);
        $batch = [];
        foreach ($all as $id => $rule) {
            if ($id > $afterRuleId && count($batch) < $limit) {
                $batch[] = $rule;
            }
        }

        return $batch;
    }

    public function lock(int $ruleId): AlertRule|UnusableRuleRecord|null
    {
        $this->locked[] = $ruleId;
        if (isset($this->beforeLock[$ruleId])) {
            $edit = $this->beforeLock[$ruleId];
            unset($this->beforeLock[$ruleId]);
            $edit();
        }

        if (isset($this->deletedMidRun[$ruleId]) || isset($this->seriesGoneMidRun[$ruleId])) {
            return null;
        }
        if (isset($this->unusable[$ruleId])) {
            return new UnusableRuleRecord($ruleId, $this->unusable[$ruleId]);
        }

        return $this->rules[$ruleId] ?? null;
    }

    public function orphanedRuleIds(int $limit): array
    {
        $this->orphanLimits[] = $limit;
        $held = array_filter($this->orphaned, fn(int $id): bool => $this->states === null
            || isset($this->states->states[$id]) || isset($this->states->unreadable[$id]) || isset($this->incidents?->active[$id]));
        sort($held);

        return array_slice($held, 0, $limit);
    }
}
