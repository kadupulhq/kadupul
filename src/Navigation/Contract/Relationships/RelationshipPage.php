<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Contract\Relationships;

final readonly class RelationshipPage
{
    /** @param list<array<string, string|int|float|null>> $records */
    public function __construct(public string $parentName, public array $records) {}
}
