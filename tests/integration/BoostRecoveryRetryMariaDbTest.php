<?php

declare(strict_types=1);

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

namespace BoostRecoveryMariaDbTest;

require_once dirname(__DIR__).'/Helpers/PhpSource.php';
$root = dirname(__DIR__, 2);
foreach ([['lib/functions.php', 'cacti_sizeof'], ['lib/boost.php', 'boost_flush_output_batch'],
    ['poller_recovery.php', 'recovery_delete_acknowledged_rows']] as [$file, $name]) {
    $source = file_get_contents($root.'/'.$file);
    if ($source === false) { throw new \RuntimeException('Unable to read '.$file); }
    eval('namespace '.__NAMESPACE__.';'.test_php_function_source($source, $name));
}
function connection(): \PDO {
    $host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOST_DB_PORT') ?: '3306';
    $name = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
    $socket = getenv('BOOST_DB_SOCKET');
    $dsn = $socket ? "mysql:unix_socket=$socket;dbname=$name;charset=utf8mb4" : "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    return new \PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
}
function db_fetch_row($query, $assoc, $conn) { return $conn->query($query)->fetch(\PDO::FETCH_ASSOC); }
function db_execute($query, $log, $conn) {
    if (($GLOBALS['recovery_inject_failure'] ?? false) === $conn) { return false; }
    $GLOBALS['recovery_affected'][spl_object_hash($conn)] = $conn->exec($query);
    return true;
}
function db_affected_rows($conn) { return $GLOBALS['recovery_affected'][spl_object_hash($conn)] ?? 0; }
function db_execute_prepared($query, $parameters, $log, $conn) {
    if (($GLOBALS['recovery_inject_delete_failure'] ?? false) === $conn) { return false; }
    return $conn->prepare($query)->execute($parameters);
}
function cacti_log(...$arguments) { $GLOBALS['recovery_native_logs'][] = $arguments[0]; }
function values(\PDO $conn, array $rows): array {
    return array_map(static fn($row) => '('.(int) $row['local_data_id'].','.$conn->quote($row['rrd_name']).','.$conn->quote($row['time']).','.$conn->quote($row['output']).')', $rows);
}
function rows(\PDO $conn): array { return $conn->query('SELECT * FROM poller_output_boost ORDER BY local_data_id')->fetchAll(\PDO::FETCH_ASSOC); }

test('recovery publishes a retained changed value before deleting it on retry', function ($observed, $replacement, $collation) {
    $local = connection();
    $main = connection();
    foreach ([$local, $main] as $conn) {
        // The installed Cacti schema uses an explicit zero timestamp default.
        // Preserve the remaining strict modes while allowing that legacy DDL.
        $modes = explode(',', (string) $conn->query('SELECT @@SESSION.sql_mode')->fetchColumn());
        $modes = array_values(array_diff($modes, ['NO_ZERO_DATE', 'NO_ZERO_IN_DATE']));
        $conn->exec('SET SESSION sql_mode='.$conn->quote(implode(',', $modes)));
        // Private tables retain the real defaults, including no implicit ON UPDATE.
        $conn->exec("CREATE TEMPORARY TABLE poller_output_boost (local_data_id INT UNSIGNED NOT NULL DEFAULT 0, rrd_name VARCHAR(19) NOT NULL DEFAULT '', time TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00', output VARCHAR(512) NOT NULL, PRIMARY KEY(local_data_id,time,rrd_name)) ENGINE=InnoDB COLLATE=".$collation);
    }
    $GLOBALS['recovery_inject_failure'] = false;
    $GLOBALS['recovery_inject_delete_failure'] = false;
    $GLOBALS['recovery_native_logs'] = [];
    $seed = [[1, 'value', '2026-09-15 00:00:00', $observed], [2, 'value', '2026-09-15 00:00:00', ''], [3, 'value', '2026-09-15 00:00:00', 'unchanged']];
    $insert = $local->prepare('INSERT INTO poller_output_boost VALUES (?, ?, ?, ?)');
    foreach ($seed as $row) { $insert->execute($row); }
    $captured = rows($local);
    expect(boost_flush_output_batch(values($main, $captured), $main, true))->toBeTrue();
    $local->prepare('UPDATE poller_output_boost SET output=? WHERE local_data_id=1')->execute([$replacement]);
    expect(recovery_delete_acknowledged_rows($captured, $local))->toBeTrue();
    $retained = rows($local);
    expect($retained)->toHaveCount(1)->and($retained[0]['output'])->toBe($replacement)
        ->and($retained[0]['time'])->toBe($captured[0]['time']);
    // A failed resend cannot acknowledge or remove the retained row.
    $GLOBALS['recovery_inject_failure'] = $main;
    expect(boost_flush_output_batch(values($main, $retained), $main, true))->toBeFalse();
    expect(rows($local))->toBe($retained)->and(rows($main)[0]['output'])->toBe($observed);
    $GLOBALS['recovery_inject_failure'] = false;
    expect(boost_flush_output_batch(values($main, $retained), $main, true))->toBeTrue();
    expect(rows($main)[0]['output'])->toBe($replacement);
    // A failed local acknowledgement remains recoverable through an idempotent resend.
    $GLOBALS['recovery_inject_delete_failure'] = $local;
    expect(recovery_delete_acknowledged_rows($retained, $local))->toBeFalse();
    expect(rows($local))->toBe($retained);
    $GLOBALS['recovery_inject_delete_failure'] = false;
    expect(boost_flush_output_batch(values($main, $retained), $main, true))->toBeTrue();
    expect(recovery_delete_acknowledged_rows($retained, $local))->toBeTrue()->and(rows($local))->toBe([]);
    expect(rows($main)[0]['output'])->toBe($replacement)->and($GLOBALS['recovery_native_logs'])->toBe([]);
})->with([['41', '42'], ['U', 'u'], ['42', '42 '], ['café', 'CAFÉ'], ['café', 'cafe']])->with(['utf8mb4_unicode_ci', 'latin1_swedish_ci']);
