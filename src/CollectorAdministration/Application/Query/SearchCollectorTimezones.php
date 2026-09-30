<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Query;

use Kadupul\CollectorAdministration\Application\Port\CollectorTimezones;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class SearchCollectorTimezones
{
    public function __construct(private ConsoleAccess $access, private CollectorTimezones $timezones) {}

    /** @return list<array{label: string, value: string}> */
    public function __invoke(string $term): array
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied($actor === null);
        }
        return $this->timezones->search($term);
    }
}
