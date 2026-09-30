<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** How the canonical baseline was read. Values are stable JSON strings. */
enum BaselineOutcome: string
{
    case Loaded = 'loaded';
    /** A dry run read the file and loaded nothing. */
    case Planned = 'planned';
    case FileMissing = 'file_missing';
    case Unparsable = 'unparsable';
}
