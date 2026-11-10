<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/**
 * A submitted SNMP notification receiver. Stored credentials are never shown,
 * so an edit either keeps them or replaces all four; resolve() combines the
 * choice with the stored row inside the write transaction.
 *
 * lib/snmpagent.php builds the snmptrap command from the stored row: SNMPv3
 * picks its security level from which secrets are present and passes the
 * protocols only for those levels, so each secret and its protocol must be
 * set together.
 */
final readonly class NotificationReceiverChange
{
    /** Every stored column except id, in schema order. */
    public const array COLUMNS = ['hostname', 'description', 'disabled', 'max_log_size', 'snmp_version', 'snmp_community', 'snmp_username', 'snmp_password',
        'snmp_auth_protocol', 'snmp_priv_passphrase', 'snmp_priv_protocol', 'snmp_engine_id', 'snmp_port', 'snmp_message_type', 'notes'];
    public const array SETTINGS = ['description', 'hostname', 'disabled', 'max_log_size', 'snmp_version', 'snmp_auth_protocol', 'snmp_priv_protocol',
        'snmp_engine_id', 'snmp_port', 'snmp_message_type', 'notes'];
    /** Column widths of the stored credentials. */
    public const array CREDENTIALS = ['snmp_community' => 100, 'snmp_username' => 50, 'snmp_password' => 50, 'snmp_priv_passphrase' => 200];
    public const array VERSIONS = [1, 2, 3];
    public const array AUTH_PROTOCOLS = ['[None]', 'MD5', 'SHA', 'SHA224', 'SHA256', 'SHA384', 'SHA512'];
    public const array PRIVACY_PROTOCOLS = ['[None]', 'DES', 'AES', 'AES128', 'AES192', 'AES192C', 'AES256', 'AES256C'];
    /** 1 sends notifications (traps) and 2 sends informs. */
    public const array MESSAGE_TYPES = [1, 2];
    public const int LOG_SIZE_MAX = 31;
    public const int DESCRIPTION_LENGTH = 255;
    public const int HOSTNAME_LENGTH = 100;
    public const int ENGINE_ID_LENGTH = 64;
    /** The notes column is TEXT. */
    public const int NOTES_BYTES = 65535;

    /** @var array<string, bool|int|string> */
    public array $settings;
    /** @var array<string, string> */
    private array $credentials;

    /**
     * @param array<string, mixed> $settings the SETTINGS keys
     * @param array<string, mixed> $credentials the four CREDENTIALS keys; all empty when $keepCredentials
     */
    public function __construct(array $settings, public bool $keepCredentials, #[\SensitiveParameter] array $credentials)
    {
        $keys = array_flip(self::SETTINGS);
        if (array_diff_key($keys, $settings) !== [] || array_diff_key($settings, $keys) !== []
            || array_diff_key(self::CREDENTIALS, $credentials) !== [] || array_diff_key($credentials, self::CREDENTIALS) !== []) {
            throw new \InvalidArgumentException('Submit every notification receiver field.');
        }
        $settings = array_replace($keys, $settings);
        foreach (['description' => self::DESCRIPTION_LENGTH, 'hostname' => self::HOSTNAME_LENGTH] as $key => $limit) {
            $settings[$key] = trim(self::line($settings[$key], $limit));
            if ($settings[$key] === '') {
                throw new \InvalidArgumentException('Enter a description and a hostname.');
            }
        }
        $ranges = ['max_log_size' => [1, self::LOG_SIZE_MAX], 'snmp_port' => [1, 65535]];
        foreach ($ranges as $key => [$min, $max]) {
            if (!is_int($settings[$key]) || $settings[$key] < $min || $settings[$key] > $max) {
                throw new \InvalidArgumentException('Select supported notification receiver settings.');
            }
        }
        if (!is_bool($settings['disabled']) || !in_array($settings['snmp_version'], self::VERSIONS, true)
            || !in_array($settings['snmp_message_type'], self::MESSAGE_TYPES, true)
            || !in_array($settings['snmp_auth_protocol'], self::AUTH_PROTOCOLS, true)
            || !in_array($settings['snmp_priv_protocol'], self::PRIVACY_PROTOCOLS, true)) {
            throw new \InvalidArgumentException('Select supported notification receiver settings.');
        }
        self::line($settings['snmp_engine_id'], self::ENGINE_ID_LENGTH);
        $notes = $settings['notes'];
        if (!is_string($notes) || !mb_check_encoding($notes, 'UTF-8') || str_contains($notes, "\0") || strlen($notes) > self::NOTES_BYTES) {
            throw new \InvalidArgumentException('Notes must be valid text of at most 65535 bytes.');
        }
        foreach (self::CREDENTIALS as $key => $limit) {
            self::line($credentials[$key], $limit);
            if ($keepCredentials && $credentials[$key] !== '') {
                throw new \InvalidArgumentException('Choose Replace credentials before entering new credentials.');
            }
        }
        if ($settings['snmp_version'] !== 3) {
            // Only SNMPv3 uses these; legacy stored them empty for other versions.
            $settings = array_replace($settings, ['snmp_auth_protocol' => '', 'snmp_priv_protocol' => '', 'snmp_engine_id' => '']);
        }
        /** @var array<string, bool|int|string> $settings validated above */
        $this->settings = $settings;
        /** @var array<string, string> $credentials validated above */
        $this->credentials = $credentials;
        if (!$keepCredentials) {
            self::assertCredentials($this->row([]));
        }
    }

    /**
     * The complete column set to store. Kept credentials come from $stored;
     * SNMPv3 credentials are cleared when the receiver is not SNMPv3.
     *
     * @param array<string, mixed> $stored the current row, or [] for a new receiver
     * @return array<string, int|string>
     */
    public function resolve(#[\SensitiveParameter] array $stored): array
    {
        if ($this->keepCredentials && $stored === []) {
            throw new \InvalidArgumentException('Enter the credentials for a new notification receiver.');
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
        $values = ['disabled' => $this->settings['disabled'] === true ? 'on' : ''] + $this->settings;
        foreach (array_keys(self::CREDENTIALS) as $key) {
            $kept = $stored[$key] ?? '';
            $values[$key] = $this->keepCredentials ? (is_scalar($kept) ? (string) $kept : '') : $this->credentials[$key];
            if ($this->settings['snmp_version'] !== 3 && $key !== 'snmp_community') {
                $values[$key] = '';
            }
        }
        $row = [];
        foreach (self::COLUMNS as $column) {
            $value = $values[$column];
            $row[$column] = is_bool($value) ? ($value ? 'on' : '') : $value;
        }
        return $row;
    }

    /** @param array<string, int|string> $row */
    private static function assertCredentials(#[\SensitiveParameter] array $row): void
    {
        foreach (array_keys(self::CREDENTIALS) as $key) {
            // Kept legacy values reach the snmptrap command line too.
            if (preg_match('/[\x00-\x1f\x7f]/', (string) $row[$key]) === 1) {
                throw new \InvalidArgumentException('Replace stored credentials that contain control characters.');
            }
        }
        if ($row['snmp_version'] !== 3) {
            return;
        }
        if ($row['snmp_username'] === '' || $row['snmp_engine_id'] === '') {
            throw new \InvalidArgumentException('SNMPv3 requires a username and an engine ID.');
        }
        $password = (string) $row['snmp_password'];
        $passphrase = (string) $row['snmp_priv_passphrase'];
        if (($password !== '') !== ($row['snmp_auth_protocol'] !== '[None]')
            || ($passphrase !== '') !== ($row['snmp_priv_protocol'] !== '[None]')) {
            throw new \InvalidArgumentException('Set an SNMPv3 authentication or privacy protocol together with its passphrase.');
        }
        if ($passphrase !== '' && $password === '') {
            throw new \InvalidArgumentException('SNMPv3 privacy also requires authentication.');
        }
        if (($password !== '' && strlen($password) < 8) || ($passphrase !== '' && strlen($passphrase) < 8)) {
            throw new \InvalidArgumentException('SNMPv3 authentication and privacy passphrases require at least 8 bytes.');
        }
    }

    /** Single-line text: valid UTF-8 without control characters. */
    private static function line(#[\SensitiveParameter] mixed $value, int $limit): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('Notification receiver fields must contain valid single-line text.');
        }
        if (mb_strlen($value, 'UTF-8') > $limit) {
            throw new \InvalidArgumentException('A notification receiver field exceeds its maximum length.');
        }
        return $value;
    }
}
