<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Domain;

final readonly class NewDevice
{
    public const DEFAULTS = ['description' => '', 'hostname' => '', 'notes' => '', 'location' => '', 'external_id' => '', 'enabled' => true,
        'host_template_id' => '0', 'site_id' => '0', 'poller_id' => '1', 'device_threads' => '1',
        'snmp_version' => '2', 'snmp_community' => '', 'snmp_username' => '', 'snmp_password' => '', 'snmp_auth_protocol' => '[None]',
        'snmp_priv_passphrase' => '', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => '',
        'snmp_port' => '161', 'snmp_timeout' => '500', 'max_oids' => '10', 'bulk_walk_size' => '0',
        'availability_method' => '2', 'ping_method' => '1', 'ping_port' => '23', 'ping_timeout' => '500', 'ping_retries' => '2', 'use_default_credentials' => true];
    public const AUTH_PROTOCOLS = ['[None]', 'MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
    public const PRIVACY_PROTOCOLS = ['[None]', 'DES', 'AES', 'AES128', 'AES192', 'AES192C', 'AES256', 'AES256C'];
    public array $fields;

    public function __construct(#[\SensitiveParameter] array $values)
    {
        if (array_diff_key($values, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('Unexpected fields were submitted.');
        }
        $fields = array_replace(self::DEFAULTS, $values);
        $integers = ['host_template_id' => [0, 16777215], 'site_id' => [0, 4294967295], 'poller_id' => [1, 65535], 'device_threads' => [1, 255], 'snmp_port' => [1, 65535], 'snmp_timeout' => [1, 16777215], 'max_oids' => [1, 60], 'ping_port' => [0, 65535], 'ping_timeout' => [1, 4294967295], 'ping_retries' => [0, 100]];
        foreach ($fields as $key => $value) {
            if (in_array($key, ['enabled', 'use_default_credentials'], true)) {
                if (!is_bool($value)) {
                    throw new \InvalidArgumentException('Choose valid polling and credential options.');
                }
                continue;
            }
            if (isset($integers[$key]) && is_int($value)) {
                $fields[$key] = $value = (string) $value;
            }
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('Device fields must contain valid text.');
            }
        }
        $device = new Device(0, '', '', '', true, '', '');
        $device->revise($fields['description'], $fields['hostname'], $fields['notes'], $fields['enabled'], $fields['location'], $fields['external_id'], $device->revision());
        $fields['description'] = $device->description();
        $fields['hostname'] = $device->hostname();
        foreach ($integers as $key => [$minimum, $maximum]) {
            if (!ctype_digit($fields[$key]) || strlen($fields[$key]) > 10 || (int) $fields[$key] < $minimum || (int) $fields[$key] > $maximum) {
                throw new \InvalidArgumentException('A device numeric setting is outside its supported range.');
            }
        }
        foreach (['snmp_version' => ['0', '1', '2', '3'], 'availability_method' => ['0', '1', '2', '3', '4', '5', '6'], 'ping_method' => ['1', '2', '3', '5'], 'snmp_auth_protocol' => self::AUTH_PROTOCOLS, 'snmp_priv_protocol' => self::PRIVACY_PROTOCOLS] as $key => $allowed) {
            if (!in_array($fields[$key], $allowed, true)) {
                throw new \InvalidArgumentException('Select supported SNMP and availability settings.');
            }
        }
        if ($fields['bulk_walk_size'] !== '-1' && (!ctype_digit($fields['bulk_walk_size']) || strlen($fields['bulk_walk_size']) > 3 || (int) $fields['bulk_walk_size'] > 60)) {
            throw new \InvalidArgumentException('Bulk walk size must be -1, 0 or between 1 and 60.');
        }
        foreach (['snmp_community' => 100, 'snmp_username' => 50, 'snmp_password' => 50, 'snmp_priv_passphrase' => 200, 'snmp_context' => 64, 'snmp_engine_id' => 64] as $key => $limit) {
            if (mb_strlen($fields[$key], 'UTF-8') > $limit) {
                throw new \InvalidArgumentException('An SNMP field exceeds its maximum length.');
            }
        }
        if ($fields['use_default_credentials'] && ($fields['snmp_community'] !== '' || $fields['snmp_password'] !== '' || $fields['snmp_priv_passphrase'] !== '')) {
            throw new \InvalidArgumentException('Clear Use configured credentials to enter device-specific credentials.');
        }
        if ($fields['snmp_version'] === '3') {
            if ((!$fields['use_default_credentials'] && $fields['snmp_username'] === '') || ($fields['snmp_priv_protocol'] !== '[None]' && $fields['snmp_auth_protocol'] === '[None]')) {
                throw new \InvalidArgumentException('SNMPv3 requires a username; privacy also requires authentication.');
            }
            if (!$fields['use_default_credentials'] && (($fields['snmp_auth_protocol'] !== '[None]' && strlen($fields['snmp_password']) < 8) || ($fields['snmp_priv_protocol'] !== '[None]' && strlen($fields['snmp_priv_passphrase']) < 8))) {
                throw new \InvalidArgumentException('SNMPv3 authentication and privacy passphrases require at least 8 bytes.');
            }
        }
        $this->fields = $fields;
    }
}
