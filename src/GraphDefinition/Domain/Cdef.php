<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

final readonly class Cdef
{
    /** @param list<CdefItem> $items in sequence order */
    public function __construct(
        public int $id,
        public string $name,
        public string $revision,
        public array $items,
        public string $preview,
    ) {}

    public function item(int $id): ?CdefItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }

        return null;
    }
}
