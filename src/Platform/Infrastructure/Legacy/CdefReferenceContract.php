<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * Installer-only primary schema operation; DDL cannot be rolled back.
 * Metadata readiness is evaluated under the configured installer identity.
 * Runtime accounts must not use this API as their application readiness gate.
 */
final class CdefReferenceContract
{
    private const TABLES = ['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'];

    public function __construct(private readonly \PDO $database, private readonly mixed $collectorId) {}

    public function ready(): bool
    {
        $this->preflight();

        return count($this->installedDefinitions()) === count(CdefReferenceTriggers::definitions())
            && $this->missingIndexes() === [] && (new CdefReferenceReadinessProcedure($this->database))->installed();
    }

    public function install(): void
    {
        // Reject before any implicit-commit DDL, including when a caller owns
        // a transaction or the primary role was not explicitly established.
        $this->preflight();
        $installed = $this->installedDefinitions();
        (new CdefReferenceReadinessProcedure($this->database))->installed();
        $this->probeCapability();
        foreach ($this->missingIndexes() as $sql) {
            $this->execute($sql);
        }
        foreach (CdefReferenceTriggers::createStatements() as $name => $sql) {
            if (!isset($installed[$name])) {
                $this->execute($sql);
            }
        }
        (new CdefReferenceReadinessProcedure($this->database))->install();
        if (!$this->ready()) {
            throw new \RuntimeException('The CDEF reference contract is incomplete. Schema changes may already have been applied.');
        }
    }

    public function preflight(): void
    {
        if ($this->collectorId !== 1) {
            throw new \RuntimeException('The CDEF reference contract requires the explicitly configured primary collector.');
        }
        if ($this->database->inTransaction()) {
            throw new \RuntimeException('The CDEF schema operation cannot use a caller transaction.');
        }
        if ($this->database->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('The CDEF reference contract requires native MySQL or MariaDB.');
        }
        foreach (self::TABLES as $table) {
            $rows = $this->read("SHOW CREATE TABLE `$table`");
            $create = $rows[0]['Create Table'] ?? '';
            if (!is_string($create) || !preg_match('/\bENGINE=InnoDB\b/i', $create)
                || preg_match('/\bTEMPORARY\b/i', $create)) {
                throw new \RuntimeException('The CDEF reference contract requires persistent InnoDB tables.');
            }
            $columns = [];
            foreach ($this->read("SHOW COLUMNS FROM `$table`") as $column) {
                $columns[$column['Field']] = $column;
            }
            $identity = $columns[$table === 'cdef' ? 'id' : 'cdef_id'] ?? [];
            if (preg_match('/^mediumint(?:\([0-9]+\))? unsigned$/D', $identity['Type'] ?? '') !== 1
                || str_contains(strtoupper($identity['Extra'] ?? ''), 'GENERATED')) {
                throw new \RuntimeException('The CDEF reference contract requires the existing unsigned mediumint identity schema.');
            }
            if ($table === 'cdef') {
                $primary = array_values(array_filter($this->read('SHOW INDEX FROM cdef'), static fn(array $row): bool => $row['Key_name'] === 'PRIMARY'));
                if (count($primary) !== 1 || $primary[0]['Column_name'] !== 'id' || (int) $primary[0]['Non_unique'] !== 0) {
                    throw new \RuntimeException('The CDEF reference contract requires the unique parent identity.');
                }
            } elseif ($table === 'cdef_items') {
                if (preg_match('/^tinyint(?:\([0-9]+\))? unsigned$/D', $columns['type']['Type'] ?? '') !== 1
                    || ($columns['value']['Type'] ?? '') !== 'varchar(150)'
                    || ($columns['value']['Null'] ?? '') !== 'NO') {
                    throw new \RuntimeException('The CDEF reference contract requires the existing item type and target schema.');
                }
            }
        }
        // Existing unsafe data is reported, never silently repaired. All
        // queries must finish successfully even on silent-error PDO handles.
        $unsafe = [
            'SELECT 1 FROM cdef WHERE id=0 LIMIT 1',
            'SELECT 1 FROM cdef_items i LEFT JOIN cdef p ON p.id=i.cdef_id WHERE p.id IS NULL LIMIT 1',
            "SELECT 1 FROM cdef_items WHERE type=5 AND (value NOT REGEXP '^[1-9][0-9]{0,7}$'"
                . ' OR CAST(value AS UNSIGNED)>16777215 OR BINARY value <> BINARY CAST(CAST(value AS UNSIGNED) AS CHAR)) LIMIT 1',
            'SELECT 1 FROM cdef_items i LEFT JOIN cdef p ON p.id=CAST(i.value AS UNSIGNED) WHERE i.type=5 AND p.id IS NULL LIMIT 1',
        ];
        foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $unsafe[] = "SELECT 1 FROM `$table` i LEFT JOIN cdef p ON p.id=i.cdef_id"
                . ' WHERE i.cdef_id IS NOT NULL AND i.cdef_id<>0 AND p.id IS NULL LIMIT 1';
        }
        foreach ($unsafe as $sql) {
            if ($this->read($sql) !== []) {
                throw new \RuntimeException('Existing CDEF references are unsafe. Repair the reported data before installing the contract.');
            }
        }
        $this->installedDefinitions();
    }

    /** @return array<string, true> */
    private function installedDefinitions(): array
    {
        $expected = CdefReferenceTriggers::definitions();
        $installed = [];
        $actor = $this->read('SELECT CURRENT_USER() AS actor')[0]['actor'] ?? null;
        foreach ($this->read('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING, ACTION_STATEMENT, DEFINER'
            . ' FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()') as $row) {
            $name = $row['TRIGGER_NAME'];
            if (!isset($expected[$name])) {
                if ($row['ACTION_TIMING'] === 'BEFORE' && in_array($row['EVENT_OBJECT_TABLE'], self::TABLES, true)) {
                    throw new \RuntimeException('An existing CDEF table trigger requires explicit operator review.');
                }
                continue;
            }
            $definition = $expected[$name];
            if ($row['ACTION_TIMING'] !== 'BEFORE' || $row['EVENT_MANIPULATION'] !== $definition['event']
                || $row['EVENT_OBJECT_TABLE'] !== $definition['table'] || $row['DEFINER'] !== $actor
                || self::normalized($row['ACTION_STATEMENT']) !== self::normalized($definition['body'])) {
                throw new \RuntimeException('An existing CDEF contract trigger differs from the reviewed definition or installer identity.');
            }
            $installed[$name] = true;
        }

        return $installed;
    }

    /** @return list<string> */
    private function missingIndexes(): array
    {
        $missing = [];
        foreach (['aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            $rows = $this->read("SHOW INDEX FROM `$table`");
            $hasIndex = false;
            foreach ($rows as $row) {
                $usable = (int) $row['Seq_in_index'] === 1 && $row['Column_name'] === 'cdef_id'
                    && ($row['Sub_part'] ?? null) === null
                    && strtoupper($row['Index_type'] ?? '') === 'BTREE'
                    && strtoupper($row['Visible'] ?? 'YES') === 'YES'
                    && strtoupper($row['Ignored'] ?? 'NO') === 'NO';
                if ($row['Key_name'] === 'kadupul_cdef_reference' && !$usable) {
                    throw new \RuntimeException('An existing CDEF cache index is not the expected usable full-column index.');
                }
                $hasIndex = $hasIndex || $usable;
            }
            if (!$hasIndex) {
                $missing[] = "ALTER TABLE `$table` ADD INDEX `kadupul_cdef_reference` (`cdef_id`)";
            }
        }

        return $missing;
    }

    private function probeCapability(): void
    {
        $table = 'kadupul_cdef_cap_' . bin2hex(random_bytes(8));
        $trigger = $table . '_insert';
        $created = false;
        try {
            $this->execute("CREATE TABLE `$table` (id INT UNSIGNED PRIMARY KEY) ENGINE=InnoDB");
            $created = true;
            $this->execute("CREATE TRIGGER `$trigger` BEFORE INSERT ON `$table` FOR EACH ROW BEGIN"
                . ' DECLARE parent_id MEDIUMINT UNSIGNED DEFAULT NULL;'
                . ' DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_id=NULL;'
                . ' SELECT id INTO parent_id FROM cdef WHERE id=0 LOCK IN SHARE MODE;'
                . " IF NEW.id=2 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='CDEF capability rejection'; END IF; END");
            $this->execute("INSERT INTO `$table` VALUES (1)");
            try {
                $this->database->exec("INSERT INTO `$table` VALUES (2)");
                $info = $this->database->errorInfo();
            } catch (\PDOException $error) {
                $info = $error->errorInfo ?? [];
            }
            if (($info[0] ?? null) !== '45000' || (int) ($info[1] ?? 0) !== 1644
                || count($this->read("SELECT id FROM `$table`")) !== 1) {
                throw new \RuntimeException('The native CDEF trigger capability probe did not enforce its rejection.');
            }
        } finally {
            if ($created) {
                // Dropping this exclusively owned probe table removes its
                // trigger too. Cleanup failure is an explicit installer error.
                $this->execute("DROP TABLE `$table`");
            }
        }
    }

    private static function normalized(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    private function execute(string $sql): void
    {
        if ($this->database->exec($sql) === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF schema statement could not be confirmed. Applied DDL is not rolled back.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function read(string $sql): array
    {
        $statement = $this->database->query($sql);
        if ($statement === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF schema or reference read could not be confirmed.');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('The CDEF schema or reference fetch could not be confirmed.');
        }

        return $rows;
    }
}
