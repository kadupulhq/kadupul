<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Installer-owned routine: no parameters, no mutation, one bounded result. */
final class CdefReferenceReadinessProcedure
{
    public const NAME = 'kadupul_cdef_reference_status';

    public function __construct(private readonly \PDO $database) {}

    public function installed(): bool
    {
        $rows = $this->read("SELECT ROUTINE_TYPE, SECURITY_TYPE, SQL_DATA_ACCESS, ROUTINE_DEFINITION, DEFINER"
            . " FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='" . self::NAME . "'");
        if ($rows === []) {
            return false;
        }
        $actor = $this->read('SELECT CURRENT_USER() AS actor')[0]['actor'] ?? null;
        if (count($rows) !== 1 || $rows[0]['ROUTINE_TYPE'] !== 'PROCEDURE' || $rows[0]['SECURITY_TYPE'] !== 'DEFINER'
            || $rows[0]['SQL_DATA_ACCESS'] !== 'READS SQL DATA' || $rows[0]['DEFINER'] !== $actor
            || !is_string($rows[0]['ROUTINE_DEFINITION'])
            || self::normalized($rows[0]['ROUTINE_DEFINITION']) !== self::normalized($this->body())
            || (int) ($this->read("SELECT COUNT(*) AS count FROM information_schema.PARAMETERS WHERE SPECIFIC_SCHEMA=DATABASE()"
                . " AND SPECIFIC_NAME='" . self::NAME . "'")[0]['count'] ?? -1) !== 0) {
            throw new \RuntimeException('An existing CDEF readiness routine differs from the reviewed definition or installer identity.');
        }

        return true;
    }

    public function install(): void
    {
        if (!$this->installed()) {
            if ($this->database->exec('CREATE PROCEDURE ' . self::NAME . '() SQL SECURITY DEFINER READS SQL DATA ' . $this->body()) === false
                || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('The CDEF readiness routine could not be installed. Applied DDL is not rolled back.');
            }
        }
        if (!$this->installed()) {
            throw new \RuntimeException('The CDEF readiness routine installation could not be confirmed.');
        }
    }

    /** Native metadata stays inside the installer security context at CALL. */
    public function body(): string
    {
        $context = $this->read('SELECT DATABASE() AS schema_name, CURRENT_USER() AS actor, VERSION() AS server_version')[0] ?? [];
        if (!is_string($context['schema_name'] ?? null) || $context['schema_name'] === '' || !is_string($context['actor'] ?? null)) {
            throw new \RuntimeException('The CDEF readiness routine requires an explicit native installation database.');
        }
        $quote = function (string $value): string {
            $literal = $this->database->quote($value);
            if ($literal === false) {
                throw new \RuntimeException('A CDEF readiness metadata literal could not be confirmed.');
            }

            return $literal;
        };
        $definitions = [];
        foreach (CdefReferenceTriggers::definitions() as $name => $definition) {
            $definitions[] = '(BINARY TRIGGER_NAME=' . $quote($name)
                . ' AND EVENT_MANIPULATION=' . $quote($definition['event'])
                . ' AND EVENT_OBJECT_TABLE=' . $quote($definition['table'])
                . ' AND BINARY DEFINER=' . $quote($context['actor'])
                . ' AND SHA2(BINARY ACTION_STATEMENT,256)=' . $quote(hash('sha256', $definition['body'])) . ')';
        }
        $schema = $quote($context['schema_name']);
        $tables = implode(',', array_map($quote, ['cdef', 'cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item']));
        $visible = str_contains($context['server_version'], 'MariaDB') ? "IGNORED='NO'" : "IS_VISIBLE='YES'";

        return 'SELECT 1 AS contract_version, ('
            . '(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' . $schema
            . " AND ACTION_TIMING='BEFORE' AND EVENT_OBJECT_TABLE IN ($tables))=10 AND "
            . '(SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=' . $schema
            . " AND ACTION_TIMING='BEFORE' AND (" . implode(' OR ', $definitions) . '))=10 AND '
            . '(SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=' . $schema
            . " AND TABLE_NAME IN ($tables) AND ENGINE='InnoDB' AND TABLE_TYPE='BASE TABLE')=5 AND "
            . '(SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=' . $schema
            . " AND TABLE_NAME IN ('aggregate_graph_templates_item','aggregate_graphs_graph_item')"
            . " AND COLUMN_NAME='cdef_id' AND SEQ_IN_INDEX=1 AND SUB_PART IS NULL AND INDEX_TYPE='BTREE' AND $visible)=2"
            . ') AS ready';
    }

    private static function normalized(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /** @return list<array<string, mixed>> */
    private function read(string $sql): array
    {
        $statement = $this->database->query($sql);
        if ($statement === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF routine metadata read could not be confirmed.');
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if ($statement->errorCode() !== '00000') {
            throw new \RuntimeException('The native CDEF routine metadata fetch could not be confirmed.');
        }

        return $rows;
    }
}
