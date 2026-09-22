<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceTemplateAssignment;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceTemplateAssignments;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class DeviceTemplateWorkerTest extends TestCase
{
    public static function executables(): array
    {
        return [[true, '{"status":"ok"}', false], [false, '{"status":"ok"}', true], [true, '{malformed-json}', true], [true, '{"status":[]}', true]];
    }

    #[DataProvider('executables')]
    public function testConfiguredExecutableAndWorkerResultFailures(bool $available, string $result, bool $failed): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-template-worker-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        file_put_contents($directory . '/bin/legacy-device-template.php', '<?php echo ' . var_export('KADUPUL_TEMPLATE_RESULT=' . $result, true) . ';');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', '  ' . ($available ? PHP_BINARY : $directory . '/missing php executable') . '  ']);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $adapter = new LegacyDeviceTemplateAssignments($database, new LegacyDeviceVisibility($database), $directory);
            $assignment = new DeviceTemplateAssignment(1, 'Device', 0, 1);
            if ($failed) {
                // Ignoring the configured path would incorrectly run the successful
                // stub through PHP_BINDIR/php instead of rejecting the missing binary.
                $this->expectException(\RuntimeException::class);
            }
            $adapter->save(1, $assignment, $assignment->revision());
            self::assertTrue(true);
        } finally {
            unlink($directory . '/bin/legacy-device-template.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
