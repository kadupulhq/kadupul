<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Kadupul\Graphing\Application\Port\GraphTemplateInputAccess;
use Kadupul\Graphing\Application\Query\GraphTemplateInputAccessDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(GraphTemplateInputAccess::class)]
final readonly class DbalGraphTemplateInputAccess implements GraphTemplateInputAccess
{
    /** include/global_arrays.php maps graph_templates_inputs.php to realm 10. */
    private const int TEMPLATES_REALM = 10;

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
            throw new GraphTemplateInputAccessDenied(true);
        }
        if (!$this->policy->allowsRead($actor->id, self::TEMPLATES_REALM)) {
            throw new GraphTemplateInputAccessDenied(false);
        }
        return $actor;
    }

    public function assertCurrent(int $actorId): void
    {
        $this->policy->assertTransaction();
        $actor = $this->console->consoleActor();
        if ($actor === null || $actor->id !== $actorId || !$this->policy->allowsWrite($actorId, self::TEMPLATES_REALM)) {
            throw new GraphTemplateInputAccessDenied($actor === null);
        }
    }
}
