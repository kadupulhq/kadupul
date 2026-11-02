<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PollerCacheRebuilder;
use Kadupul\Graphing\Application\Port\PollerCacheRequest;
use Kadupul\Graphing\Application\Port\PollerCacheSettings;

/**
 * Rebuilds the poller cache as cli/rebuild_poller_cache.php did, for an
 * operator with console access and the Utilities realm (15), the realm of
 * utilities.php, whose Rebuild Poller Cache action points operators to the
 * script. Every run acts as the admin_user operator on the main database,
 * because the master starts its children through cli/push_out_hosts.php,
 * which can pass on neither an operator nor --local.
 */
final readonly class RebuildPollerCache
{
    private const string ACTION = 'poller-cache.rebuild';

    /**
     * @param GraphCommandAccess $access operator, target database and audit for the command-line tools
     * @param PollerCacheSettings $settings the setting quoted in the --threads error
     * @param PollerCacheRebuilder $rebuilder the legacy body of the script
     */
    public function __construct(private GraphCommandAccess $access, private PollerCacheSettings $settings, private PollerCacheRebuilder $rebuilder) {}

    /**
     * Authorizes the operator, then runs the master or a child. One audit
     * record follows each authorized run, naming the device or "all".
     *
     * @param ?\Closure(string, string): void $progress receives the script's output as it is printed
     * @throws GraphManagementDenied before anything is read or started
     */
    public function __invoke(PollerCacheRequest $request, ?\Closure $progress = null): PollerCacheRebuild
    {
        $scope = $this->access->select(self::ACTION, false, null, true, ManagedArea::Utilities);
        $correlation = $this->access->correlation();
        $outcome = null;
        try {
            $outcome = $this->rebuilder->rebuild($scope->database, $request, $progress);
        } catch (\RuntimeException) {
            // Reported as a failure; the audit record below says the same.
        } finally {
            // The device id is digits only; the cast keeps the record's id a plain number.
            $target = $request->hostId === null ? 'all' : (string) (int) $request->hostId;
            $this->access->change($correlation, $scope, self::ACTION, 'poller-cache', $target, $outcome?->completed() ?? false);
        }

        return new PollerCacheRebuild($scope->database, $outcome->status ?? PollerCacheRebuild::FAILED, $outcome->hosts ?? 0);
    }

    /**
     * The Poller Command Concurrent Processes setting, which the script quoted
     * when --threads was not a positive integer. It is read only for an
     * authorized operator.
     *
     * @throws GraphManagementDenied before the setting is read
     */
    public function concurrentProcesses(): string
    {
        $scope = $this->access->select(self::ACTION, false, null, true, ManagedArea::Utilities);

        return $this->settings->concurrentProcesses($scope->database);
    }

    /**
     * Passes a SIGTERM or SIGINT on to the run in progress.
     *
     * @return bool false when no run is in progress
     */
    public function interrupt(int $signal): bool
    {
        return $this->rebuilder->interrupt($signal);
    }
}
