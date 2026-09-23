<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\TestCase;

/*
 * The worker runs under a Cacti bootstrap, so its commit sequence is asserted
 * against the source. A collector that commits before the main database cannot
 * be rolled back when the main commit then fails.
 */
final class DeviceRemovalCommitOrderTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(__DIR__ . '/../../bin/legacy-device-remove.php');
    }

    public function testTheMainDatabaseCommitsBeforeAnyCollector(): void
    {
        $source = $this->source();
        $primary = strpos($source, 'db_commit_transaction()');
        $collectors = strpos($source, '$remote->commit()');

        self::assertIsInt($primary);
        self::assertIsInt($collectors);
        self::assertLessThan($collectors, $primary);
    }

    public function testACollectorCommitFailureDoesNotDiscardTheLocalRemoval(): void
    {
        $source = $this->source();
        $collectors = strpos($source, '$remote->commit()');
        $failure = strpos($source, 'Collector commit failed');

        // The local removal is already committed at this point, so the failure
        // is logged rather than thrown into the rollback path.
        self::assertFalse($failure);
        self::assertStringContainsString('replication will reconcile', substr($source, $collectors, 400));
    }
}
