<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\Port;

use Kadupul\Platform\Domain\NotificationReceiver;
use Kadupul\Platform\Domain\NotificationReceiverChange;

interface NotificationReceiverStore
{
    /** @return array<string, bool|int|string> a new receiver's settings, as legacy prefilled them */
    public function defaults(): array;

    public function find(int $id): ?NotificationReceiver;

    /**
     * Creates the receiver when $id is null; otherwise $revision must match the stored row.
     *
     * @throws \InvalidArgumentException when the change is refused, stale or the receiver is gone
     * @throws \Kadupul\Platform\Application\Query\NotificationReceiverAccessDenied
     */
    public function save(int $actorId, ?int $id, NotificationReceiverChange $change, ?string $revision): int;
}
