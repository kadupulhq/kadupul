<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** What audit_database.php was asked to do. Backed, because the values are the stable JSON "mode" strings. */
enum AuditMode: string
{
    case Repair = 'repair';
    case Create = 'create';
    case Report = 'report';
    case Alters = 'alters';
    case Load = 'load';

    /**
     * The one the script ran when several were given (audit_database.php:101-113).
     *
     * @param list<self> $given
     */
    public static function first(array $given): ?self
    {
        return array_find(self::cases(), static fn(self $mode): bool => in_array($mode, $given, true));
    }
}
