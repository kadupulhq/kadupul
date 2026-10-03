<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/**
 * Native primary-database DML guards. Installation is deliberately separate:
 * collector graph items do not have local CDEF parents, and DDL privileges can
 * bypass triggers. This plan must not be treated as an installed contract.
 */
final class CdefReferenceTriggers
{
    /** @return array<string, array{table: string, event: string, body: string}> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $definitions[self::name($table, $event)] = [
                    'table' => $table,
                    'event' => $event,
                    'body' => self::optionalParent(),
                ];
            }
        }
        foreach (['INSERT', 'UPDATE'] as $event) {
            $definitions[self::name('cdef_items', $event)] = [
                'table' => 'cdef_items',
                'event' => $event,
                'body' => self::itemParents(),
            ];
        }
        $definitions[self::name('cdef', 'DELETE')] = [
            'table' => 'cdef',
            'event' => 'DELETE',
            'body' => self::deleteParent(),
        ];
        $definitions[self::name('cdef', 'UPDATE')] = [
            'table' => 'cdef',
            'event' => 'UPDATE',
            'body' => "BEGIN\nIF NEW.id <> OLD.id THEN\n"
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF identity cannot change';\nEND IF;\nEND",
        ];

        return $definitions;
    }

    /** @return array<string, string> */
    public static function createStatements(): array
    {
        $statements = [];
        foreach (self::definitions() as $name => $definition) {
            $statements[$name] = "CREATE TRIGGER `$name` BEFORE {$definition['event']} ON `{$definition['table']}`"
                . " FOR EACH ROW\n{$definition['body']}";
        }

        return $statements;
    }

    private static function name(string $table, string $event): string
    {
        return 'kadupul_cdef_' . $table . '_' . strtolower($event);
    }

    private static function optionalParent(): string
    {
        return "BEGIN\nDECLARE parent_id MEDIUMINT UNSIGNED DEFAULT NULL;\n"
            . "DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_id = NULL;\n"
            . "IF NEW.cdef_id IS NOT NULL AND NEW.cdef_id <> 0 THEN\n"
            . "SELECT id INTO parent_id FROM cdef WHERE id = NEW.cdef_id LOCK IN SHARE MODE;\n"
            . "IF parent_id IS NULL THEN\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF reference no longer exists';\n"
            . "END IF;\nEND IF;\nEND";
    }

    private static function itemParents(): string
    {
        return "BEGIN\nDECLARE parent_id MEDIUMINT UNSIGNED DEFAULT NULL;\n"
            . "DECLARE target_id MEDIUMINT UNSIGNED DEFAULT NULL;\n"
            . "DECLARE first_id MEDIUMINT UNSIGNED;\nDECLARE second_id MEDIUMINT UNSIGNED;\n"
            . "DECLARE CONTINUE HANDLER FOR NOT FOUND SET parent_id = NULL;\n"
            . "IF NEW.cdef_id = 0 THEN\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF item requires an owner';\nEND IF;\n"
            . "IF NEW.type = 5 THEN\n"
            . "IF NEW.value NOT REGEXP '^[1-9][0-9]{0,7}$' OR CAST(NEW.value AS UNSIGNED) > 16777215\n"
            . "OR BINARY NEW.value <> BINARY CAST(CAST(NEW.value AS UNSIGNED) AS CHAR) THEN\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF target must be a canonical identity';\nEND IF;\n"
            . "SET target_id = CAST(NEW.value AS UNSIGNED);\nEND IF;\n"
            . "SET first_id = LEAST(NEW.cdef_id, COALESCE(target_id, NEW.cdef_id));\n"
            . "SET second_id = GREATEST(NEW.cdef_id, COALESCE(target_id, NEW.cdef_id));\n"
            . "SELECT id INTO parent_id FROM cdef WHERE id = first_id LOCK IN SHARE MODE;\n"
            . "IF parent_id IS NULL THEN\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF owner or target no longer exists';\nEND IF;\n"
            . "IF second_id <> first_id THEN\nSET parent_id = NULL;\n"
            . "SELECT id INTO parent_id FROM cdef WHERE id = second_id LOCK IN SHARE MODE;\n"
            . "IF parent_id IS NULL THEN\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF owner or target no longer exists';\n"
            . "END IF;\nEND IF;\nEND";
    }

    private static function deleteParent(): string
    {
        $body = "BEGIN\nDECLARE reference_id BIGINT UNSIGNED DEFAULT NULL;\n"
            . "DECLARE CONTINUE HANDLER FOR NOT FOUND SET reference_id = NULL;\n";
        foreach ([
            'cdef_items' => "cdef_id = OLD.id OR (type = 5 AND BINARY value = BINARY CAST(OLD.id AS CHAR))",
            'graph_templates_item' => 'cdef_id = OLD.id',
            'aggregate_graph_templates_item' => 'cdef_id = OLD.id',
            'aggregate_graphs_graph_item' => 'cdef_id = OLD.id',
        ] as $table => $condition) {
            // Cache tables have composite keys; select the known parent value.
            $body .= "SET reference_id = NULL;\nSELECT cdef_id INTO reference_id FROM `$table`"
                . " WHERE $condition LIMIT 1 LOCK IN SHARE MODE;\n"
                . "IF reference_id IS NOT NULL THEN\n"
                . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CDEF is still referenced';\nEND IF;\n";
        }

        return $body . 'END';
    }
}
