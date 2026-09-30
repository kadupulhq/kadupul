<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Query;

use Kadupul\CollectorAdministration\Application\Port\CollectorEditor;
use Kadupul\CollectorAdministration\Domain\CollectorDetails;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class FindCollector
{
    public function __construct(private ConsoleAccess $access, private CollectorEditor $editor) {}

    public function __invoke(int $id): ?CollectorDetails
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied($actor === null);
        }
        return $this->editor->find($id);
    }
}
