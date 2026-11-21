<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Port;

use Kadupul\Alerting\Application\ReadModel\IncidentDetail;
use Kadupul\Alerting\Application\ReadModel\IncidentPage;

/**
 * Incidents the actor may see: those whose rule still exists and whose data
 * source the actor can view or no longer exists, as RuleCatalog. An incident
 * of a deleted rule names no series whose visibility could be established,
 * so it is not returned.
 */
interface IncidentCatalog
{
    public function rowsPerPage(): int;

    public function page(int $actorId, bool $activeOnly, int $page): IncidentPage;

    /** @param int $now for the notification status (NotificationView) */
    public function find(int $actorId, int $incidentId, int $now): ?IncidentDetail;
}
