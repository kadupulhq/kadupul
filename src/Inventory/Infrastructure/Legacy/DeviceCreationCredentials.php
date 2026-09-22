<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Domain\NewDevice;

/** Resolve configured credentials only inside the authorized CLI worker. */
final class DeviceCreationCredentials
{
    public function resolve(NewDevice $device, callable $readSetting): NewDevice
    {
        $fields = $device->fields;
        if (!$fields['use_default_credentials']) {
            return $device;
        }
        foreach (['snmp_community', 'snmp_password', 'snmp_priv_passphrase'] as $key) {
            $fields[$key] = (string) $readSetting($key);
        }
        if ($fields['snmp_username'] === '') {
            $fields['snmp_username'] = (string) $readSetting('snmp_username');
        }
        $fields['use_default_credentials'] = false;

        return new NewDevice($fields);
    }
}
