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

final class DeviceTemplateWorkerTest extends TestCase
{
    public function testConfiguredPhpExecutableStartsTemplateWorker(): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-template-worker-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        file_put_contents($directory . '/bin/legacy-device-template.php', '<?php echo \'KADUPUL_TEMPLATE_RESULT={"status":"ok"}\';');
        try {
            $pdo = new \PDO('sqlite::memory:');
            $pdo->exec('CREATE TABLE settings (name TEXT, value TEXT)');
            $pdo->prepare('INSERT INTO settings VALUES (?, ?)')->execute(['path_php_binary', '  ' . PHP_BINARY . '  ']);
            $database = $this->createMock(DatabaseConnection::class);
            $database->method('get')->willReturn($pdo);
            $adapter = new LegacyDeviceTemplateAssignments($database, new LegacyDeviceVisibility($database), $directory);
            $assignment = new DeviceTemplateAssignment(1, 'Device', 0, 1);
            $adapter->save(1, $assignment, $assignment->revision());
            self::assertTrue(true);
        } finally {
            unlink($directory . '/bin/legacy-device-template.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
