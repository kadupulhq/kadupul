<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

/** The realm a command needs besides console access, named for the web page it stands in for. */
enum ManagedArea
{
    /** Realm 5: graphs.php and graphs_new.php. */
    case Graphs;

    /** Realm 3: host.php. */
    case Devices;

    /** Realm 4: tree.php. */
    case Trees;
}
