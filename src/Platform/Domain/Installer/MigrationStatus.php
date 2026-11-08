<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain\Installer;

/** Values preserve include/global_constants.php and native upgrade cache consumers. */
enum MigrationStatus: int
{
    /** The native operation failed. */
    case Error = 0;
    /** The native schema operation needs operator attention. */
    case Warning = 1;
    /** The native migration requests a restart. */
    case Restart = 2;
    /** The native operation confirmed success. */
    case Success = 3;
    /** The requested schema change was already satisfied or absent. */
    case Skipped = 4;
}
