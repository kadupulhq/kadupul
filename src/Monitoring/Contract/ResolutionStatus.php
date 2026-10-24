<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Monitoring\Contract;

enum ResolutionStatus: string
{
    case Resolved = 'resolved';
    case NoBinding = 'no_binding';
    case Ambiguous = 'ambiguous';
    case NotApproved = 'not_approved';
}
