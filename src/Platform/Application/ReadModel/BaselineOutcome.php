<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

/** How create_tables() ended. Backed, because the values are the stable JSON "baseline" strings. */
enum BaselineOutcome: string
{
    case Loaded = 'loaded';
    /** A dry run read the file and loaded nothing. */
    case Planned = 'planned';
    case FileMissing = 'file_missing';
    case Unparsable = 'unparsable';
    case LoadFailed = 'load_failed';
    /** A table could not be created; the script stopped there. */
    case CreateFailed = 'create_failed';
}
