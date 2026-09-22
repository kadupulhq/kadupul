<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Domain\Device;
use Kadupul\Inventory\Domain\DeviceSnmpChange;
use Kadupul\Inventory\Domain\DeviceSnmpConfiguration as Snmp;
use PHPUnit\Framework\TestCase;

final class DeviceSnmpChangeTest extends TestCase
{
    private function fields(array $overrides = []): array
    {
        return array_replace(['keep_credentials' => true] + Snmp::PUBLIC_DEFAULTS + Snmp::CREDENTIAL_DEFAULTS, $overrides);
    }

    public function testKeepResolvesCurrentCredentialsWithoutPuttingThemInPublicSettings(): void
    {
        $change = new DeviceSnmpChange($this->fields());
        self::assertSame('', $change->fields['snmp_community']);
        self::assertSame('rotated-secret', $change->resolve(['snmp_community' => 'rotated-secret'])['snmp_community']);
        self::assertArrayNotHasKey('snmp_community', $change->publicSettings());
    }

    public function testExplicitReplacementAndVersionDowngrade(): void
    {
        $change = new DeviceSnmpChange($this->fields(['keep_credentials' => false, 'snmp_version' => '3', 'snmp_username' => 'operator', 'snmp_auth_protocol' => 'SHA384', 'snmp_password' => 'password-new', 'snmp_priv_protocol' => 'AES', 'snmp_priv_passphrase' => 'privacy-new']));
        self::assertSame('password-new', $change->resolve(['snmp_password' => 'old'])['snmp_password']);
        $downgrade = new DeviceSnmpChange(array_replace($change->fields, ['snmp_version' => '2', 'snmp_context' => 'old-context']));
        self::assertSame('[None]', $downgrade->publicSettings()['snmp_auth_protocol']);
        self::assertSame('', $downgrade->publicSettings()['snmp_context']);
    }

    public function testInvalidSettingsDoNotPartiallyMutateDevice(): void
    {
        foreach ([['keep_credentials' => null], ['keep_credentials' => 'keep'], ['snmp_version' => '4'], ['snmp_password' => 'unwanted'], ['keep_credentials' => false, 'snmp_version' => '3'], ['snmp_auth_protocol' => 'bogus'], ['snmp_context' => str_repeat('x', 65)], ['snmp_context' => "bad\0text"], ['extra' => 'ignored']] as $override) {
            $device = new Device(1, 'Before', 'router.invalid', '', true, '', '');
            $revision = $device->revision();
            try {
                $device->revise('After', 'router.invalid', '', true, '', '', $revision, 0, null, $this->fields($override));
                self::fail('Invalid SNMP accepted');
            } catch (\InvalidArgumentException) {
                self::assertSame($revision, $device->revision());
                self::assertSame('Before', $device->description());
            }
        }
    }

    public function testCredentialValuesCannotEnterPublicConfigurationOrRevision(): void
    {
        $first = new Device(1, 'Device', 'router.invalid', '', true, '', '', 0, [], ['snmp_password' => 'first-secret']);
        $second = new Device(1, 'Device', 'router.invalid', '', true, '', '', 0, [], ['snmp_password' => 'second-secret']);
        self::assertSame(Snmp::PUBLIC_DEFAULTS, $first->snmp());
        self::assertSame($first->revision(), $second->revision());
        $revision = $first->revision();
        $first->revise('Device', 'router.invalid', '', true, '', '', $revision, 0, null, $this->fields(['keep_credentials' => false, 'snmp_community' => 'replacement-secret']));
        self::assertSame($revision, $first->revision());
        self::assertSame('replacement-secret', $first->snmpChange()->resolve([])['snmp_community']);
    }

    public function testPublicSettingsInvalidateOldRevision(): void
    {
        $device = new Device(1, 'Device', 'router.invalid', '', true, '', '');
        $revision = $device->revision();
        $device->revise('Device', 'router.invalid', '', true, '', '', $revision, 0, null, $this->fields(['snmp_version' => '1']));
        self::assertNotSame($revision, $device->revision());
    }

    public function testV3ProtocolAndPassphraseRequirements(): void
    {
        $valid = $this->fields(['keep_credentials' => false, 'snmp_version' => '3', 'snmp_username' => 'operator', 'snmp_auth_protocol' => 'SHA384', 'snmp_password' => 'password-eight', 'snmp_priv_protocol' => 'AES', 'snmp_priv_passphrase' => 'privacy-eight']);
        foreach ([['snmp_auth_protocol' => '[None]'], ['snmp_password' => 'short'], ['snmp_priv_passphrase' => 'short'], ['snmp_priv_protocol' => 'unsupported']] as $override) {
            try {
                new DeviceSnmpChange(array_replace($valid, $override));
                self::fail('Invalid SNMPv3 combination accepted');
            } catch (\InvalidArgumentException $error) {
                self::assertStringNotContainsString('password-eight', $error->getMessage());
                self::assertStringNotContainsString('privacy-eight', $error->getMessage());
            }
        }
        foreach (array_keys(Snmp::PUBLIC_DEFAULTS + Snmp::CREDENTIAL_DEFAULTS) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            try {
                new DeviceSnmpChange($missing);
                self::fail('Missing SNMP field accepted: ' . $key);
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Submit all SNMP settings.', $error->getMessage());
            }
        }
    }

    public function testNonV3ResolutionClearsV3SecretsBeforeCallingLegacyPersistence(): void
    {
        foreach (['0', '1', '2'] as $version) {
            foreach ([true, false] as $keep) {
                $credentials = ['snmp_community' => 'community', 'snmp_username' => 'user', 'snmp_password' => 'auth-secret', 'snmp_priv_passphrase' => 'privacy-secret'];
                $fields = $this->fields(['snmp_version' => $version, 'keep_credentials' => $keep]);
                if (!$keep) {
                    $fields = array_replace($fields, $credentials);
                }
                $resolved = (new DeviceSnmpChange($fields))->resolve($credentials);
                self::assertSame('community', $resolved['snmp_community']);
                foreach (['snmp_username', 'snmp_password', 'snmp_priv_passphrase'] as $key) {
                    self::assertSame('', $resolved[$key]);
                }
            }
        }
    }

    public function testStoredCredentialsAreValidatedAfterResolution(): void
    {
        $change = new DeviceSnmpChange($this->fields(['snmp_version' => '3', 'snmp_auth_protocol' => 'SHA', 'snmp_priv_protocol' => 'AES']));
        $this->expectException(\InvalidArgumentException::class);
        $change->resolve(['snmp_username' => 'user', 'snmp_password' => 'short', 'snmp_priv_passphrase' => 'short']);
    }
}
