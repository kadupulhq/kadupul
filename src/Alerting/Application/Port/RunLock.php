<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Alerting\Application\Port;

/**
 * Process-wide named locks, one per purpose, so one evaluator and one
 * deliverer run at a time (ADR 0011 decision 2). A lock ends with the process
 * that holds it, so a crashed run never blocks the next one.
 */
interface RunLock
{
    public const string EVALUATE = 'evaluate';
    public const string DELIVER = 'deliver';

    /** Takes the lock without waiting; false when another process holds it. */
    public function acquire(string $purpose): bool;

    /** @return bool false when this process no longer held the lock, as after a lost session */
    public function release(string $purpose): bool;
}
