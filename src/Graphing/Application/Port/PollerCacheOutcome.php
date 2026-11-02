<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

/** How a run of the rebuild_poller_cache.php body ended, as its worker reported it. */
final readonly class PollerCacheOutcome
{
    /** The master started its children and waited for them, or a child pushed out its slice. */
    public const string REBUILT = 'rebuilt';

    /** The master found no enabled device to process and printed its warning. */
    public const string NO_HOSTS = 'no_hosts';

    /** A run of the same type and thread was registered, so this one ended silently, as the script did. */
    public const string ALREADY_RUNNING = 'already_running';

    /** A --type other than rmaster or child: the script registered it and did nothing else. */
    public const string SKIPPED = 'skipped';

    /** SIGTERM or SIGINT stopped the run; its handler cleaned up and the script exited 1. */
    public const string INTERRUPTED = 'interrupted';

    /** Every status, in the order a report lists them. */
    public const array STATUSES = [self::REBUILT, self::NO_HOSTS, self::ALREADY_RUNNING, self::SKIPPED, self::INTERRUPTED];

    /**
     * @param string $status one of STATUSES
     * @param int $hosts the enabled devices the master counted, or the devices a child processed
     * @throws \InvalidArgumentException for a status not in STATUSES or a negative count
     */
    public function __construct(public string $status, public int $hosts = 0)
    {
        if (!in_array($status, self::STATUSES, true) || $hosts < 0) {
            throw new \InvalidArgumentException('Unknown poller cache outcome.');
        }
    }

    /** Whether the script would have exited 0: every ending but an interruption. */
    public function completed(): bool
    {
        return $this->status !== self::INTERRUPTED;
    }
}
