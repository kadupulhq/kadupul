<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Domain\DeviceMaintenanceRequest;
use Kadupul\Inventory\Domain\DeviceMaintenanceState;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceMaintenanceRecords;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceMaintenance;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use PHPUnit\Framework\TestCase;

final class DeviceMaintenanceProcessTest extends TestCase
{
    public function testRemoteReindexExecutesTheWorkerWithItsValidatedRevision(): void
    {
        $state = new DeviceMaintenanceState(new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 3, 0), [1 => 'one', 2 => 'two', 3 => 'three'], [1 => 2, 2 => 2, 3 => 2], false);
        $request = new DeviceMaintenanceRequest('reindex');
        $command = ['actor' => 2, 'id' => 7, 'operation' => 'reindex', 'query' => 0, 'revision' => $state->revision()];
        $directory = sys_get_temp_dir() . '/maintenance-process-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $file = $directory . '/bin/legacy-device-maintenance.php';
        $stub = '<?php $input=json_decode(stream_get_contents(STDIN),true); if ($input !== ' . var_export($command, true) . ') { exit(9); } echo \'KADUPUL_MAINTENANCE_RESULT={"status":"ok","completed":true,"message":"completed","output":""}\';';
        file_put_contents($file, $stub);
        try {
            $db = new PDO('sqlite::memory:');
            $db->exec('CREATE TABLE settings (name TEXT,value TEXT)');
            $db->prepare('INSERT INTO settings VALUES (?,?)')->execute(['path_php_binary', PHP_BINARY]);
            $provider = $this->createMock(DatabaseConnection::class);
            $provider->method('get')->willReturn($db);
            $adapter = new LegacyDeviceMaintenance($provider, new LegacyDeviceVisibility($provider), new DeviceMaintenanceRecords(), $directory);
            $result = $adapter->execute(2, 7, $request, $state->revision(), $state);
            self::assertTrue($result->completed);
            self::assertSame('completed', $result->message);
            self::assertSame('', $result->output);
        } finally {
            unlink($file);
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }

    public function testMismatchedStateAndRevisionCannotStartAWorker(): void
    {
        $state = new DeviceMaintenanceState(new DeviceState(7, 'fixture', '192.0.2.1', true, 0, 3, 0), [], [], false);
        foreach ([8 => \InvalidArgumentException::class, 7 => DeviceEditConflict::class] as $id => $exception) {
            $provider = $this->createMock(DatabaseConnection::class);
            $provider->expects(self::never())->method('get');
            $adapter = new LegacyDeviceMaintenance($provider, new LegacyDeviceVisibility($provider), new DeviceMaintenanceRecords(), '/unused');
            try {
                $adapter->execute(2, $id, new DeviceMaintenanceRequest('reindex'), str_repeat('a', 64), $state);
                self::fail('Invalid state or revision reached worker startup');
            } catch (\InvalidArgumentException|DeviceEditConflict $error) {
                self::assertInstanceOf($exception, $error);
            }
        }
    }
}
