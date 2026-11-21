<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Query\DataSourceProfileAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(DataSourceProfileAccess::class)]
final readonly class DbalDataSourceProfileAccess implements DataSourceProfileAccess
{
    private const int PROFILE_REALM = 9;

    private DbalRealmPolicy $policy;

    public function __construct(
        private ConsoleAccess $console,
        #[Autowire(service: 'doctrine.dbal.web_connection')]
        Connection $database,
    ) {
        $this->policy = new DbalRealmPolicy($database);
    }

    public function authorize(): Actor
    {
        $actor = $this->console->consoleActor();
        if ($actor === null) {
            throw new DataSourceProfileAccessDenied(true);
        }
        if (!$this->policy->allowsRead($actor->id, self::PROFILE_REALM)) {
            throw new DataSourceProfileAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        $this->policy->assertTransaction();
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->policy->allowsWrite($actorId, self::PROFILE_REALM)) {
            throw new DataSourceProfileAccessDenied($actor === null);
        }
    }
}
