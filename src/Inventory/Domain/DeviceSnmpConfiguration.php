<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class DeviceSnmpConfiguration
{
    public const PUBLIC_DEFAULTS = ['snmp_version' => '2', 'snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => ''];
    public const CREDENTIAL_DEFAULTS = ['snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_priv_passphrase' => ''];
    public const AUTH_PROTOCOLS = ['[None]', 'MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
    public const PRIVACY_PROTOCOLS = ['[None]', 'DES', 'AES', 'AES128', 'AES192', 'AES192C', 'AES256', 'AES256C'];
    public array $fields;

    public function __construct(#[\SensitiveParameter] array $fields, bool $deferCredentials = false)
    {
        $defaults = self::PUBLIC_DEFAULTS + self::CREDENTIAL_DEFAULTS;
        if (array_diff_key($fields, $defaults) !== [] || array_diff_key($defaults, $fields) !== []) {
            throw new \InvalidArgumentException('Submit all SNMP settings.');
        }
        foreach ($fields as $value) {
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Device fields must contain valid text.');
            }
        }
        if (!in_array($fields['snmp_version'], ['0', '1', '2', '3'], true)
            || !in_array($fields['snmp_auth_protocol'], self::AUTH_PROTOCOLS, true)
            || !in_array($fields['snmp_priv_protocol'], self::PRIVACY_PROTOCOLS, true)) {
            throw new \InvalidArgumentException('Select supported SNMP settings.');
        }
        foreach (['snmp_community' => 100, 'snmp_username' => 50, 'snmp_password' => 50, 'snmp_priv_passphrase' => 200, 'snmp_context' => 64, 'snmp_engine_id' => 64] as $key => $limit) {
            if (mb_strlen($fields[$key], 'UTF-8') > $limit) {
                throw new \InvalidArgumentException('An SNMP field exceeds its maximum length.');
            }
        }
        if ($fields['snmp_version'] === '3') {
            if ((!$deferCredentials && $fields['snmp_username'] === '') || ($fields['snmp_priv_protocol'] !== '[None]' && $fields['snmp_auth_protocol'] === '[None]')) {
                throw new \InvalidArgumentException('SNMPv3 requires a username; privacy also requires authentication.');
            }
            if (!$deferCredentials && (($fields['snmp_auth_protocol'] !== '[None]' && strlen($fields['snmp_password']) < 8) || ($fields['snmp_priv_protocol'] !== '[None]' && strlen($fields['snmp_priv_passphrase']) < 8))) {
                throw new \InvalidArgumentException('SNMPv3 authentication and privacy passphrases require at least 8 bytes.');
            }
        }
        $this->fields = array_replace($defaults, $fields);
    }
}
