<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** Backed, because the values are the stable JSON "result" strings. */
enum TableResult: string
{
    case Converted = 'converted';
    case Failed = 'failed';
    case Planned = 'planned';
    case Skipped = 'skipped';
    case TooLarge = 'too_large';
}
