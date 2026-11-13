<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\TransactionRunner;

/** Restores the fakes when the work throws, as a database rollback would. */
final class InMemoryTransactionRunner implements TransactionRunner
{
    public int $committed = 0;

    public function __construct(
        private readonly InMemoryRuleStateStore $states,
        private readonly InMemoryIncidentRepository $incidents,
        private readonly ?InMemoryNotificationQueue $notifications = null,
    ) {}

    public function run(\Closure $work): void
    {
        $states = array_map(static fn($state) => clone $state, $this->states->states);
        [$saved, $unreadable] = [$this->states->saved, $this->states->unreadable];
        [$events, $active, $activeIds, $incidents] = [$this->incidents->events, $this->incidents->active, $this->incidents->activeIds, $this->incidents->incidents];
        $rows = $this->notifications?->rows;
        try {
            $work();
        } catch (\Throwable $e) {
            [$this->states->states, $this->states->saved, $this->states->unreadable] = [$states, $saved, $unreadable];
            [$this->incidents->events, $this->incidents->active, $this->incidents->activeIds, $this->incidents->incidents] = [$events, $active, $activeIds, $incidents];
            if ($this->notifications !== null && $rows !== null) {
                $this->notifications->rows = $rows;
            }

            throw $e;
        }
        ++$this->committed;
    }
}
