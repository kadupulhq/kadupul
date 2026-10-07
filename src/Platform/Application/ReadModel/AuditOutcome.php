<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Application\ReadModel;

enum AuditOutcome
{
    case Completed;
    /** The database version differs from the code and --upgrade was not given. */
    case UpgradeRequired;
    /** No mode was given, so the script printed its help. */
    case NoMode;
    /** The upgrade did not finish or the core upgrade failed, so the mode did not run. */
    case UpgradeFailed;
}
