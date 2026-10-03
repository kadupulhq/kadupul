<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceOptionsChange;
use Kadupul\Inventory\Domain\DeviceState;
use Kadupul\Inventory\Infrastructure\Legacy\DeviceOptionsWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceOptionsWriterTest extends TestCase
{
    private function connection(): \PDO
    {
        $connection = new \PDO('sqlite::memory:');
        $connection->exec("CREATE TABLE host (id INTEGER PRIMARY KEY, poller_id INTEGER, deleted TEXT, location TEXT, snmp_timeout INTEGER)");
        $connection->exec("INSERT INTO host VALUES (7, 1, '', 'Original', 500)");
        return $connection;
    }

    public function testApplyAndVerifyReadActualStoredValues(): void
    {
        $connection = $this->connection();
        $writer = new DeviceOptionsWriter();
        $device = new DeviceState(7, 'Device', 'device.invalid', true, 0, 1, 0);
        $change = new DeviceOptionsChange(['location' => 'Rack 東京', 'snmp_timeout' => '750']);
        $writer->apply($connection, $device, $change);
        $writer->verify($connection, $device, $change);
        self::assertSame(['location' => 'Rack 東京', 'snmp_timeout' => 750], $connection->query('SELECT location, snmp_timeout FROM host')->fetch(\PDO::FETCH_ASSOC));
    }

    public static function unavailableRows(): array
    {
        return [["UPDATE host SET poller_id=2"], ["UPDATE host SET deleted='on'"], ["DELETE FROM host"]];
    }

    #[DataProvider('unavailableRows')]
    public function testApplyRejectsMissingSelectedCopy(string $mutation): void
    {
        $connection = $this->connection();
        $connection->exec($mutation);
        $this->expectExceptionMessage('Device options could not be saved.');
        (new DeviceOptionsWriter())->apply($connection, new DeviceState(7, 'Device', 'device.invalid', true, 0, 1, 0), new DeviceOptionsChange(['location' => 'Rack']));
    }

    #[DataProvider('unavailableRows')]
    public function testVerifyRejectsMissingSelectedCopy(string $mutation): void
    {
        $connection = $this->connection();
        $connection->exec($mutation);
        $this->expectExceptionMessage('Device options could not be confirmed.');
        (new DeviceOptionsWriter())->verify($connection, new DeviceState(7, 'Device', 'device.invalid', true, 0, 1, 0), new DeviceOptionsChange(['location' => 'Original']));
    }

    public function testTriggerRewriteIsDetectedAndCallerCanRollback(): void
    {
        $connection = $this->connection();
        $connection->exec("CREATE TRIGGER rewrite_location AFTER UPDATE ON host BEGIN UPDATE host SET location='Rewritten' WHERE id=NEW.id; END");
        $writer = new DeviceOptionsWriter();
        $device = new DeviceState(7, 'Device', 'device.invalid', true, 0, 1, 0);
        $change = new DeviceOptionsChange(['location' => 'Rack']);
        $connection->beginTransaction();
        $writer->apply($connection, $device, $change);
        self::assertSame('Rewritten', $connection->query('SELECT location FROM host')->fetchColumn());
        try {
            $writer->verify($connection, $device, $change);
            self::fail('Rewritten value was reported as confirmed');
        } catch (\RuntimeException $error) {
            self::assertSame('Device options could not be confirmed.', $error->getMessage());
            self::assertTrue($connection->inTransaction());
        }
        $connection->rollBack();
        self::assertSame('Original', $connection->query('SELECT location FROM host')->fetchColumn());
    }
}
