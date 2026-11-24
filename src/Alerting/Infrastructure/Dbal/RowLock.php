<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Infrastructure\Dbal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\SQLitePlatform;

/**
 * The row lock suffix for a SELECT inside the rule's transaction. MariaDB and
 * MySQL (InnoDB) lock the selected rows until the transaction ends. SQLite,
 * used only by the store tests, has no row locks and no FOR UPDATE.
 */
final class RowLock
{
    public static function forUpdate(Connection $database): string
    {
        return $database->getDatabasePlatform() instanceof SQLitePlatform ? '' : ' FOR UPDATE';
    }
}
