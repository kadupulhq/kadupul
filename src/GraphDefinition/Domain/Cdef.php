<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** A CDEF (a named RPN expression that transforms graph data) with its items, revision and expanded preview. */
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

    /** The item with this id, or null when the CDEF has none. */
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
