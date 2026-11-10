<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Domain;

/** An SNMP notification receiver as the editor shows it: never its credentials. */
final readonly class NotificationReceiver
{
    public function __construct(
        public int $id,
        public string $description,
        public string $hostname,
        public bool $disabled,
        public int $maxLogSize,
        public int $version,
        public string $authProtocol,
        public string $privProtocol,
        public string $engineId,
        public int $port,
        public int $messageType,
        public string $notes,
        public string $revision,
    ) {}

    /** @return array<string, bool|int|string> keyed by NotificationReceiverChange::SETTINGS */
    public function settings(): array
    {
        return [
            'description' => $this->description,
            'hostname' => $this->hostname,
            'disabled' => $this->disabled,
            'max_log_size' => $this->maxLogSize,
            'snmp_version' => $this->version,
            'snmp_auth_protocol' => $this->authProtocol,
            'snmp_priv_protocol' => $this->privProtocol,
            'snmp_engine_id' => $this->engineId,
            'snmp_port' => $this->port,
            'snmp_message_type' => $this->messageType,
            'notes' => $this->notes,
        ];
    }

    /**
     * Credentials are part of the digest, so an editor goes stale when another
     * administrator replaces them. The digest is keyed because the page carries
     * it: an unkeyed hash would let a reader test guesses of the credentials.
     *
     * @param array<string, mixed> $row every snmpagent_managers column
     * @param string $key a server-side secret, never sent to the browser
     */
    public static function revisionOf(#[\SensitiveParameter] array $row, #[\SensitiveParameter] string $key): string
    {
        $values = [];
        foreach (['id', ...NotificationReceiverChange::COLUMNS] as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new \UnexpectedValueException('The notification receiver row is incomplete.');
            }
            $values[] = $value === null ? null : (string) $value;
        }
        return hash_hmac('sha256', "notification-receiver-v1\0" . json_encode($values, JSON_THROW_ON_ERROR), $key);
    }
}
