<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** One MODIFY COLUMN to int(10) unsigned. */
final readonly class ColumnChange
{
    public function __construct(public string $name, public bool $autoIncrement, public bool $nullable, public ?string $default) {}
}
