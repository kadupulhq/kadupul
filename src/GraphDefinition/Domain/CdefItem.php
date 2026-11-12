<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Domain;

/** One item of a CDEF: a function, operator, special data source, another CDEF or a custom string. */
final readonly class CdefItem
{
    /** @param string $label Display text: the function, operator or CDEF name, or the stored value. */
    public function __construct(
        public int $id,
        public int $sequence,
        public int $type,
        public string $value,
        public string $label,
    ) {}
}
