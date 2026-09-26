<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Builds the visibility predicate from policies read on the same connection
 * that runs the query embedding it. Locked write checks stay on
 * LegacyDeviceVisibility inside their PDO transactions.
 */
final readonly class DoctrineDeviceVisibility
{
    public function __construct(private Connection $database) {}

    public function predicate(int $userId): string
    {
        $mode = DeviceVisibilityRules::mode($this->database->fetchOne(DeviceVisibilityRules::MODE));

        return DeviceVisibilityRules::predicate($mode, $this->database->fetchAllAssociative(DeviceVisibilityRules::POLICIES, [$userId, $userId]));
    }
}
