<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\Monitoring\Fakes;

use Kadupul\IdentityAccess\Contract\AuditEvent;
use Kadupul\IdentityAccess\Contract\AuditTrail;

final class RecordingAuditTrail implements AuditTrail
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public bool $failing = false;

    #[\Override]
    public function record(AuditEvent $event): void
    {
        if ($this->failing) {
            throw new \RuntimeException('audit sink unavailable');
        }
        $this->events[] = $event;
    }
}
