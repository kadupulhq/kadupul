<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The EXTRA values make_column_props() appended that are valid DDL. */
enum ColumnExtra: string
{
    case None = '';
    case AutoIncrement = 'AUTO_INCREMENT';
    case OnUpdateNow = 'ON UPDATE CURRENT_TIMESTAMP';

    /** The text make_column_props() appended, or null when it is not one of these (the baseline's "1", for one). */
    public static function fromLegacy(string $extra): ?self
    {
        return self::tryFrom(strtoupper($extra));
    }
}
