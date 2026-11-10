<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/**
 * A submitted SNMP Option Set entry. Stored credentials are never shown, so
 * an edit either keeps them or replaces all four; resolve() combines the
 * choice with the stored row inside the write transaction.
 */
final readonly class SnmpOptionEntryChange
{
    /** Every stored column except id, snmp_id and sequence, in schema order. */
    public const array COLUMNS = ['snmp_version', 'snmp_community', 'snmp_port', 'snmp_timeout', 'snmp_retries', 'max_oids', 'bulk_walk_size',
        'snmp_username', 'snmp_password', 'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id'];
    public const array CREDENTIALS = ['snmp_community' => 100, 'snmp_username' => 50, 'snmp_password' => 50, 'snmp_priv_passphrase' => 200];
    public const array VERSIONS = [1, 2, 3];
    public const array AUTH_PROTOCOLS = ['[None]', 'MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
    public const array PRIVACY_PROTOCOLS = ['[None]', 'DES', 'AES', 'AES128', 'AES192', 'AES192C', 'AES256', 'AES256C'];
    /** The legacy drop-down choices. */
    public const array MAX_OIDS = [1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60];
    public const array BULK_WALK_SIZES = [-1, 0, 1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60];
    /** Legacy accepted up to eight digits; retries also fit the tinyint column. */
    public const int TIMEOUT_MAX = 99999999;
    public const int RETRIES_MAX = 255;

    /** @var array<string, int|string> */
    public array $settings;
    /** @var array<string, string> */
    private array $credentials;

    /**
     * @param array<string, mixed> $settings snmp_version, snmp_port, snmp_timeout, snmp_retries, max_oids,
     *        bulk_walk_size, snmp_auth_protocol, snmp_priv_protocol, snmp_context and snmp_engine_id
     * @param array<string, mixed> $credentials the four CREDENTIALS keys; all empty when $keepCredentials
     */
    public function __construct(array $settings, public bool $keepCredentials, #[\SensitiveParameter] array $credentials)
    {
        $expected = ['snmp_version', 'snmp_port', 'snmp_timeout', 'snmp_retries', 'max_oids', 'bulk_walk_size', 'snmp_auth_protocol', 'snmp_priv_protocol', 'snmp_context', 'snmp_engine_id'];
        $keys = array_flip($expected);
        if (array_diff_key($keys, $settings) !== [] || array_diff_key($settings, $keys) !== []
            || array_diff_key(self::CREDENTIALS, $credentials) !== [] || array_diff_key($credentials, self::CREDENTIALS) !== []) {
            throw new \InvalidArgumentException('Submit all SNMP settings.');
        }
        $settings = array_replace($keys, $settings);
        $integers = [
            'snmp_version' => self::VERSIONS,
            'snmp_port' => [1, 65535],
            'snmp_timeout' => [0, self::TIMEOUT_MAX],
            'snmp_retries' => [0, self::RETRIES_MAX],
            'max_oids' => self::MAX_OIDS,
            'bulk_walk_size' => self::BULK_WALK_SIZES,
        ];
        foreach ($integers as $key => $allowed) {
            $value = $settings[$key];
            $valid = is_int($value) && (in_array($key, ['snmp_port', 'snmp_timeout', 'snmp_retries'], true)
                ? $value >= $allowed[0] && $value <= $allowed[1]
                : in_array($value, $allowed, true));
            if (!$valid) {
                throw new \InvalidArgumentException('Select supported SNMP settings.');
            }
        }
        if (!in_array($settings['snmp_auth_protocol'], self::AUTH_PROTOCOLS, true) || !in_array($settings['snmp_priv_protocol'], self::PRIVACY_PROTOCOLS, true)) {
            throw new \InvalidArgumentException('Select supported SNMP settings.');
        }
        foreach (['snmp_context' => 64, 'snmp_engine_id' => 64] as $key => $limit) {
            self::assertText($settings[$key], $limit);
        }
        foreach (self::CREDENTIALS as $key => $limit) {
            self::assertText($credentials[$key], $limit);
            if ($keepCredentials && $credentials[$key] !== '') {
                throw new \InvalidArgumentException('Choose Replace credentials before entering new credentials.');
            }
        }
        if ($settings['snmp_version'] !== 3) {
            // Only SNMPv3 uses these; the legacy form hid them for other versions.
            $settings = array_replace($settings, ['snmp_auth_protocol' => '[None]', 'snmp_priv_protocol' => '[None]', 'snmp_context' => '', 'snmp_engine_id' => '']);
        } elseif ($settings['snmp_priv_protocol'] !== '[None]' && $settings['snmp_auth_protocol'] === '[None]') {
            throw new \InvalidArgumentException('SNMPv3 privacy also requires authentication.');
        }
        /** @var array<string, int|string> $settings validated above */
        $this->settings = $settings;
        /** @var array<string, string> $credentials validated above */
        $this->credentials = $credentials;
        if (!$keepCredentials) {
            self::assertCredentials($this->row([]));
        }
    }

    /**
     * The complete column set to store. Kept credentials come from $stored;
     * SNMPv3 credentials are cleared when the entry is not SNMPv3.
     *
     * @param array<string, mixed> $stored the current row, or [] for a new entry
     * @return array<string, int|string>
     */
    public function resolve(#[\SensitiveParameter] array $stored): array
    {
        if ($this->keepCredentials && $stored === []) {
            throw new \InvalidArgumentException('Enter the credentials for a new SNMP entry.');
        }
        $row = $this->row($stored);
        self::assertCredentials($row);
        return $row;
    }

    /**
     * @param array<string, mixed> $stored
     * @return array<string, int|string>
     */
    private function row(#[\SensitiveParameter] array $stored): array
    {
        $credentials = [];
        foreach (array_keys(self::CREDENTIALS) as $key) {
            $kept = $stored[$key] ?? '';
            $credentials[$key] = $this->keepCredentials ? (is_scalar($kept) ? (string) $kept : '') : $this->credentials[$key];
            if ($this->settings['snmp_version'] !== 3 && $key !== 'snmp_community') {
                $credentials[$key] = '';
            }
        }
        $values = $this->settings + $credentials;
        $row = [];
        foreach (self::COLUMNS as $column) {
            $row[$column] = $values[$column];
        }
        return $row;
    }

    /** @param array<string, int|string> $row */
    private static function assertCredentials(#[\SensitiveParameter] array $row): void
    {
        if ($row['snmp_version'] !== 3) {
            if ($row['snmp_community'] === '') {
                throw new \InvalidArgumentException('SNMP versions 1 and 2 require a community.');
            }
            return;
        }
        if ($row['snmp_username'] === '') {
            throw new \InvalidArgumentException('SNMPv3 requires a username.');
        }
        if (($row['snmp_auth_protocol'] !== '[None]' && strlen((string) $row['snmp_password']) < 8)
            || ($row['snmp_priv_protocol'] !== '[None]' && strlen((string) $row['snmp_priv_passphrase']) < 8)) {
            throw new \InvalidArgumentException('SNMPv3 authentication and privacy passphrases require at least 8 bytes.');
        }
    }

    private static function assertText(mixed $value, int $limit): void
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('SNMP fields must contain valid text.');
        }
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw new \InvalidArgumentException('An SNMP field exceeds its maximum length.');
        }
    }
}
