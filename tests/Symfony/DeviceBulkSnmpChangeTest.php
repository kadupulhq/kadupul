<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\DeviceBulkSnmpChange;
use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use PHPUnit\Framework\TestCase;

final class DeviceBulkSnmpChangeTest extends TestCase
{
    private function stored(): array
    {
        return ['snmp_version' => '3', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES', 'snmp_context' => 'original', 'snmp_engine_id' => 'original-engine', 'snmp_community' => 'original-community', 'snmp_username' => 'original-user', 'snmp_password' => 'original-password', 'snmp_priv_passphrase' => 'original-privacy'];
    }

    public function testPartialUpdatePreservesEveryUncheckedSettingAndSecret(): void
    {
        $stored = $this->stored();
        foreach ([true, false] as $value) {
            $change = new DeviceBulkSnmpChange(['keep_credentials' => $value, 'snmp_context' => 'new-context']);
            self::assertSame(array_replace($stored, ['snmp_context' => 'new-context']), $change->resolve($stored));
        }
        $change = new DeviceBulkSnmpChange(['keep_credentials' => false, 'snmp_password' => 'replacement-password']);
        self::assertSame(array_replace($stored, ['snmp_password' => 'replacement-password']), $change->resolve($stored));
    }

    public function testExplicitVersionChangeRetainsUncheckedStoredCredentials(): void
    {
        foreach (['0', '1', '2'] as $version) {
            $stored = $this->stored();
            $change = new DeviceBulkSnmpChange(['keep_credentials' => true, 'snmp_version' => $version]);
            self::assertSame(array_replace($stored, ['snmp_version' => $version]), $change->resolve($stored));
        }
    }

    public function testSelectedProtocolUsesTheDevicesActualVersionAndCredentials(): void
    {
        $change = new DeviceBulkSnmpChange(['keep_credentials' => true, 'snmp_priv_protocol' => 'AES256']);
        self::assertSame('AES256', $change->resolve($this->stored())['snmp_priv_protocol']);
        $this->expectException(\InvalidArgumentException::class);
        $change->resolve(array_replace($this->stored(), ['snmp_auth_protocol' => '[None]']));
    }

    public function testUntouchedSubmissionCannotConstructAWriteCommand(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Select at least one supported SNMP setting.');
        new DeviceBulkSnmpChange(['keep_credentials' => true]);
    }

    public function testInvalidSelectedInputsFailBeforeResolvingSecrets(): void
    {
        foreach ([['snmp_version' => null], ['snmp_version' => '4'], ['snmp_auth_protocol' => 'bogus'], ['snmp_context' => str_repeat('x', 65)], ['snmp_password' => "bad\0text"], ['extra' => 'ignored'], ['snmp_context' => []]] as $fields) {
            try {
                new DeviceBulkSnmpChange(['keep_credentials' => false] + $fields);
                self::fail('Invalid selected setting accepted');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
