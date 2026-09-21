<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\EditDevice;
use Kadupul\Inventory\Application\Port\DeviceEditor;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeviceEditTest extends TestCase
{
    public function testStaleRevisionDoesNotChangeTheAggregate(): void
    {
        $device = new Device(7, 'Before', 'before.invalid', 'Original notes', true);
        try {
            $device->revise('After', 'after.invalid', '', false, str_repeat('0', 64));
            self::fail('Expected conflict');
        } catch (DeviceEditConflict) {
            self::assertSame('Before', $device->description());
            self::assertSame('Original notes', $device->notes());
        }
    }

    #[DataProvider('invalidChanges')]
    public function testValidationIsAtomic(string $name, string $hostname, string $notes): void
    {
        $device = new Device(7, 'Before', 'before.invalid', 'Original notes', true);
        $before = $device->revision();
        try {
            $device->revise($name, $hostname, $notes, false, $before);
            self::fail('Expected validation error');
        } catch (\InvalidArgumentException) {
            self::assertSame($before, $device->revision());
        }
    }

    public static function invalidChanges(): iterable
    {
        yield ['', 'host.invalid', ''];
        yield [str_repeat('x', 151), 'host.invalid', ''];
        yield ['Name', 'host;command', ''];
        yield ['Name', '', ''];
        yield ['Name', 'host.invalid', str_repeat('x', 65536)];
        yield ['Name', 'host.invalid', "\xff"];
    }

    public function testCommandPassesActorAndExpectedRevisionToWritePort(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
        $access->method('canManageDevices')->willReturn(true);
        $device = new Device(7, 'Before', 'before.invalid', '', true);
        $revision = $device->revision();
        $port = $this->createMock(DeviceEditor::class);
        $port->expects(self::once())->method('findVisible')->with(42, 7)->willReturn($device);
        $port->expects(self::once())->method('save')->with(42, self::callback(fn(Device $saved) => $saved->description() === 'After' && $saved->hostname() === 'after.invalid' && !$saved->enabled()), $revision);
        (new EditDevice($access, $port))(7, 'After', 'after.invalid', 'Notes', false, $revision);
    }

    public function testPollingChangeInvalidatesAnOlderDetailsForm(): void
    {
        $device = new Device(7, 'Device', 'host.invalid', '', true);
        $original = $device->revision();
        $device->revise('Device', 'host.invalid', '', false, $original);
        self::assertFalse($device->enabled());
        self::assertNotSame($original, $device->revision());
        $this->expectException(DeviceEditConflict::class);
        $device->revise('Stale edit', 'host.invalid', '', true, $original);
    }

    public function testDisabledDeviceCanBeEnabledWithCurrentRevision(): void
    {
        $device = new Device(7, 'Device', 'host.invalid', '', false);
        $device->revise('Device', 'host.invalid', '', true, $device->revision());
        self::assertTrue($device->enabled());
    }

    public function testUnauthorizedCommandCannotReadOrWriteDevice(): void
    {
        $access = $this->createMock(ConsoleAccess::class);
        $access->method('consoleActor')->willReturn(new Actor(42, 'viewer'));
        $access->method('canManageDevices')->willReturn(false);
        $port = $this->createMock(DeviceEditor::class);
        $port->expects(self::never())->method('findVisible');
        $port->expects(self::never())->method('save');
        $this->expectException(InventoryAccessDenied::class);
        (new EditDevice($access, $port))(7, 'After', 'after.invalid', '', false, 'untrusted');
    }
}
