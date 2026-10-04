<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Application\Port\TableCatalog;
use Kadupul\Platform\Domain\Schema\TableStatus;
use PHPUnit\Framework\TestCase;

final class TableCatalogTest extends TestCase
{
    public function testNamesKeepTheirReadOrderAndStayStrings(): void
    {
        $status = new TableStatus('InnoDB', 'utf8mb4_unicode_ci', 'Dynamic', 0);

        // PHP turns the key "123" into an int; names() must still hand back a string.
        self::assertSame(['host', '123', 'Host'], new TableCatalog(['host' => $status, '123' => $status, 'Host' => $status])->names());
    }

    public function testAnEmptyCatalogHasNoNames(): void
    {
        self::assertSame([], new TableCatalog([])->names());
    }
}
