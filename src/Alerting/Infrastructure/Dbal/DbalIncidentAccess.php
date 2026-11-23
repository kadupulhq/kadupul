<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\IncidentAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

/** The Alert Incidents realm (30) on the alerting stores' connection. */
final readonly class DbalIncidentAccess implements IncidentAccess
{
    private const int INCIDENTS_REALM = 30;

    private AlertingRealmCheck $check;

    public function __construct(ConsoleAccess $console, Connection $database)
    {
        $this->check = new AlertingRealmCheck($console, $database);
    }

    public function authorize(): Actor
    {
        return $this->check->authorize(self::INCIDENTS_REALM);
    }

    public function assertCurrent(int $actorId): void
    {
        $this->check->assertCurrent($actorId, self::INCIDENTS_REALM);
    }
}
