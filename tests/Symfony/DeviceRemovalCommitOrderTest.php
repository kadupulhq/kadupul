<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\RemovalCommitOrder;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';

$log = [];
$commits = [];
$primaryCommits = true;

function db_commit_transaction()
{
    $GLOBALS['commits'][] = 'primary';

    return $GLOBALS['primaryCommits'];
}

function cacti_log(string $message, bool $output = false, string $environ = '')
{
    $GLOBALS['log'][] = $environ . ': ' . $message;
}

// A fixed first-party function body; the worker itself needs a Cacti bootstrap.
eval('namespace ' . __NAMESPACE__ . '; use RuntimeException;' . \test_php_function_source(file_get_contents(__DIR__ . '/../../bin/legacy-device-remove.php'), 'device_removal_commit')); // nosemgrep: php.lang.security.eval-use.eval-use

final class DeviceRemovalCommitOrderTest extends TestCase
{
    /** @return PDO A collector whose commit succeeds or fails, recording the order. */
    private function collector(int $pollerId, bool $succeeds): PDO
    {
        $remote = $this->createMock(PDO::class);
        $remote->method('commit')->willReturnCallback(static function () use ($pollerId, $succeeds): bool {
            $GLOBALS['commits'][] = 'collector ' . $pollerId;

            return $succeeds;
        });

        return $remote;
    }

    protected function setUp(): void
    {
        $GLOBALS['log'] = [];
        $GLOBALS['commits'] = [];
        $GLOBALS['primaryCommits'] = true;
    }

    public function testTheMainDatabaseCommitsBeforeAnyCollector(): void
    {
        device_removal_commit([2 => $this->collector(2, true), 3 => $this->collector(3, true)], [7]);

        self::assertSame(['primary', 'collector 2', 'collector 3'], $GLOBALS['commits']);
        self::assertSame([], $GLOBALS['log']);
    }

    public function testAFailedPrimaryCommitLeavesTheCollectorsUncommitted(): void
    {
        $GLOBALS['primaryCommits'] = false;

        try {
            device_removal_commit([2 => $this->collector(2, true)], [7]);
            self::fail('Expected the commit to be refused');
        } catch (RuntimeException $error) {
            self::assertSame('Commit failed', $error->getMessage());
        }

        // The collector must not have committed; the caller rolls it back.
        self::assertSame(['primary'], $GLOBALS['commits']);
    }

    public function testAFailedCollectorCommitIsLoggedAgainstThatCollector(): void
    {
        device_removal_commit([2 => $this->collector(2, false), 3 => $this->collector(3, true)], [7, 8]);

        self::assertSame(['primary', 'collector 2', 'collector 3'], $GLOBALS['commits']);
        self::assertCount(1, $GLOBALS['log']);
        self::assertStringContainsString('collector 2 did not commit', $GLOBALS['log'][0]);
        self::assertStringContainsString('Devices 7,8', $GLOBALS['log'][0]);
        self::assertStringStartsWith('AUDIT: ERROR', $GLOBALS['log'][0]);
    }
}
