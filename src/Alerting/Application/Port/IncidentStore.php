<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\Query\AcknowledgeOutcome;

interface IncidentStore
{
    /**
     * Acknowledges an open incident the actor can see (IncidentCatalog), by
     * the actor, at $at, in one audited transaction that rechecks the actor
     * (IncidentAccess::assertCurrent()) and locks the rule row, then the
     * incident row, in the evaluator's order. An incident already
     * acknowledged keeps its first acknowledgement.
     */
    public function acknowledge(int $actorId, int $incidentId, int $at): AcknowledgeOutcome;
}
