<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('boost')]
final class BoostSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(private readonly LegacyConfiguration $configuration, private readonly BoostState $state, private readonly ?string $enabled) {}

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

        // The tick is not the Boost interval: the master applies boost_time_to_run()
        // and exits when no run is due, so a one-minute tick keeps the configured cadence.
        return $this->schedule = (new Schedule())
            ->add(RecurringMessage::every('1 minute', new BoostRun()))
            ->stateful($this->state->cache())
            ->processOnlyLastMissedRun(true)
            ->lock($this->state->lock());
    }
}
