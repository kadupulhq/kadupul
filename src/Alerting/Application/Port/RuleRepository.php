<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Rule\AlertRule;

interface RuleRepository
{
    /**
     * Up to $limit rules with an id greater than $afterRuleId, in ascending id
     * order, leaving out rules whose series no longer exists (those are
     * orphanedRuleIds()' to end, never evaluated again).
     *
     * @return list<AlertRule>
     */
    public function batchAfter(int $afterRuleId, int $limit): array;

    /**
     * Rules that need orphan handling, in ascending id order: a rule whose
     * data source, device or RRD data source no longer exists and that still
     * has a stored state or an active incident, and the id of a deleted rule
     * that still has either. A deleted series is not "missing data": the rate
     * source cannot tell them apart, so this reads the definitions instead.
     *
     * @return list<int>
     */
    public function orphanedRuleIds(): array;
}
