<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** What a run does with the graphs it selects: count them, list them, or remove them. */
enum GraphRemovalMode
{
    case Count;
    case Listing;
    case Remove;
}
