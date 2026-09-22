<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceEditor;
use Kadupul\Inventory\Infrastructure\Legacy\LegacyDeviceVisibility;
use Kadupul\Platform\Contract\DatabaseConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceEditResultTest extends TestCase
{
    public static function rejectedResults(): array
    {
        return [['snmp_invalid', \InvalidArgumentException::class], ['invalid', \RuntimeException::class], ['failed', \RuntimeException::class], ['unknown', \RuntimeException::class]];
    }
    #[DataProvider('rejectedResults')]
    public function testOnlyExplicitSnmpValidationIsReportedAsCredentialError(string $status, string $exception): void
    {
        $directory = sys_get_temp_dir() . '/kadupul-edit-result-' . bin2hex(random_bytes(8));
        mkdir($directory . '/bin', 0700, true);
        $output = 'KADUPUL_EDIT_RESULT=' . json_encode(['status' => $status]) . "\n";
        file_put_contents($directory . '/bin/legacy-device-edit.php', '<?php echo ' . var_export($output, true) . '; exit(1);');
        try {
            $database = $this->createMock(DatabaseConnection::class);
            $editor = new LegacyDeviceEditor($database, new LegacyDeviceVisibility($database), $directory);
            $device = new Device(1, 'Device', 'router.invalid', '', true, '', '');
            try {
                $editor->save(42, $device, $device->revision());
                self::fail('Worker rejection accepted');
            } catch (\Throwable $error) {
                self::assertSame($exception, $error::class);
                self::assertSame($status === 'snmp_invalid', str_contains($error->getMessage(), 'stored credentials'));
            }
        } finally {
            unlink($directory . '/bin/legacy-device-edit.php');
            rmdir($directory . '/bin');
            rmdir($directory);
        }
    }
}
