<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Management;

final readonly class CatalogPage
{
    /**
     * @param list<array<string, string|int|float|null>> $records
     * @param array<string, int|string>|null $operationParameters
     */
    public function __construct(public array $records, public ?string $parentName = null, public ?array $operationParameters = null) {}
}
