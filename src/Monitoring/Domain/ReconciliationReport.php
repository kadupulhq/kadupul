<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Domain;

/**
 * Current approved or active plans compared with a fresh proposal, per pack.
 * It describes; acting on it goes through approval and activation.
 */
final readonly class ReconciliationReport
{
    /**
     * @param list<CollectionPlan> $added proposed plans for packs with no current plan
     * @param list<CollectionPlan> $removed current plans whose pack no longer applies
     * @param list<PlanChange> $changed
     * @param list<CollectionPlan> $unchanged current plans the proposal matches exactly
     */
    public function __construct(
        public PlanningProposal $proposal,
        public array $added,
        public array $removed,
        public array $changed,
        public array $unchanged,
    ) {}
}
