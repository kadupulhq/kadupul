<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

enum GrantOutcome: string
{
    case Granted = 'granted';
    /** A dry run: every check passed and the grant would be made. */
    case Planned = 'planned';
    /** Every check passed but no user was named, so nothing was granted. */
    case NoUser = 'no-user';
    case UserInvalid = 'user-invalid';
    case ItemTypeMissing = 'item-type-missing';
    case ItemIdMissing = 'item-id-missing';
    case ItemInvalid = 'item-invalid';
    /** The write was refused or failed. */
    case NotGranted = 'not-granted';
}
