<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Command;

use Kadupul\CollectorAdministration\Application\Port\RemoteDatabaseProbe;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class TestCollectorConnection
{
    public function __construct(private ConsoleAccess $access, private RemoteDatabaseProbe $probe) {}

    /** @param array<string, mixed> $credentials */
    public function __invoke(#[\SensitiveParameter] array $credentials, ?int $collectorId = null, ?string $revision = null): bool
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied($actor === null);
        }
        return $this->probe->canConnect($actor->id, $credentials, $collectorId, $revision);
    }
}
