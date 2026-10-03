<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PDO;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class LegacyVariableTitleCacheTest extends TestCase
{
    private PDO $database;

    protected function setUp(): void
    {
        require dirname(__DIR__) . '/Fixtures/variable-title-cache-database.php';
        define('SQL_NO_CACHE', '');
        require dirname(__DIR__, 2) . '/lib/functions.php';
        require dirname(__DIR__, 2) . '/lib/variables.php';
        $this->database = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $GLOBALS['variable_title_database'] = $this->database;
        $GLOBALS['variable_title_writes'] = $GLOBALS['variable_title_hooks'] = [];
        // All columns used by the actual selectors/getters/writers, with canonical identities.
        $this->database->exec('CREATE TABLE data_local (id INTEGER PRIMARY KEY, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, data_template_id INTEGER)');
        $this->database->exec('CREATE TABLE data_template_data (id INTEGER PRIMARY KEY, local_data_id INTEGER, data_template_id INTEGER, name TEXT, name_cache TEXT)');
        $this->database->exec('CREATE TABLE graph_local (id INTEGER PRIMARY KEY, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT)');
        $this->database->exec('CREATE TABLE graph_templates_graph (id INTEGER PRIMARY KEY, local_graph_id INTEGER, graph_template_id INTEGER, title TEXT, title_cache TEXT, t_title TEXT)');
        $this->database->exec('CREATE TABLE graph_templates_item (id INTEGER PRIMARY KEY, local_graph_id INTEGER, task_item_id INTEGER)');
        $this->database->exec('CREATE TABLE data_template_rrd (id INTEGER PRIMARY KEY, local_data_id INTEGER)');
        $this->database->exec('CREATE TABLE host_snmp_cache (host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, field_name TEXT, field_value TEXT)');
        foreach ([[1, 7, 9, 'eth0', 11], [2, 7, 9, 'eth1', 12], [3, 8, 9, 'eth0', 11], [4, 7, 10, 'eth0', 13]] as [$id, $host, $query, $index, $template]) {
            $this->database->prepare('INSERT INTO data_local VALUES (?,?,?,?,?)')->execute([$id, $host, $query, $index, $template]);
            $this->database->prepare('INSERT INTO data_template_data VALUES (?,?,?,?,?)')->execute([$id, $id, $template, 'Data ' . $id, 'old data ' . $id]);
            $graph = $id + 10;
            $this->database->prepare('INSERT INTO graph_local VALUES (?,?,?,?)')->execute([$graph, $host, $query, $index]);
            $this->database->prepare('INSERT INTO graph_templates_graph VALUES (?,?,?,?,?,?)')->execute([$graph, $graph, $template, 'Graph ' . $graph, 'old graph ' . $graph, '']);
            $this->database->prepare('INSERT INTO data_template_rrd VALUES (?,?)')->execute([$id + 100, $id]);
            $this->database->prepare('INSERT INTO graph_templates_item VALUES (?,?,?)')->execute([$graph, $graph, $id + 100]);
        }
        $this->database->exec("INSERT INTO data_template_data VALUES (100,0,11,'Base data','base cache'); INSERT INTO graph_templates_graph VALUES (100,0,11,'Base graph','base cache','')");
        // Two items share the selected source: DISTINCT must admit one cache write.
        $this->database->exec('INSERT INTO graph_templates_item VALUES (112,12,102)');
    }

    private function caches(string $table, string $column): array
    {
        return $this->database->query('SELECT id,' . $column . ' FROM ' . $table . ' ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private function assertSelection(callable $operation, array $selected, bool $graphs): void
    {
        $table = $graphs ? 'graph_templates_graph' : 'data_template_data';
        $column = $graphs ? 'title_cache' : 'name_cache';
        $before = $this->caches($table, $column);
        $GLOBALS['variable_title_hooks'] = $GLOBALS['variable_title_writes'] = [];
        $operation();
        $after = $this->caches($table, $column);
        foreach ($before as $id => $value) {
            self::assertSame(in_array($id, $selected, true) ? ($graphs ? 'Graph ' : 'Data ') . $id : $value, $after[$id]);
        }
        self::assertCount(count($selected), $GLOBALS['variable_title_writes']);
        self::assertSame($graphs ? [] : array_map(static fn($id) => ['update_data_source_title_cache', $id], $selected), $GLOBALS['variable_title_hooks']);
        $reset = $this->database->prepare('UPDATE ' . $table . ' SET ' . $column . '=? WHERE id=?');
        foreach ($before as $id => $value) {
            $reset->execute([$value, $id]);
        }
    }

    public function testDataSelectorsUpdateExactPersistedSourcesAndHookIdentities(): void
    {
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_template(11), [1, 3], false);
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_query(9, 'eth0'), [1, 3], false);
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_host(7), [1, 2, 4], false);
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_host(7, 9), [1, 2], false);
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_host(7, 9, [2]), [2], false);
        $this->assertSelection(static fn() => \update_data_source_title_cache_from_template(999), [], false);
    }

    public function testGraphSelectorsUpdateExactPersistedGraphsAndExcludeBaseTemplate(): void
    {
        $this->assertSelection(static fn() => \update_graph_title_cache_from_template(11), [11, 13], true);
        $this->assertSelection(static fn() => \update_graph_title_cache_from_query(9, 'eth0'), [11, 13], true);
        $this->assertSelection(static fn() => \update_graph_title_cache_from_host(7), [11, 12, 14], true);
        $this->assertSelection(static fn() => \update_graph_title_cache_from_host(7, 9), [11, 12], true);
        $this->assertSelection(static fn() => \update_graph_title_cache_from_host(7, 9, [2]), [12], true);
        $this->assertSelection(static fn() => \update_graph_title_cache_from_query(999, 'missing'), [], true);
    }

    public function testUnresolvedTitlesPreserveExistingCacheButInitializeEmptyCache(): void
    {
        $this->database->exec("UPDATE data_local SET host_id=0 WHERE id=1; UPDATE graph_local SET host_id=0 WHERE id=11; UPDATE data_template_data SET name='|query_missing|' WHERE id=1; UPDATE graph_templates_graph SET title='|host_description|' WHERE id=11");
        \update_data_source_title_cache(1);
        \update_graph_title_cache(11);
        self::assertSame('old data 1', $this->caches('data_template_data', 'name_cache')[1]);
        self::assertSame('old graph 11', $this->caches('graph_templates_graph', 'title_cache')[11]);
        self::assertSame([], $GLOBALS['variable_title_writes']);
        self::assertSame([], $GLOBALS['variable_title_hooks']);
        $this->database->exec("UPDATE data_template_data SET name_cache='' WHERE id=1; UPDATE graph_templates_graph SET title_cache='' WHERE id=11");
        \update_data_source_title_cache(1);
        \update_graph_title_cache(11);
        self::assertSame('|query_missing|', $this->caches('data_template_data', 'name_cache')[1]);
        self::assertSame('|host_description|', $this->caches('graph_templates_graph', 'title_cache')[11]);
        self::assertSame([['|query_missing|', 1], ['|host_description|', 11]], $GLOBALS['variable_title_writes']);
        self::assertSame([['update_data_source_title_cache', 1]], $GLOBALS['variable_title_hooks']);
    }

    public function testSnmpSubstitutionUsesHostScopedCacheAndZeroHostFallback(): void
    {
        $insert = $this->database->prepare('INSERT INTO host_snmp_cache VALUES (?,?,?,?,?)');
        foreach ([[7, 9, 'eth0', 'name', 'Primary port'], [0, 9, 'eth0', 'name', 'Fallback port'], [8, 9, 'eth0', 'name', 'Other host'], [0, 9, 'eth0', 'blank', '   ']] as $row) {
            $insert->execute($row);
        }
        self::assertSame('Primary port', \substitute_snmp_query_data('|QUERY_NAME|', 7, 9, 'eth0'));
        self::assertSame('Fall', \substitute_snmp_query_data('|query_name|', 0, 9, 'eth0', 4));
        self::assertSame('Fallback port / |query_blank|', \substitute_snmp_query_data('|query_name| / |query_blank|', 0, 9, 'eth0'));
        self::assertSame('|query_name|', \substitute_snmp_query_data('|query_name|', 7, 999, 'eth0'));
        self::assertSame([], $GLOBALS['variable_title_writes']);
        self::assertSame([], $GLOBALS['variable_title_hooks']);
    }
}
