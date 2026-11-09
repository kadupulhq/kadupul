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
     *
     * @param list<int> $ruleIds
     *
     * @return array<int, RuleState>
     */
    public function load(array $ruleIds): array;

    public function save(int $ruleId, RuleState $state): void;

    /** Forgets a rule's state; a rule with no stored state starts closed with no counts. */
    public function delete(int $ruleId): void;
}
