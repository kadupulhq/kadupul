<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Domain\Incident;

/**
 * ADR 0011 decision 6. Stored as the lower-case names in alert_incident.status.
 * Open and Acknowledged are active: a rule has at most one active incident.
 * Closed ends an incident by recovery; Orphaned ends it because its rule or
 * series no longer exists, and is never reported as a recovery.
 */
enum IncidentStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Closed = 'closed';
    case Orphaned = 'orphaned';

    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Acknowledged;
    }
}
