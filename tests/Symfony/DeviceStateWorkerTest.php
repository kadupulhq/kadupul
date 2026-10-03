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

    public function testSynchronizationHandoffContainsOnlyActorSelectionAndOperation(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-sync-handoff-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $capture = $directory . '/payload.json';
        file_put_contents($directory . '/bin/legacy-device-state.php', '<?php file_put_contents(' . var_export($capture, true) . ', stream_get_contents(STDIN)); echo \'KADUPUL_STATE_RESULT={"status":"ok"}\';');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $selection = new DeviceSelection([7 => str_repeat('a', 64)]);
            (new LegacyDeviceStates($database, new LegacyDeviceVisibility($database), $directory))->synchronizeTemplates(42, $selection);
            self::assertSame(['actor' => 42, 'selection' => [7 => str_repeat('a', 64)], 'operation' => 'sync-template'], json_decode(file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR));
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
