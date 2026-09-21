<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Use only connection-local temporary tables; never mutate an installation.
$root = dirname(__DIR__, 2);
foreach (['Platform/Contract/DatabaseConnection', 'IdentityAccess/Domain/RowCacheInvalidation', 'IdentityAccess/Application/Port/InvalidatedRowCache', 'IdentityAccess/Application/Command/CleanInvalidatedRowCache', 'IdentityAccess/Infrastructure/Legacy/InstallationRowCache'] as $class) {
    require $root . '/src/' . $class . '.php';
}
$pdo = new PDO('mysql:host=' . (getenv('BOOST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('BOOST_DB_PORT') ?: '3306') . ';dbname=' . getenv('BOOST_DB_NAME'), getenv('BOOST_DB_USER'), getenv('BOOST_DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("SET time_zone = '+00:00'");
$pdo->exec('CREATE TEMPORARY TABLE settings (name VARCHAR(50) PRIMARY KEY, value VARCHAR(255))');
$pdo->exec('CREATE TEMPORARY TABLE user_auth_row_cache (user_id INT, class VARCHAR(20), hash VARCHAR(32), time TIMESTAMP, total_rows INT DEFAULT 0, PRIMARY KEY (user_id, class, hash), KEY class_time (class, time))');
$pdo->exec("INSERT INTO settings VALUES ('time_last_change_graph', '1700000000'), ('time_last_changeXdevice', '1900000000')");
$insert = $pdo->prepare('INSERT INTO user_auth_row_cache (user_id, class, hash, time) VALUES (?, ?, ?, FROM_UNIXTIME(?))');
for ($i = 0; $i < 1001; ++$i) {
    $insert->execute([$i, 'graph', 'stale', 1699999999]);
}
foreach ([['graph', 'boundary', 1700000000], ['graph', 'fresh', 1700000001], ['device', 'other', 1699999999]] as [$class, $hash, $time]) {
    $insert->execute([1, $class, $hash, $time]);
}
$database = new class ($pdo) implements \Kadupul\Platform\Contract\DatabaseConnection {
    public function __construct(private readonly PDO $pdo) {}

    public function get(): PDO
    {
        return $this->pdo;
    }
};
// Inspect a populated range; an empty range may be optimized away by MySQL 9.7.
$plan = $pdo->query("EXPLAIN SELECT user_id, hash FROM user_auth_row_cache WHERE class = 'graph' AND time < FROM_UNIXTIME(1700000000) ORDER BY time, user_id, hash LIMIT 1000")->fetch(PDO::FETCH_ASSOC);
if (!str_contains((string) $plan['possible_keys'], 'class_time')) {
    throw new RuntimeException('Cleanup cannot use its class/time access path.');
}
$storage = new \Kadupul\IdentityAccess\Infrastructure\Legacy\InstallationRowCache($database);
$cutoff = new \Kadupul\IdentityAccess\Domain\RowCacheInvalidation('graph', 1700000000);
if ($storage->count($cutoff) !== 1001) {
    throw new RuntimeException('Backlog count includes fresh rows or misses stale rows.');
}
$clean = new \Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache($storage);
foreach ([1000, 1, 0] as $expected) {
    if ($clean() !== $expected) {
        throw new RuntimeException('Bounded or idempotent cleanup failed.');
    }
}
if ($pdo->query('SELECT hash FROM user_auth_row_cache ORDER BY hash')->fetchAll(PDO::FETCH_COLUMN) !== ['boundary', 'fresh', 'other']) {
    throw new RuntimeException('Fresh or unrelated cache rows changed.');
}
$pdo->exec('ALTER TABLE user_auth_row_cache DROP INDEX class_time');
try {
    iterator_to_array((new \Kadupul\IdentityAccess\Infrastructure\Legacy\InstallationRowCache($database))->invalidations());
    throw new RuntimeException('Missing index was accepted.');
} catch (RuntimeException $error) {
    if (!str_contains($error->getMessage(), 'requires the class_time index')) {
        throw $error;
    }
}
$indexCreates = 0;
function db_index_exists(string $table, string $index): bool
{
    global $pdo;
    if ($table !== 'user_auth_row_cache') {
        return true;
    }

    return $pdo->query("SHOW INDEX FROM user_auth_row_cache WHERE Key_name = 'class_time'")->fetchColumn() !== false;
}
function db_install_execute(string $sql): void
{
    global $pdo, $indexCreates;
    if (str_starts_with($sql, 'ALTER TABLE user_auth_row_cache')) {
        $pdo->exec($sql);
        ++$indexCreates;
    }
}
require $root . '/install/upgrades/1_2_31.php';
upgrade_to_1_2_31();
upgrade_to_1_2_31();
if ($indexCreates !== 1 || !db_index_exists('user_auth_row_cache', 'class_time')) {
    throw new RuntimeException('Index upgrade is not idempotent.');
}
preg_match_all("/INSERT INTO `table_indexes` VALUES \('user_auth_row_cache',1,'class_time',([12]),'([^']+)'/", file_get_contents($root . '/docs/audit_schema.sql'), $audit);
if ($audit[1] !== ['1', '2'] || $audit[2] !== ['class', 'time']) {
    throw new RuntimeException('Database audit schema does not preserve the cleanup index.');
}
if ($storage->count($cutoff) !== 0) {
    throw new RuntimeException('Backlog count did not reflect cleanup.');
}
echo "Row-cache database contract passed.\n";
