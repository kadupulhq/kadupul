<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Application\Command;

use Kadupul\Graphing\Application\Port\PollerOutputDrain;
use Kadupul\Graphing\Application\Port\PollerOutputDrainResult;

/**
 * Use case behind cli/poller_output_empty.php: writes every queued
 * poller_output sample to its RRD file and audits the pass.
 */
final readonly class EmptyPollerOutput
{
    private const string ACTION = 'poller-output.empty';

    public function __construct(private GraphCommandAccess $access, private PollerOutputDrain $drain) {}

    /**
     * The original never left this installation's database, so a remote
     * collector drains its own queue and the operator is read from its own
     * replicated accounts; there is no main-database switch to ask for.
     *
     * @param ?string $operator account to act as; null means the admin_user setting
     * @param ?\Closure(string, string): void $relay receives the 'out' or 'err' text the pass printed, as it is printed
     * @throws GraphManagementDenied before the queue is touched, when the operator lacks the Console Access and Devices realms
     */
    public function __invoke(?string $operator, ?\Closure $relay = null): PollerOutputEmptying
    {
        // No web page empties this queue; data_sources.php, realm 3, owns the data it feeds.
        $scope = $this->access->select(self::ACTION, true, $operator, true, ManagedArea::Devices);
        $correlation = $this->access->correlation();
        $result = null;
        try {
            $result = $this->drain->drain($relay);
        } catch (\RuntimeException) {
            // Reported as a failure; the audit record below says the same.
        } finally {
            $this->access->change($correlation, $scope, self::ACTION, 'poller-output', 'queue', $result?->succeeded() === true);
        }

        return $result === null
            ? new PollerOutputEmptying($scope->database, PollerOutputDrainResult::FAILED)
            : new PollerOutputEmptying($scope->database, $result->status, $result->updates);
    }
}
