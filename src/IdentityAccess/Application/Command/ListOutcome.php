<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Application\Command;

/** How a permission-target listing ended. */
enum ListOutcome: string
{
    case Listed = 'listed';
    /** --list-graphs without a device that exists. */
    case HostInvalid = 'host-invalid';
    case NotImplemented = 'not-implemented';
}
