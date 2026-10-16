<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

final readonly class ConsoleCollectorStatus
{
    public function __construct(public string $connection, public ?int $queuedRecords)
    {
        if (!in_array($connection, ['online', 'recovery', 'offline'], true)
            || ($connection === 'online' && $queuedRecords !== null)
            || ($queuedRecords !== null && $queuedRecords < 0)) {
            throw new \InvalidArgumentException('Invalid collector status.');
        }
    }
}
