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
    public function publicSettings(): array
    {
        $fields = array_intersect_key($this->fields, DeviceSnmpConfiguration::PUBLIC_DEFAULTS);
        if ($fields['snmp_version'] !== '3') {
            $fields = array_replace($fields, ['snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => '']);
        }
        return $fields;
    }
    public function resolve(#[\SensitiveParameter] array $stored): array
    {
        $fields = $this->fields;
        if ($fields['keep_credentials']) {
            foreach (DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $key => $empty) {
                $fields[$key] = (string) ($stored[$key] ?? '');
            }
        }
        unset($fields['keep_credentials']);
        $fields = (new DeviceSnmpConfiguration($fields))->fields;
        return array_replace($fields, $this->publicSettings());
    }
}
