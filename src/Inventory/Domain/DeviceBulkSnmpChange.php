<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/** Only explicitly selected fields are changed; secrets remain in the worker. */
final readonly class DeviceBulkSnmpChange
{
    /** @var array<string, string|bool> */
    public array $fields;

    public function __construct(#[\SensitiveParameter] array $fields)
    {
        if (!is_bool($fields['keep_credentials'] ?? null)) {
            throw new \InvalidArgumentException('Choose whether to use stored credentials or replace them.');
        }
        $keep = $fields['keep_credentials'];
        unset($fields['keep_credentials']);
        $defaults = DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS;
        if ($fields === [] || array_diff_key($fields, $defaults) !== []) {
            throw new \InvalidArgumentException('Select at least one supported SNMP setting.');
        }
        if (array_key_exists('snmp_version', $fields) && !in_array($fields['snmp_version'], ['0', '1', '2', '3'], true)) {
            throw new \InvalidArgumentException('Select supported SNMP settings.');
        }
        // Cross-field v3 requirements depend on each device's stored values.
        // Reuse configuration validation for individual types and storage limits.
        new DeviceSnmpConfiguration(array_replace($defaults, $fields, ['snmp_version' => '0']), true);
        if ($keep) {
            foreach (array_intersect_key($fields, DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS) as $value) {
                if ($value !== '') {
                    throw new \InvalidArgumentException('Choose Replace credentials before entering new credentials.');
                }
            }
        }
        $this->fields = ['keep_credentials' => $keep] + $fields;
    }

    /** @return array<string, string> */
    public function resolve(#[\SensitiveParameter] array $stored): array
    {
        $defaults = DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS;
        if (array_diff_key($defaults, $stored) !== [] || array_diff_key($stored, $defaults) !== []) {
            throw new \InvalidArgumentException('Submit all SNMP settings.');
        }
        $selected = $this->fields;
        unset($selected['keep_credentials']);
        if ($this->fields['keep_credentials']) {
            $selected = array_diff_key($selected, DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS);
        }
        return (new DeviceSnmpConfiguration(array_replace($stored, $selected)))->fields;
    }
}
