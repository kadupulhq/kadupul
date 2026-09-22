<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Verify required legacy writes even when procedural helpers swallow SQL errors. */
final class DeviceCreationVerifier
{
    public function verify(\PDO $primary, ?\PDO $remote, int $deviceId, int $templateId, bool $lockPrimary = false): void
    {
        if ($lockPrimary && !$primary->inTransaction()) {
            throw new \LogicException('Current association checks require a transaction');
        }
        $lock = $lockPrimary ? ' LOCK IN SHARE MODE' : '';
        foreach ([
            ['host_graph', 'graph_template_id', 'host_template_graph'],
            ['host_snmp_query', 'snmp_query_id', 'host_template_snmp_query'],
        ] as [$table, $key, $templateTable]) {
            $expected = $this->rows($primary, "SELECT $key FROM $templateTable WHERE host_template_id = ? ORDER BY $key" . $lock, $templateId);
            $actual = $this->rows($primary, "SELECT $key FROM $table WHERE host_id = ? ORDER BY $key" . $lock, $deviceId);
            foreach ($expected as $row) {
                if (!in_array($row, $actual)) {
                    throw new \RuntimeException('Required device association was not saved');
                }
            }
            if ($remote !== null) {
                $columns = $key === 'snmp_query_id' ? 'snmp_query_id, reindex_method' : $key;
                $sql = "SELECT $columns FROM $table WHERE host_id = ? ORDER BY $key";
                if ($this->rows($primary, $sql . $lock, $deviceId) != $this->rows($remote, $sql, $deviceId)) {
                    throw new \RuntimeException('Collector associations could not be confirmed');
                }
            }
        }
    }

    private function rows(\PDO $connection, string $sql, int $id): array
    {
        $statement = $connection->prepare($sql);
        if ($statement === false || !$statement->execute([$id])) {
            throw new \RuntimeException('Device association verification unavailable');
        }

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
