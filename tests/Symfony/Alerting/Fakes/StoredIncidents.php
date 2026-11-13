<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Domain\Incident\Incident;
use Kadupul\Alerting\Infrastructure\Dbal\DbalIncidentRepository;

/** Reads alert_incident rows back for assertions, through the repository's own row mapping. */
final class StoredIncidents
{
    /**
     * Every incident of a rule, oldest first.
     *
     * @return list<Incident>
     */
    public static function forRule(Connection $database, int $ruleId): array
    {
        $rows = $database->fetchAllAssociative(
            'SELECT id, rule_id, status, active_rule_id, opened_at, ended_at, acknowledged_at, acknowledged_by
            FROM alert_incident WHERE rule_id = ? ORDER BY id',
            [$ruleId],
        );

        return array_map(DbalIncidentRepository::fromRow(...), $rows);
    }
}
