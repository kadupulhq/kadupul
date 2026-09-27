<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;

/** Records one event per affected site after the site transaction resolves. */
final readonly class SiteWriteAudit
{
    public function __construct(private AuditTrail $trail) {}

    /** @param list<int|string> $siteIds */
    public function record(int $actorId, string $action, array $siteIds, string $decision, string $outcome): void
    {
        // One identifier ties together the records of a single bulk operation.
        $correlationId = bin2hex(random_bytes(16));
        foreach ($siteIds as $siteId) {
            try {
                $this->trail->record(new AuditEvent($correlationId, $actorId, $action, 'site', (string) $siteId, $decision, $outcome));
            } catch (\Throwable) {
                // The transitional sink must not replace the resolved write result.
            }
        }
    }
}
