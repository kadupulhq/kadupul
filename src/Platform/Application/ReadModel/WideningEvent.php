<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** Backed, because the values are the stable JSON "result" strings. */
enum WideningEvent: string
{
    case AlreadyWide = 'already_wide';
    case Skipped = 'skipped';
    case MissingColumn = 'missing_column';
    case Planned = 'planned';
    case Widened = 'widened';
    case Failed = 'failed';
}
