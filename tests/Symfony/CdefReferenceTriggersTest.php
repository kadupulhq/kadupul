<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Legacy\CdefReferenceTriggers;
use PHPUnit\Framework\TestCase;

final class CdefReferenceTriggersTest extends TestCase
{
    public function testEveryPrimaryOwnerTargetAndCacheWriteHasBothGuards(): void
    {
        $definitions = CdefReferenceTriggers::definitions();
        self::assertCount(10, $definitions);
        foreach (['graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item', 'cdef_items'] as $table) {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $definition = $definitions['kadupul_cdef_' . $table . '_' . strtolower($event)];
                self::assertSame($table, $definition['table']);
                self::assertSame($event, $definition['event']);
                self::assertStringContainsString('LOCK IN SHARE MODE', $definition['body']);
                self::assertStringContainsString("SIGNAL SQLSTATE '45000'", $definition['body']);
            }
        }
    }

    public function testTargetAndOwnerLocksHaveCanonicalSortedIdentities(): void
    {
        $body = CdefReferenceTriggers::definitions()['kadupul_cdef_cdef_items_insert']['body'];
        self::assertStringContainsString('LEAST(NEW.cdef_id', $body);
        self::assertStringContainsString('GREATEST(NEW.cdef_id', $body);
        self::assertStringContainsString('BINARY NEW.value <> BINARY CAST', $body);
        self::assertStringContainsString('16777215', $body);
        self::assertLessThan(strpos($body, 'WHERE id = second_id'), strpos($body, 'WHERE id = first_id'));
    }

    public function testDeleteChecksEveryIncomingReferenceUsingCurrentReads(): void
    {
        $body = CdefReferenceTriggers::definitions()['kadupul_cdef_cdef_delete']['body'];
        foreach (['cdef_items', 'graph_templates_item', 'aggregate_graph_templates_item', 'aggregate_graphs_graph_item'] as $table) {
            self::assertStringContainsString("FROM `$table`", $body);
        }
        self::assertSame(4, substr_count($body, 'LOCK IN SHARE MODE'));
        self::assertStringContainsString('type = 5 AND BINARY value = BINARY CAST(OLD.id AS CHAR)', $body);
    }

    public function testPlansAreBoundToBeforeRowEventsAndDoNotChangePrivileges(): void
    {
        foreach (CdefReferenceTriggers::createStatements() as $name => $sql) {
            self::assertLessThanOrEqual(64, strlen($name));
            self::assertStringStartsWith("CREATE TRIGGER `$name` BEFORE ", $sql);
            self::assertStringContainsString(' FOR EACH ROW', $sql);
            self::assertStringNotContainsString('DEFINER', $sql);
            self::assertStringNotContainsString('SET GLOBAL', $sql);
            self::assertStringNotContainsString('GRANT ', $sql);
        }
    }
}
