<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\DataQuerySortReadFailure;

use PDO;
use PDOException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function db_fetch_row_prepared(string $sql, array $params): array|false
{
    $GLOBALS['sortRead']['reads'][] = $params;
    try {
        $statement = $GLOBALS['sortRead']['db']->prepare($sql);
        $statement->execute($params);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException) {
        return false;
    }
}

function db_fetch_cell_prepared(string $sql, array $params): mixed
{
    $row = db_fetch_row_prepared($sql, $params);

    return $row === false || $row === [] ? false : reset($row);
}

function db_execute_prepared(string $sql, array $params): bool
{
    $GLOBALS['sortRead']['writes'][] = $params;
    try {
        return $GLOBALS['sortRead']['db']->prepare($sql)->execute($params);
    } catch (PDOException) {
        return false;
    }
}

function get_data_query_array(int $id): array
{
    $GLOBALS['sortRead']['xmlReads'][] = $id;

    return $GLOBALS['sortRead']['xml'];
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

function cacti_log(string $message): void
{
    $GLOBALS['sortRead']['logs'][] = $message;
}

test('sort-field reads preserve failure and successful XML fallback semantics', function (string $scenario, mixed $expected) {
    if (!function_exists(__NAMESPACE__ . '\\get_best_data_query_index_type')) {
        $source = file_get_contents(dirname(__DIR__, 2) . '/lib/data_query.php');
        expect($source)->toBeString();
        eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'get_best_data_query_index_type'));
    }
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE host_snmp_query (host_id INTEGER, snmp_query_id INTEGER, sort_field VARCHAR(50) NOT NULL DEFAULT '', PRIMARY KEY (host_id,snmp_query_id))");
    $stored = match ($scenario) {
        'stored' => 'ifDescr',
        'zero' => '0',
        default => '',
    };
    $db->prepare('INSERT INTO host_snmp_query VALUES (?,?,?)')->execute([7, 11, $stored]);
    $db->exec("INSERT INTO host_snmp_query VALUES (8,11,'other-host'),(7,12,'other-query')");
    if ($scenario === 'missing') {
        $db->exec('DELETE FROM host_snmp_query WHERE host_id=7 AND snmp_query_id=11');
    } elseif ($scenario === 'read-failure') {
        $db->exec('DROP TABLE host_snmp_query');
    }
    $xml = match ($scenario) {
        'fields' => ['fields' => ['ifName' => [], 'ifDescr' => []]],
        'unreadable' => [],
        'no-fields' => ['name' => 'No index fields'],
        default => ['index_order' => 'ifName:ifDescr'],
    };
    $GLOBALS['sortRead'] = ['db' => $db, 'xml' => $xml, 'reads' => [], 'writes' => [], 'xmlReads' => [], 'logs' => []];
    try {
        expect(get_best_data_query_index_type(7, 11))->toBe($expected)
            ->and($GLOBALS['sortRead']['reads'])->toBe([[7, 11]]);
        $fallback = in_array($scenario, ['empty', 'missing', 'fields'], true);
        expect($GLOBALS['sortRead']['writes'])->toBe($fallback ? [['ifName', 7, 11]] : [])
            ->and($GLOBALS['sortRead']['xmlReads'])->toBe(in_array($scenario, ['stored', 'zero', 'read-failure'], true) ? [] : [11]);
        if ($scenario !== 'read-failure') {
            $rows = $db->query('SELECT host_id,snmp_query_id,sort_field FROM host_snmp_query ORDER BY host_id,snmp_query_id')->fetchAll(PDO::FETCH_NUM);
            $expectedRows = [[7, 11, $fallback ? 'ifName' : $stored], [7, 12, 'other-query'], [8, 11, 'other-host']];
            expect($rows)->toBe($scenario === 'missing' ? array_slice($expectedRows, 1) : $expectedRows);
        }
    } finally {
        unset($GLOBALS['sortRead']);
    }
})->with([
    'stored' => ['stored', 'ifDescr'],
    'zero field name' => ['zero', '0'],
    'empty field' => ['empty', 'ifName'],
    'missing row' => ['missing', 'ifName'],
    'XML field fallback' => ['fields', 'ifName'],
    'unreadable XML' => ['unreadable', false],
    'XML without fields' => ['no-fields', false],
    'failed SELECT' => ['read-failure', false],
]);
