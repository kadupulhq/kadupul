<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\DevicePolling;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DevicePollingTest extends TestCase
{
    public function testLegacyZeroSettingsAndPartialCreationDefaults(): void
    {
        $fields = (new DevicePolling(array_replace(DevicePolling::DEFAULTS, ['ping_method' => '0', 'max_oids' => '0'])))->fields;
        self::assertSame('0', $fields['ping_method']);
        self::assertSame('0', $fields['max_oids']);
        $created = new \Kadupul\Inventory\Domain\NewDevice(['description' => 'Partial', 'hostname' => 'partial.invalid']);
        self::assertSame(DevicePolling::DEFAULTS, array_intersect_key($created->fields, DevicePolling::DEFAULTS));
    }

    public static function invalidSettings(): iterable
    {
        foreach (DevicePolling::RANGES as $key => [$minimum, $maximum]) {
            foreach ([$minimum - 1, $maximum + 1, '', '1e2', '1.5', true, [], ' 1'] as $invalid) {
                yield [$key, $invalid];
            }
        }
        foreach (['bulk_walk_size' => ['-2', '61'], 'availability_method' => ['7', '-1'], 'ping_method' => ['-1', '4', '6']] as $key => $values) {
            foreach ($values as $value) {
                yield [$key, $value];
            }
        }
    }

    #[DataProvider('invalidSettings')]
    public function testInvalidPollingDoesNotPartiallyChangeDevice(string $key, mixed $value): void
    {
        $device = new Device(7, 'Before', 'host.invalid', '', true, '', '');
        $revision = $device->revision();
        try {
            $device->revise('After', 'host.invalid', '', true, '', '', $revision, 8, array_replace(DevicePolling::DEFAULTS, [$key => $value]));
            self::fail('Invalid polling accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame('Before', $device->description());
            self::assertSame(0, $device->siteId());
            self::assertSame($revision, $device->revision());
        }
    }

    public function testEveryPollingFieldInvalidatesEarlierForms(): void
    {
        foreach (DevicePolling::DEFAULTS as $key => $default) {
            $device = new Device(7, 'Device', 'host.invalid', '', true, '', '');
            $revision = $device->revision();
            $settings = DevicePolling::DEFAULTS;
            $settings[$key] = (string) ((int) $default + 1);
            $device->revise('Device', 'host.invalid', '', true, '', '', $revision, 0, $settings);
            self::assertNotSame($revision, $device->revision(), $key);
            try {
                $device->revise('Stale', 'host.invalid', '', true, '', '', $revision, 0, DevicePolling::DEFAULTS);
                self::fail('Stale polling accepted');
            } catch (DeviceEditConflict) {
                self::assertSame('Device', $device->description());
            }
        }
    }

    public function testMissingAndExtraFieldsAreRejected(): void
    {
        foreach ([[], array_diff_key(DevicePolling::DEFAULTS, ['snmp_port' => true]), DevicePolling::DEFAULTS + ['snmp_password' => 'unexpected']] as $fields) {
            try {
                new DevicePolling($fields);
                self::fail('Incomplete or extra polling fields accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSupportedBoundaryValuesAndSentinel(): void
    {
        foreach ([0, 1] as $boundary) {
            $fields = DevicePolling::DEFAULTS;
            foreach (DevicePolling::RANGES as $key => $range) {
                $fields[$key] = $range[$boundary];
            }
            $fields['bulk_walk_size'] = '-1';
            $polling = new DevicePolling($fields);
            self::assertSame('-1', $polling->fields['bulk_walk_size']);
            self::assertSame((string) $fields['ping_timeout'], $polling->fields['ping_timeout']);
        }
    }
}
