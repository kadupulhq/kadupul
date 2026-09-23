<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DevicePlacement;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDevicePlacements;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class DevicePlacementWorkerBinaryTest extends TestCase
{
    public static function settings(): iterable
    {
        yield 'unset' => [null];
        yield 'blank' => ['   '];
        yield 'configured' => [' ' . PHP_BINARY . ' '];
    }

    #[DataProvider('settings')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWorkerUsesCliRatherThanRequestSapi(?string $configured): void
    {
        // Model a non-CLI request interpreter without requiring an FPM server.
        define('Kadupul\\Inventory\\Infrastructure\\Legacy\\PHP_BINARY', '/missing/php-fpm');
        $directory = sys_get_temp_dir() . '/placement-cli-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $file = $directory . '/bin/legacy-device-placement.php';
        file_put_contents($file, '<?php if (PHP_SAPI !== "cli") { exit(9); } echo \'KADUPUL_PLACEMENT_RESULT={"status":"ok"}\';');
        try {
            $db = new \PDO('sqlite::memory:');
            $db->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            if ($configured !== null) {
                $db->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', $configured]);
            }
            $connection = $this->createMock(DatabaseConnection::class);
            $connection->method('get')->willReturn($db);
            (new LegacyDevicePlacements($connection, $directory))->place(
                42,
                new DeviceSelection([7 => str_repeat('a', 64)]),
                new DevicePlacement('tree', '2:0')
            );
            $this->addToAssertionCount(1);
        } finally {
            unlink($file);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
