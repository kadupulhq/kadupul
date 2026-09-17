<?php
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later
namespace DurablePollerQueueTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

function db_install_execute($sql, $params = array()) {
    if (str_starts_with($sql, 'ALTER TABLE poller_output ENGINE=')) {
        $statement = $GLOBALS['durable_queue_pdo']->prepare($sql);
        $statement->execute($params);
    }
    return 0;
}
function db_table_exists($table) { return false; }
function db_install_add_column(...$args) {}
function db_install_add_key(...$args) {}
function db_fetch_cell_prepared($sql, $params) {
    $statement = $GLOBALS['durable_queue_pdo']->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
$root = dirname(__DIR__, 2);
foreach (array('/install/upgrades/1_2_32.php' => 'upgrade_to_1_2_32', '/lib/rrd_maintenance.php' => 'rrd_maintenance_queue_configuration_error') as $path => $name) {
    eval('namespace ' . __NAMESPACE__ . '; ' . \test_php_function_source(file_get_contents($root . $path), $name)); // nosemgrep: php.lang.security.eval-use.eval-use
}

test('upgrade converts the MEMORY retry queue to InnoDB without discarding samples and can be repeated', function () {
    $host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOST_DB_PORT') ?: '3306';
    $database = getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract';
    $socket = getenv('BOOST_DB_SOCKET');
    $dsn = $socket ? "mysql:unix_socket=$socket;dbname=$database" : "mysql:host=$host;port=$port;dbname=$database";
    $pdo = new \PDO($dsn, getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
    $GLOBALS['durable_queue_pdo'] = $pdo;
    // CREATE deliberately fails if another fixture already owns this table.
    $pdo->exec('CREATE TABLE poller_output (local_data_id int NOT NULL, rrd_name varchar(19) NOT NULL, time timestamp NOT NULL, output varchar(512) NOT NULL, PRIMARY KEY(local_data_id, rrd_name, time)) ENGINE=MEMORY');
    try {
        $pdo->exec("INSERT INTO poller_output VALUES (7, 'value', '2026-09-15 00:00:00', '42'), (8, 'value', '2026-09-15 00:00:00', 'U')");
        $before = $pdo->query('SELECT * FROM poller_output ORDER BY local_data_id')->fetchAll(\PDO::FETCH_ASSOC);
        expect(rrd_maintenance_queue_configuration_error())->toContain('must use InnoDB');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            upgrade_to_1_2_32();
            expect(rrd_maintenance_queue_configuration_error())->toBe('');
            expect($pdo->query('SELECT * FROM poller_output ORDER BY local_data_id')->fetchAll(\PDO::FETCH_ASSOC))->toBe($before);
        }
    } finally {
        $pdo->exec('DROP TABLE poller_output');
        unset($GLOBALS['durable_queue_pdo']);
    }
});
