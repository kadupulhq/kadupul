<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Kadupul\Alerting\Application\Port\RuleAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

/** The Alert Rules realm (29) on the alerting stores' connection. */
final readonly class DbalRuleAccess implements RuleAccess
{
    private const int RULES_REALM = 29;

    private AlertingRealmCheck $check;

    public function __construct(ConsoleAccess $console, Connection $database)
    {
        $this->check = new AlertingRealmCheck($console, $database);
    }

    public function authorize(): Actor
    {
        return $this->check->authorize(self::RULES_REALM);
    }

    public function assertCurrent(int $actorId): void
    {
        $this->check->assertCurrent($actorId, self::RULES_REALM);
    }
}
