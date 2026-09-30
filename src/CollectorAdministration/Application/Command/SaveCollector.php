<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Application\Command;

use Kadupul\CollectorAdministration\Application\Port\CollectorEditor;
use Kadupul\CollectorAdministration\Application\Query\CollectorAccessDenied;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;

final readonly class SaveCollector
{
    public function __construct(private ConsoleAccess $access, private CollectorEditor $editor) {}

    /** @param array<string, mixed> $values */
    public function __invoke(?int $id, array $values): int
    {
        $actor = $this->access->consoleActor();
        if ($actor === null || !$this->access->canManageDevices($actor)) {
            throw new CollectorAccessDenied($actor === null);
        }
        return $this->editor->save($actor->id, $id, $values);
    }
}
