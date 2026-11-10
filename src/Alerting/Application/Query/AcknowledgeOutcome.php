<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Alerting\Application\Query;

enum AcknowledgeOutcome
{
    case Acknowledged;
    case AlreadyAcknowledged;
    /** Closed or orphaned; nothing was written. */
    case Ended;
    /** No such incident, or one the actor cannot see. */
    case NotFound;
}
