<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/** The entries of one set in discovery order, with the order digest a move must match. */
final readonly class SnmpOptionEntries
{
    /** @param list<SnmpOptionEntry> $entries */
    public function __construct(
        public array $entries,
        public string $order,
    ) {}

    public function first(): ?int
    {
        return $this->entries === [] ? null : $this->entries[0]->id;
    }

    public function last(): ?int
    {
        return $this->entries === [] ? null : $this->entries[count($this->entries) - 1]->id;
    }
}
