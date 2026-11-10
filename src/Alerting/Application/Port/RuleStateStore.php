<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Domain\Evaluation\RuleState;

interface RuleStateStore
{
    /**
     * Stored states keyed by rule id; a rule with no stored state is absent.
     * A row that cannot be read is returned as an UnusableRuleRecord for that
     * rule, so it does not hide the others.
     *
     * @param list<int> $ruleIds
     *
     * @return array<int, RuleState|UnusableRuleRecord>
     */
    public function load(array $ruleIds): array;

    /**
     * Inside the rule's transaction, after RuleRepository::lock(): reads the
     * rule's stored state with a row lock until the transaction ends. Null
     * when the rule has no stored state; an unreadable row is returned as
     * its UnusableRuleRecord.
     */
    public function lock(int $ruleId): RuleState|UnusableRuleRecord|null;

    public function save(int $ruleId, RuleState $state): void;

    /**
     * Forgets the rules' states without reading them, so an unreadable row is
     * removed too; a rule with no stored state starts closed with no counts.
     *
     * @param list<int> $ruleIds
     */
    public function delete(array $ruleIds): void;
}
