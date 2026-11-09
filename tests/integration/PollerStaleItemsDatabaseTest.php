<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PollerStaleItemsDatabase;

use PDO;
use RuntimeException;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

$stale_item_source = file_get_contents(dirname(__DIR__, 2) . '/lib/poller.php');
if ($stale_item_source === false) {
    throw new RuntimeException('Cannot read the collector synchronizer.');
}
// The production stale-item cleanup, called by the replicate_out() block below.
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($stale_item_source, 'replicate_out_remove_stale_items'));

function connection(): PDO
{
    $dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
    if (!$dsn) {
        throw new RuntimeException('KADUPUL_TEST_MYSQL_DSN is required for the disposable database contract.');
    }
    $db = new PDO($dsn, getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("SET SESSION time_zone = '+00:00'");

    return $db;
}

function db_fetch_cell(string $sql, string $column = '', bool $log = true, PDO|false $connection = false): mixed
{
    $db = $connection ?: $GLOBALS['stale_item_contract']['source'];
    $GLOBALS['stale_item_contract']['reads'][] = $db === $GLOBALS['stale_item_contract']['remote'] ? 'remote' : 'source';

    return $db->query($sql)->fetchColumn();
}

function db_execute_prepared(string $sql, array $params = [], bool $log = true, PDO|false $connection = false): bool
{
    $db = $connection ?: $GLOBALS['stale_item_contract']['source'];
    $GLOBALS['stale_item_contract']['writes'][] = [$db === $GLOBALS['stale_item_contract']['remote'] ? 'remote' : 'source', $params];

    return $db->prepare($sql)->execute($params);
}

function db_fetch_assoc(string $sql, bool $log = true, PDO|false $connection = false): array|false
{
    $db = $connection ?: $GLOBALS['stale_item_contract']['source'];
    $GLOBALS['stale_item_contract']['reads'][] = $db === $GLOBALS['stale_item_contract']['remote'] ? 'remote' : 'source';

    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

test('replication removes only the remote items main no longer holds', function (string $scenario) {
    $root = dirname(__DIR__, 2);
    $source = file_get_contents($root . '/lib/poller.php');
    $schema = file_get_contents($root . '/cacti.sql');
    expect($source)->toBeString()->and($schema)->toBeString();
    $function = \test_php_function_source($source, 'replicate_out');
    // The read/failure guard and the cleanup are separate blocks. Capture both
    // so an earlier guard cannot silently omit the actual mutation.
    $cleanup = \test_php_block_source('<?php ' . $function, '$time = db_fetch_cell(', '// Remove anything not updated recently');
    $cleanup .= \test_php_block_source('<?php ' . $function, 'if (!empty($time))', '// Remove anything not updated recently');
    expect(preg_match('/^CREATE TABLE poller_item \(.*?^\) ENGINE=InnoDB ROW_FORMAT=Dynamic;/ms', $schema, $match))->toBe(1);
    $ddl = str_replace('CREATE TABLE poller_item (', 'CREATE TEMPORARY TABLE poller_item (', $match[0]);
    // Use the shipped columns; permit nullable legacy timestamps so
    // older MariaDB implicit timestamp defaults cannot replace NULL with now.
    $ddl = str_replace('`last_updated` timestamp DEFAULT', '`last_updated` timestamp NULL DEFAULT', $ddl, $replacements);
    expect($replacements)->toBe(1);
    $primary = connection();
    $rcnn_id = connection();
    $remote_poller_id = 2;
    $fail = static fn(): bool => false;
    $timezone = date_default_timezone_get();
    date_default_timezone_set('UTC');
    $GLOBALS['stale_item_contract'] = ['source' => $primary, 'remote' => $rcnn_id, 'reads' => [], 'writes' => []];
    try {
        foreach ([$primary, $rcnn_id] as $db) {
            $db->exec($ddl);
        }
        $primary->exec("INSERT INTO poller_item (local_data_id,poller_id,rrd_name,last_updated) VALUES
            (1,2,'in','2026-09-15 00:00:00'),(1,2,'out','2026-09-15 00:00:00'),(2,2,'','2026-09-16 00:00:00'),(5,3,'','2026-09-15 00:00:00')");
        // Rows replicate_out() has just copied: every item main keeps for collector 2.
        $data = $primary->query('SELECT * FROM poller_item WHERE poller_id = 2')->fetchAll(PDO::FETCH_ASSOC);
        // A current row the copy did not change keeps its older last_updated.
        $current = [[1, 'in', '2026-09-15 00:00:00'], [1, 'out', '2026-09-15 00:00:00'], [2, '', '2026-09-16 00:00:00']];
        $stale = [[3, '', '2026-09-16 00:00:00'], [4, '', null], [6, '', '0000-00-00 00:00:00'], [7, 'in', '2026-09-14 00:00:00']];
        $rows = match ($scenario) {
            'mixed' => array_merge($current, $stale),
            'current' => $current,
            'empty' => [],
        };
        // Existing zero dates are a supported legacy sentinel. Load them with
        // relaxed write validation, then restore the engine's original mode.
        $mode = $rcnn_id->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        $rcnn_id->exec("SET SESSION sql_mode = ''");
        $insert = $rcnn_id->prepare('INSERT INTO poller_item (local_data_id,poller_id,rrd_name,last_updated) VALUES (?,2,?,?)');
        foreach ($rows as $row) {
            $insert->execute($row);
        }
        $rcnn_id->exec('SET SESSION sql_mode = ' . $rcnn_id->quote($mode));

        // Execute the real MAX/read, local UPDATE and remote DELETE together,
        // without rewriting their SQL or substituting a second implementation.
        eval('namespace ' . __NAMESPACE__ . ';' . $cleanup);

        $expected = $scenario === 'empty' ? [] : $current;
        $remoteRows = static fn(): array => $rcnn_id->query('SELECT local_data_id,rrd_name,last_updated FROM poller_item ORDER BY local_data_id,rrd_name')->fetchAll(PDO::FETCH_NUM);
        expect($remoteRows())->toBe($expected);
        $local = $primary->query('SELECT last_updated FROM poller_item ORDER BY local_data_id,rrd_name')->fetchAll(PDO::FETCH_COLUMN);
        $writes = $GLOBALS['stale_item_contract']['writes'];
        if ($scenario === 'empty') {
            expect($GLOBALS['stale_item_contract']['reads'])->toBe(['remote'])
                ->and($local)->toBe(['2026-09-15 00:00:00', '2026-09-15 00:00:00', '2026-09-16 00:00:00', '2026-09-15 00:00:00'])
                ->and($writes)->toBe([]);
        } else {
            expect($GLOBALS['stale_item_contract']['reads'])->toBe(['remote', 'remote'])
                ->and($local)->toBe(['2026-09-16 00:00:00', '2026-09-16 00:00:00', '2026-09-16 00:00:00', '2026-09-15 00:00:00'])
                ->and($writes[0][0])->toBe('source')
                ->and((int) $writes[0][1][0])->toBe(strtotime('2026-09-16 00:00:00'))
                ->and($writes[0][1][1])->toBe(2)
                ->and(array_column(array_slice($writes, 1), 0))->toBe($scenario === 'mixed' ? ['remote'] : []);
            eval('namespace ' . __NAMESPACE__ . ';' . $cleanup);
            expect($remoteRows())->toBe($expected);
        }
    } finally {
        foreach ([$primary, $rcnn_id] as $db) {
            $db->exec('DROP TEMPORARY TABLE IF EXISTS poller_item');
        }
        date_default_timezone_set($timezone);
        unset($GLOBALS['stale_item_contract']);
    }
})->with(['mixed', 'current', 'empty']);
