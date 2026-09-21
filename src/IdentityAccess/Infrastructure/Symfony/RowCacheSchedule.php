<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('row_cache')]
final class RowCacheSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(private readonly LegacyConfiguration $configuration, private readonly RowCacheState $state, private readonly ?string $enabled) {}

    public function getSchedule(): Schedule
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }
        if ($this->enabled !== '1') {
            return $this->schedule = new Schedule();
        }
        // The installation adapter rejects remote collectors before scheduling.
        $this->configuration->values();
        return $this->schedule = (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new RowCacheCleanup()))
            ->stateful($this->state->cache())
            ->processOnlyLastMissedRun(true)
            ->lock($this->state->lock());
    }
}
