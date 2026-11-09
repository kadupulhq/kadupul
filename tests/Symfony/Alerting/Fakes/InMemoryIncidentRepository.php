<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\IncidentRepository;

final class InMemoryIncidentRepository implements IncidentRepository
{
    /** @var list<array{string, int, int}> action, rule id, observation time */
    public array $events = [];

    public function open(int $ruleId, int $observedAt): void
    {
        $this->events[] = ['open', $ruleId, $observedAt];
    }

    public function close(int $ruleId, int $observedAt): void
    {
        $this->events[] = ['close', $ruleId, $observedAt];
    }
}
