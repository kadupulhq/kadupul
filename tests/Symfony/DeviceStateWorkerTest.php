<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceStates;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeviceStateWorkerTest extends TestCase
{
    public function testStrictModeIsRestoredAfterRemoteSetupBeforeWrites(): void
    {
        $source = file_get_contents(__DIR__ . '/../../bin/legacy-device-state.php');
        self::assertIsString($source);
        $connect = strpos($source, 'poller_connect_to_remote(');
        $remoteMode = strpos($source, '$remote->exec("SET SESSION sql_mode');
        $primaryMode = strpos($source, '$connection->exec("SET SESSION sql_mode');
        $write = strpos($source, 'api_device_enable_devices(');
        foreach ([$connect, $remoteMode, $primaryMode, $write] as $offset) {
            self::assertNotFalse($offset);
        }
        self::assertLessThan($remoteMode, $connect);
        self::assertLessThan($primaryMode, $remoteMode);
        self::assertLessThan($write, $primaryMode);
        self::assertStringContainsString("throw new RuntimeException('Collector connection validation unavailable')", $source);
        self::assertStringContainsString("throw new RuntimeException('Primary connection validation unavailable')", $source);
    }

    public static function executables(): array
    {
        return [[true, '{"status":"ok"}', false], [false, '{"status":"ok"}', true], [true, '{malformed-json}', true], [true, '{"status":[]}', true], [true, '"ok"', true], [true, 'null', true], [true, 'true', true], [true, '42', true], [true, '[]', true]];
    }

    #[DataProvider('executables')]
    public function testConfiguredExecutableAndWorkerResultFailures(bool $available, string $result, bool $failed): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-state-worker-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        file_put_contents($directory . '/bin/legacy-device-state.php', '<?php echo ' . var_export('KADUPUL_STATE_RESULT=' . $result, true) . ';');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', '  ' . ($available ? PHP_BINARY : $directory . '/missing php executable') . '  ']);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $adapter = new LegacyDeviceStates($database, new LegacyDeviceVisibility($database), $directory);
            $selection = new DeviceSelection([1 => str_repeat('a', 64)]);
            if ($failed) {
                // Ignoring the configured path would incorrectly run the successful
                // stub through PHP_BINDIR/php instead of rejecting the missing binary.
                $this->expectException(\RuntimeException::class);
            }
            $adapter->setEnabled(1, $selection, true);
            self::assertTrue(true);
        } finally {
            unlink($directory . '/bin/legacy-device-state.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
