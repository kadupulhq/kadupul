<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\DataInput\Infrastructure\Legacy;

use Kadupul\DataInput\Application\Port\DataQueryAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\DatabaseConnection;

/**
 * The Data Input Method account and realm rules with the Data Queries realm,
 * which include/global_arrays.php assigns to data_queries.php.
 */
final readonly class LegacyDataQueryAccess implements DataQueryAccess
{
    public const int REALM_ID = 13;

    private LegacyDataInputAccess $access;

    public function __construct(ConsoleAccess $console, DatabaseConnection $database)
    {
        $this->access = new LegacyDataInputAccess($console, $database, self::REALM_ID);
    }

    #[\Override]
    public function authorize(): Actor
    {
        return $this->access->authorize();
    }
}
