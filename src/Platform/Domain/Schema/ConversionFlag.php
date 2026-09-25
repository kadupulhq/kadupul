<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Schema;

/** The on/off flags of convert_tables.php. */
enum ConversionFlag
{
    case Innodb;
    case Utf8;
    case Latin1;
    case Rebuild;
    case Dynamic;
    case Force;
}
