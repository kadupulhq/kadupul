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
$pdo->exec('CREATE TEMPORARY TABLE user_auth_row_cache (user_id INT, class VARCHAR(20), hash VARCHAR(32), time TIMESTAMP, total_rows INT DEFAULT 0, PRIMARY KEY (user_id, class, hash))');
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
$clean = new \Kadupul\IdentityAccess\Application\Command\CleanInvalidatedRowCache(new \Kadupul\IdentityAccess\Infrastructure\Legacy\InstallationRowCache($database));
foreach ([1000, 1, 0] as $expected) {
    if ($clean() !== $expected) {
        throw new RuntimeException('Bounded or idempotent cleanup failed.');
    }
}
if ($pdo->query('SELECT hash FROM user_auth_row_cache ORDER BY hash')->fetchAll(PDO::FETCH_COLUMN) !== ['boundary', 'fresh', 'other']) {
    throw new RuntimeException('Fresh or unrelated cache rows changed.');
}
echo "Row-cache database contract passed.\n";
