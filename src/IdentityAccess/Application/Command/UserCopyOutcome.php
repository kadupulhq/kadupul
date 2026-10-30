<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

enum UserCopyOutcome: string
{
    case Copied = 'copied';
    /** A dry run: the copy would be attempted. */
    case Planned = 'planned';
    case TemplateMissing = 'template-missing';
    /** The new username is already taken; the copy refuses to overwrite. */
    case NewUserExists = 'new-user-exists';
    /** The copy ran and failed, or it reported success but the new account is absent. */
    case NotCopied = 'not-copied';
}
