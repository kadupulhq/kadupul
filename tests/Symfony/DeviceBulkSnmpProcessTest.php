<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceBulkSnmpChange;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceStates;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;

final class DeviceBulkSnmpProcessTest extends TestCase
{
    public function testActualProcessHandoffUsesOnlyTheSelectedSnmpEnvelope(): void
    {
        $directory = sys_get_temp_dir() . '/bulk-snmp-handoff-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $capture = $directory . '/command.json';
        file_put_contents($directory . '/bin/legacy-device-state.php', '<?php file_put_contents(' . var_export($capture, true) . ', stream_get_contents(STDIN)); echo "KADUPUL_STATE_RESULT={\\"status\\":\\"ok\\"}\\n";');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT,value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?,?)')->execute(['path_php_binary', PHP_BINARY]);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $port = new LegacyDeviceStates($database, new LegacyDeviceVisibility($database), $directory);
            $fields = ['keep_credentials' => true, 'snmp_auth_protocol' => 'SHA256'];
            $port->changeSnmp(42, new DeviceSelection([7 => str_repeat('a', 64)]), new DeviceBulkSnmpChange($fields));
            self::assertSame(['actor' => 42, 'selection' => [7 => str_repeat('a', 64)], 'operation' => 'snmp', 'changes' => $fields], json_decode(file_get_contents($capture), true, 16, JSON_THROW_ON_ERROR));
        } finally {
            if (is_file($capture)) {
                unlink($capture);
            }
            unlink($directory . '/bin/legacy-device-state.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
