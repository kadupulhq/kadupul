<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

/** Explicit credential replacement; existing secrets are resolved only by the worker. */
final readonly class DeviceSnmpChange
{
    public array $fields;
    /**
     * Validates an SNMP change for one device.
     * Stored credentials may be kept only when the credential fields are empty.
     *
     * @param array $fields All SNMP fields plus a boolean "keep_credentials".
     * @throws \InvalidArgumentException When a field is invalid or credentials are both kept and replaced.
     */
    public function __construct(#[\SensitiveParameter] array $fields)
    {
        if (!is_bool($fields['keep_credentials'] ?? null)) {
            throw new \InvalidArgumentException('Choose whether to use stored credentials or replace them.');
        }
        $keep = $fields['keep_credentials'];
        unset($fields['keep_credentials']);
        $configuration = new DeviceSnmpConfiguration($fields, $keep);
        if ($keep) {
            foreach (DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $key => $empty) {
                if ($fields[$key] !== '') {
                    throw new \InvalidArgumentException('Choose Replace credentials before entering new credentials.');
                }
            }
        }
        $this->fields = ['keep_credentials' => $keep] + $configuration->fields;
    }
    /**
     * Returns the non-secret SNMP fields; for versions other than 3, the v3-only fields are reset.
     *
     * @return array Map from string to string.
     */
    public function publicSettings(): array
    {
        $fields = array_intersect_key($this->fields, DeviceSnmpConfiguration::PUBLIC_DEFAULTS);
        if ($fields['snmp_version'] !== '3') {
            $fields = array_replace($fields, ['snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => '']);
        }
        return $fields;
    }
    /**
     * Merges the stored credentials into this change when it keeps them, and validates the result.
     *
     * @param array $stored The device's stored SNMP settings, including credentials.
     * @return array The complete settings to save.
     */
    public function resolve(#[\SensitiveParameter] array $stored): array
    {
        $fields = $this->fields;
        foreach (DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $key => $empty) {
            if ($fields['snmp_version'] !== '3' && $key !== 'snmp_community') {
                $fields[$key] = '';
            } elseif ($fields['keep_credentials']) {
                $fields[$key] = (string) ($stored[$key] ?? '');
            }
        }
        unset($fields['keep_credentials']);
        $fields = (new DeviceSnmpConfiguration($fields))->fields;
        return array_replace($fields, $this->publicSettings());
    }
}
