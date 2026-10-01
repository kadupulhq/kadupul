<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * Runtime needs EXECUTE on the installer-verified status routine, not DDL
 * privileges or the installer's login. Privileged DROP/TRUNCATE/forged routines
 * remain outside this DML contract; no settings flag substitutes for metadata.
 */
final class CdefReferenceReadiness implements \Kadupul\Platform\Contract\CdefReferenceReadiness
{
    public function __construct(private readonly \PDO $database, private readonly mixed $collectorId) {}

    public function assertReady(): void
    {
        if (!$this->ready()) {
            throw new \RuntimeException('The primary CDEF reference contract is not ready. Run cli/upgrade_database.php --install-cdef-reference-contract on the primary installation.');
        }
    }

    public function ready(): bool
    {
        if ($this->collectorId !== 1 || $this->database->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return false;
        }
        foreach (['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $create = $this->read("SHOW CREATE TABLE `$table`")[0]['Create Table'] ?? '';
            if (!is_string($create) || preg_match('/\bENGINE=InnoDB\b/i', $create) !== 1 || preg_match('/\bTEMPORARY\b/i', $create)) {
                return false;
            }
            $columns = [];
            foreach ($this->read("SHOW COLUMNS FROM `$table`") as $column) {
                $columns[$column['Field']] = $column;
            }
            $identity = $columns[$table === 'cdef' ? 'id' : 'cdef_id'] ?? [];
            if (preg_match('/^mediumint(?:\([0-9]+\))? unsigned$/D', $identity['Type'] ?? '') !== 1
                || str_contains(strtoupper($identity['Extra'] ?? ''), 'GENERATED')) {
                return false;
            }
        }
        $name = CdefReferenceReadinessProcedure::NAME;
        $routines = $this->read("SELECT ROUTINE_TYPE, SECURITY_TYPE, SQL_DATA_ACCESS, DEFINER FROM information_schema.ROUTINES"
            . " WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='$name'");
        if (count($routines) !== 1 || $routines[0]['ROUTINE_TYPE'] !== 'PROCEDURE' || $routines[0]['SECURITY_TYPE'] !== 'DEFINER'
            || $routines[0]['SQL_DATA_ACCESS'] !== 'READS SQL DATA' || !is_string($routines[0]['DEFINER'])
            || $routines[0]['DEFINER'] === '') {
            return false;
        }
        if ((int) ($this->read("SELECT COUNT(*) AS count FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE()"
            . " AND SPECIFIC_NAME='$name'")[0]['count'] ?? -1) !== 0) {
            return false;
        }
        $statement = $this->database->query("CALL $name()");
        if ($statement === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF readiness call could not be confirmed.');
        }
        try {
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $second = $statement->fetch(\PDO::FETCH_ASSOC);
            $this->assertStatement($statement, 'result');
            $valid = is_array($row) && $second === false && array_keys($row) === ['contract_version', 'ready']
                && in_array($row['contract_version'], [1, '1'], true) && in_array($row['ready'], [1, '1'], true);

            // PDO_mysql retains the preceding column count on the empty terminal
            // CALL status rowset on both supported engines. Inspect bounded data,
            // allowing precisely that one status rowset, never arbitrary results.
            $terminal = $statement->nextRowset();
            $this->assertStatement($statement, 'rowsets');
            if (!$terminal) {
                return false;
            }
            $extra = $statement->fetch(\PDO::FETCH_ASSOC);
            $this->assertStatement($statement, 'terminal result');
            if ($extra !== false) {
                return false;
            }
            $additional = $statement->nextRowset();
            $this->assertStatement($statement, 'terminal rowsets');

            return $valid && !$additional;
        } finally {
            if (!$statement->closeCursor() || $statement->errorCode() !== '00000') {
                throw new \RuntimeException('The native CDEF readiness cursor close could not be confirmed.');
            }
        }
    }

    private function assertStatement(\PDOStatement $statement, string $operation): void
    {
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException("The native CDEF readiness $operation could not be confirmed.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function read(string $sql): array
    {
        $statement = $this->database->query($sql);
        if ($statement === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF readiness metadata read could not be confirmed.');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF readiness metadata fetch could not be confirmed.');
        }

        return $rows;
    }
}
