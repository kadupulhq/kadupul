<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony;

use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('row_cache')]
final class RowCacheSchedule implements ScheduleProviderInterface
{
    private ?Schedule $schedule = null;

    public function __construct(private readonly LegacyConfiguration $configuration, private readonly string $stateDirectory, private readonly ?string $enabled) {}

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
        if (!is_dir($this->stateDirectory) && !mkdir($this->stateDirectory, 0700, true) && !is_dir($this->stateDirectory)) {
            throw new \RuntimeException('Cannot create scheduler state directory.');
        }
        $lock = (new LockFactory(new FlockStore($this->stateDirectory)))->createLock('row-cache');

        return $this->schedule = (new Schedule())
            ->add(RecurringMessage::every('5 minutes', new RowCacheCleanup()))
            ->stateful(new FilesystemAdapter('row-cache', 0, $this->stateDirectory))
            ->processOnlyLastMissedRun(true)
            ->lock($lock);
    }
}
