<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

$root = $argv[1] ?? dirname(__DIR__, 2);
require $root . '/lib/session_store.php';
final class SessionMetadataLockStatement extends PDOStatement
{
    public static ?PDO $peer = null;
    public static bool $armed = false;
    public static bool $failAfterDelete = false;
    public static ?bool $blocked = null;
    protected function __construct() {}
    public function execute(?array $params = null): bool
    {
        if (!self::$armed || !str_starts_with($this->queryString, 'DELETE FROM sessions WHERE access <')) return parent::execute($params);
        self::$armed = false;
        try {
            self::$peer->exec('ALTER TABLE sessions ENGINE=MyISAM');
            self::$blocked = false;
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? 0) !== 1205) throw $error;
            self::$blocked = true;
        }
        $result = parent::execute($params);
        return self::$failAfterDelete ? false : $result;
    }
}
$dsn = getenv('KADUPUL_TEST_MYSQL_DSN');
$user = getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_USER') : (getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root');
$password = getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') !== false ? getenv('KADUPUL_TEST_MYSQL_ADMIN_PASSWORD') : (getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '');
$database = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$peer = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'session_mdl_test_' . bin2hex(random_bytes(8));
$created = false;
$checks = 0;
$check = static function (bool $condition, string $name) use (&$checks): void {
    if (!$condition) throw new RuntimeException($name);
    ++$checks;
};
try {
    $database->exec('CREATE DATABASE `' . $schema . '`');
    $created = true;
    foreach ([$database, $peer] as $connection) $connection->exec('USE `' . $schema . '`');
    $sql = file_get_contents($root . '/cacti.sql');
    if (!is_string($sql) || preg_match('/CREATE TABLE `sessions`\s*\([^;]*;/s', $sql, $match) !== 1) throw new RuntimeException('Missing shipped table.');
    $database->exec($match[0]);
    $database->exec('CREATE TABLE caller_work(id int PRIMARY KEY) ENGINE=InnoDB');
    $peer->exec('SET SESSION lock_wait_timeout=1');
    $database->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SessionMetadataLockStatement::class]);
    SessionMetadataLockStatement::$peer = $peer;
    foreach (['confirmed', 'failed-owned', 'failed-borrowed'] as $mode) {
        $database->exec('DELETE FROM sessions');
        $database->exec("INSERT INTO sessions(id,access) VALUES('expired',1)");
        $store = new CactiSessionStore($database, $schema, 'php', static fn(): PDO => $database);
        $check($store->open(), 'Native GC admission failed.');
        if ($mode === 'failed-borrowed') {
            $database->beginTransaction();
            $database->exec('INSERT INTO caller_work VALUES(1)');
        }
        SessionMetadataLockStatement::$armed = true;
        SessionMetadataLockStatement::$blocked = null;
        SessionMetadataLockStatement::$failAfterDelete = $mode !== 'confirmed';
        $result = $store->clean(10);
        $check(SessionMetadataLockStatement::$blocked === true, 'Concurrent engine change crossed native GC admission.');
        $check($result === ($mode === 'confirmed'), 'Native GC outcome changed.');
        $check((int) $database->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === ($mode === 'confirmed' ? 0 : 1), 'Native GC cleanup lost data.');
        $check($database->inTransaction() === ($mode === 'failed-borrowed'), 'Native GC changed caller transaction ownership.');
        if ($mode === 'failed-borrowed') {
            $check((int) $database->query('SELECT COUNT(*) FROM caller_work')->fetchColumn() === 1, 'Earlier caller work was lost.');
            $database->rollBack();
        }
    }
    // A timeout above must be caused by the native unit, not a deny-all peer.
    $peer->exec('ALTER TABLE sessions ENGINE=MyISAM');
    $check($peer->query("SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='sessions'")->fetchColumn() === 'MyISAM', 'Peer could not alter after the native unit.');
    define('NATIVE_SESSION_SCENARIO_COMPLETED', true);
    echo json_encode(['checks' => $checks], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    if ($database->inTransaction()) $database->rollBack();
    if ($created) $database->exec('DROP DATABASE `' . $schema . '`');
}
