<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

// #198: a re-index with no index changes cleared the orphan flag of data
// sources it had just found orphaned, so their unresolved state was lost.

namespace DataQueryOrphanState;

use PDO;

$source = file_get_contents(dirname(__DIR__, 2) . '/lib/data_query.php');
if ($source === false) {
    throw new \RuntimeException('Cannot read the data query library.');
}
$GLOBALS['data_query_orphans_source'] = $source;

/** Run the no-index-change step of run_data_query() from the production source. */
function clear(int $host, int $query, array $orphaned, array $definition): void
{
    $source = $GLOBALS['data_query_orphans_source'];
    $start = strpos($source, "__esc('No Index Changes Detected, Poller Cache Refreshed'));");
    $end = strpos($source, "\n\t}\n\n\tif (cacti_sizeof(\$orphaned_ids) &&", (int) $start);
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $body = substr($source, $start, $end - $start);
    $body = substr($body, strpos($body, "\n"));
    $host_id = $host;
    $snmp_query_id = $query;
    $orphaned_ids = $orphaned;
    $query_array = $definition;
    eval('namespace ' . __NAMESPACE__ . ';' . $body);
}

function db(): PDO
{
    return $GLOBALS['data_query_orphans']['db'];
}

function db_fetch_cell_prepared(string $sql, array $params = []): mixed
{
    $query = db()->prepare($sql);
    expect($query)->not->toBeFalse()->and($query->execute($params))->toBeTrue();

    return $query->fetchColumn();
}

function db_execute_prepared(string $sql, array $params = []): bool
{
    $query = db()->prepare($sql);

    return $query !== false && $query->execute($params);
}

function push_out_host(int|string $host, int|string $local_data_id = 0, int|string $data_template_id = 0): void
{
    $GLOBALS['data_query_orphans']['push_out'][] = [(int) $host, (int) $local_data_id, (int) $data_template_id];
}

function cacti_sizeof(mixed $value): int
{
    return is_countable($value) ? count($value) : 0;
}

function orphans(): array
{
    return array_map('intval', db()->query('SELECT id FROM data_local WHERE orphan = 1 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
}

beforeEach(function () {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT]);
    $db->exec('CREATE TABLE data_local (id INTEGER PRIMARY KEY, data_template_id INTEGER, host_id INTEGER, snmp_query_id INTEGER, snmp_index TEXT, orphan INTEGER NOT NULL DEFAULT 0)');
    // Device 5, query 3: 10 orphaned by this re-index, 11 orphaned earlier and
    // now left alone (a transient index), 12 healthy. Rows 20 and 21 belong to
    // another device and another query.
    $db->exec("INSERT INTO data_local VALUES (10, 7, 5, 3, 'eth0', 1), (11, 7, 5, 3, 'eth1', 1), (12, 7, 5, 3, 'eth2', 0), (20, 7, 6, 3, 'eth0', 1), (21, 8, 5, 4, 'eth0', 1)");
    $GLOBALS['data_query_orphans'] = ['db' => $db, 'push_out' => []];
});

test('data sources orphaned by this re-index stay orphaned', function () {
    clear(5, 3, ['10'], []);

    expect(orphans())->toBe([10, 20, 21])
        ->and($GLOBALS['data_query_orphans']['push_out'])->toBe([]);
});

test('earlier orphans of the device and query are still cleared', function () {
    clear(5, 3, [], []);

    expect(orphans())->toBe([20, 21]);
});

test('with orphan removal the poller cache is pushed out only when a flag was cleared', function (array $orphaned, array $expectedOrphans, array $pushOut) {
    clear(5, 3, $orphaned, ['index_orphan_removal' => 'true']);

    expect(orphans())->toBe($expectedOrphans)
        ->and($GLOBALS['data_query_orphans']['push_out'])->toBe($pushOut);
})->with([
    'one new orphan, one earlier orphan' => [['10'], [10, 20, 21], [[5, 0, 7]]],
    'every orphan is new' => [['10', '11'], [10, 11, 20, 21], []],
]);
