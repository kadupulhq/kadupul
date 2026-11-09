<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\RuleRepository;
use Kadupul\Alerting\Domain\Rule\AlertRule;

final class InMemoryRuleRepository implements RuleRepository
{
    /** @var array<int, AlertRule> */
    private array $rules = [];
    /** @var list<int> */
    public array $orphaned = [];

    public function add(AlertRule ...$rules): void
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->id] = $rule;
        }
        ksort($this->rules);
    }

    public function batchAfter(int $afterRuleId, int $limit): array
    {
        $batch = [];
        foreach ($this->rules as $id => $rule) {
            if ($id > $afterRuleId && count($batch) < $limit) {
                $batch[] = $rule;
            }
        }

        return $batch;
    }

    public function orphanedRuleIds(): array
    {
        return $this->orphaned;
    }
}
