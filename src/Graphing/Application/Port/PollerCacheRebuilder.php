<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Port;

use Kadupul\IdentityAccess\Contract\OperatorDatabase;

/** Runs the body of cli/rebuild_poller_cache.php through the legacy code that registers, starts and waits for its processes. */
interface PollerCacheRebuilder
{
    /**
     * Runs the master or a child as the script did after reading its
     * arguments: the process registration, the child launches through
     * cli/push_out_hosts.php, push_out_host() for each device, and the
     * SIGTERM and SIGINT handler. Nothing is rolled back when a step fails,
     * as there.
     *
     * @param ?\Closure(string, string): void $progress receives what the script printed as it printed it:
     *     ("out", text) and ("err", text)
     * @phpstan-impure
     * @throws \RuntimeException when the run failed or its outcome is unknown
     */
    public function rebuild(OperatorDatabase $database, PollerCacheRequest $request, ?\Closure $progress = null): PollerCacheOutcome;

    /**
     * Passes a signal the command received on to a run in progress, so the
     * script's own handler stops its children and unregisters it.
     *
     * @return bool false when no run is in progress, so the caller must stop by itself
     */
    public function interrupt(int $signal): bool;
}
