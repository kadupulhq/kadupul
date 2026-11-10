<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Kadupul\Tests\Alerting\Fakes;

use Kadupul\Alerting\Application\Port\RunLock;

/** Named locks of one process; $elsewhere are held by another process and cannot be taken. */
final class InMemoryRunLock implements RunLock
{
    /** @var array<string, true> */
    public array $held = [];
    /** @var list<string> "acquire <purpose>", "busy <purpose>" or "release <purpose>", in order */
    public array $events = [];

    /**
     * @param list<string> $elsewhere purposes another process holds
     * @param bool $releaseFails release() reports that the lock was not held, as a lost session would
     */
    public function __construct(public array $elsewhere = [], public bool $releaseFails = false) {}

    public function acquire(string $purpose): bool
    {
        if (in_array($purpose, $this->elsewhere, true)) {
            $this->events[] = 'busy ' . $purpose;

            return false;
        }
        $this->held[$purpose] = true;
        $this->events[] = 'acquire ' . $purpose;

        return true;
    }

    public function release(string $purpose): bool
    {
        $this->events[] = 'release ' . $purpose;
        $was = isset($this->held[$purpose]);
        unset($this->held[$purpose]);

        return $was && !$this->releaseFails;
    }
}
