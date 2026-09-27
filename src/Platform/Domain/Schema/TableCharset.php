<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The only character sets the conversion writes into DDL. */
enum TableCharset: string
{
    case Utf8mb4 = 'utf8mb4';
    case Latin1 = 'latin1';

    public function clause(): string
    {
        return match ($this) {
            self::Utf8mb4 => 'CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            self::Latin1 => 'CONVERT TO CHARACTER SET latin1',
        };
    }
}
