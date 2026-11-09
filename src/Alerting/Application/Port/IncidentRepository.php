<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * Opens and ends the single active (open or acknowledged) incident of a rule
 * (ADR 0011 decision 6). Callers run these inside TransactionRunner with the
 * rule's state change.
 */
interface IncidentRepository
{
    /** @throws UnusableRuleRecord when the rule already has an active incident */
    public function open(int $ruleId, int $observedAt): void;

    /**
     * Closes the active incident by recovery.
     *
     * @throws UnusableRuleRecord when there is none
     */
    public function close(int $ruleId, int $observedAt): void;

    /**
     * Ends the rules' active incidents as orphaned, which is never a recovery.
     * It reads no rule state.
     *
     * @param list<int> $ruleIds
     *
     * @return int incidents ended; a rule with no active incident adds none
     */
    public function orphan(array $ruleIds, int $at): int;
}
