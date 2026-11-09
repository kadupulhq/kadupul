<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Automation\Domain;

/**
 * One entry of an SNMP Option Set without its credentials. The community,
 * username, authentication password and privacy passphrase stay in the store.
 */
final readonly class SnmpOptionEntry
{
    public function __construct(
        public int $id,
        public int $setId,
        public int $sequence,
        public int $version,
        public int $port,
        public int $timeout,
        public int $retries,
        public int $maxOids,
        public int $bulkWalkSize,
        public string $authProtocol,
        public string $privProtocol,
        public string $context,
        public string $engineId,
        public string $revision,
    ) {}

    /** @return array<string, int|string> the settings an editor shows, keyed like SnmpOptionEntryChange */
    public function settings(): array
    {
        return [
            'snmp_version' => $this->version,
            'snmp_port' => $this->port,
            'snmp_timeout' => $this->timeout,
            'snmp_retries' => $this->retries,
            'max_oids' => $this->maxOids,
            'bulk_walk_size' => $this->bulkWalkSize,
            'snmp_auth_protocol' => $this->authProtocol,
            'snmp_priv_protocol' => $this->privProtocol,
            'snmp_context' => $this->context,
            'snmp_engine_id' => $this->engineId,
        ];
    }

    /**
     * Credentials are part of the digest, so an editor goes stale when another
     * administrator replaces them. The sequence is left out: reordering must
     * not invalidate an open editor.
     *
     * @param array<string, mixed> $row every automation_snmp_items column except sequence
     */
    public static function revisionOf(array $row): string
    {
        $values = [];
        foreach (['id', 'snmp_id', ...SnmpOptionEntryChange::COLUMNS] as $column) {
            $value = $row[$column] ?? null;
            if ($value !== null && !is_scalar($value)) {
                throw new \UnexpectedValueException('The SNMP entry row is incomplete.');
            }
            $values[] = $value === null ? null : (string) $value;
        }
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    /** @param list<array<string, mixed>> $rows id and sequence of every entry in discovery order */
    public static function orderOf(array $rows): string
    {
        return hash('sha256', json_encode(array_map(
            static fn(array $row): array => [is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0, is_numeric($row['sequence'] ?? null) ? (int) $row['sequence'] : 0],
            $rows,
        ), JSON_THROW_ON_ERROR));
    }
}
