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
     * orphanedRuleIds()' to end, never evaluated again). A rule whose row
     * cannot be read is returned in its place as an UnusableRuleRecord, so
     * one bad row neither hides the other rules nor holds the cursor.
     *
     * @return list<AlertRule|UnusableRuleRecord>
     */
    public function batchAfter(int $afterRuleId, int $limit): array;

    /**
     * Inside the rule's transaction, before its state is written: locks the
     * rule row until the transaction ends, so the rule cannot be deleted
     * while its state, incident and notification are written. False when the
     * rule is already gone, for example deleted after batchAfter() read it;
     * the caller then writes nothing. Lock order is rule, then state, then
     * incident, then notification.
     */
    public function lock(int $ruleId): bool;

    /**
     * Up to $limit rules that need orphan handling, in ascending id order: a rule whose
     * data source, device or RRD data source no longer exists and that still
     * has a stored state or an active incident, and the id of a deleted rule
     * that still has either. A deleted series is not "missing data": the rate
     * source cannot tell them apart, so this reads the definitions instead.
     * A handled orphan has neither left, so the next call returns the next ones.
     *
     * @return list<int>
     */
    public function orphanedRuleIds(int $limit): array;
}
