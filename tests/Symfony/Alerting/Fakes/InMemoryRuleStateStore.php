<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\RuleStateStore;
use Kadupul\Alerting\Application\Port\UnusableRuleRecord;
use Kadupul\Alerting\Domain\Evaluation\RuleState;

final class InMemoryRuleStateStore implements RuleStateStore
{
    /** @var array<int, RuleState> */
    public array $states = [];
    /** @var list<int> rule ids in the order they were saved */
    public array $saved = [];
    /** @var array<int, string> stored rows that cannot be read, by rule id, with the reason */
    public array $unreadable = [];
    /** @var list<list<int>> the ids of each delete() call */
    public array $deleted = [];

    public function load(array $ruleIds): array
    {
        $found = [];
        foreach ($ruleIds as $id) {
            if (isset($this->unreadable[$id])) {
                $found[$id] = new UnusableRuleRecord($id, $this->unreadable[$id]);
            } elseif (isset($this->states[$id])) {
                // A copy, so only save() changes what is stored.
                $found[$id] = clone $this->states[$id];
            }
        }

        return $found;
    }

    public function save(int $ruleId, RuleState $state): void
    {
        $this->states[$ruleId] = clone $state;
        $this->saved[] = $ruleId;
    }

    public function delete(array $ruleIds): void
    {
        $this->deleted[] = $ruleIds;
        foreach ($ruleIds as $id) {
            unset($this->states[$id], $this->unreadable[$id]);
        }
    }
}
