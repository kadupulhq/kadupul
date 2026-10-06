<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/**
 * The table character sets the audit's repair keeps. repair_database() took
 * SUBSTRING_INDEX(TABLE_COLLATION, "_", 1) and wrote it after CHARSET=; only
 * names on this list are written, so a collation cannot supply other text.
 */
enum DefaultCharset: string
{
    case Utf8mb4 = 'utf8mb4';
    case Utf8mb3 = 'utf8mb3';
    case Utf8 = 'utf8';
    case Latin1 = 'latin1';
    case Ascii = 'ascii';
    case Binary = 'binary';

    /** The charset repair_database() derived: the collation up to its first "_", or utf8mb4 when the table had none. */
    public static function legacyName(?string $collation): string
    {
        return $collation === null ? 'utf8mb4' : explode('_', $collation, 2)[0];
    }
}
