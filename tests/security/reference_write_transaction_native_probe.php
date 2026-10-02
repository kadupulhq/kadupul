<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require dirname(__DIR__, 2) . '/lib/reference_write.php';

function referenceCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS $message\n";
}

$dsn = getenv('KADUPUL_REFERENCE_TEST_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) {
    throw new RuntimeException('An explicitly authorized disposable native server is required.');
}
$database = new PDO($dsn, getenv('KADUPUL_REFERENCE_TEST_USER') ?: '', getenv('KADUPUL_REFERENCE_TEST_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$schema = 'kadupul_reference_write_' . bin2hex(random_bytes(8));
$created = false;
try {
    echo 'SERVER ' . $database->query('SELECT VERSION()')->fetchColumn() . ' PHP ' . PHP_VERSION . "\n";
    $database->exec("CREATE DATABASE `$schema`");
    $created = true;
    $database->exec("USE `$schema`");
    $database->exec('CREATE TABLE reference_rows (id INT PRIMARY KEY, value INT NOT NULL) ENGINE=InnoDB');
    $database_hostname = 'owned-native';
    $database_port = 0;
    $database_default = $schema;
    $database_sessions = ["$database_hostname:$database_port:$database_default" => $database];
    $config = ['poller_id' => 2];
    $write = static fn() => $database->exec('INSERT INTO reference_rows VALUES (1,10)') !== false;
    referenceCheck(reference_write_atomic($write, ['reference_rows']) === true, 'persistent write commits on actual remote collector connection');
    referenceCheck(!$database->inTransaction() && (int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10, 'owned commit confirms durable row');
    referenceCheck($config['poller_id'] === 2, 'remote collector identity is preserved');
    $failure = static function () use ($database): bool {
        $database->exec('UPDATE reference_rows SET value=20 WHERE id=1');
        return false;
    };
    referenceCheck(reference_write_atomic($failure, ['reference_rows']) === false, 'false operation is rejected');
    referenceCheck(!$database->inTransaction() && (int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10, 'false operation rolls back earlier write');
    $database->beginTransaction();
    $database->exec('INSERT INTO reference_rows VALUES (2,30)');
    referenceCheck(reference_write_atomic($failure, ['reference_rows']) === false, 'caller operation failure is reported');
    referenceCheck($database->inTransaction() && (int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10 && (int) $database->query('SELECT value FROM reference_rows WHERE id=2')->fetchColumn() === 30, 'savepoint preserves caller work and original row');
    referenceCheck(reference_write_atomic(static fn() => $database->exec('UPDATE reference_rows SET value=40 WHERE id=1') !== false, ['reference_rows']) === true && $database->inTransaction(), 'successful savepoint does not commit caller transaction');
    $database->rollBack();
    referenceCheck((int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10 && (int) $database->query('SELECT COUNT(*) FROM reference_rows WHERE id=2')->fetchColumn() === 0, 'caller controls final rollback');
    $throws = static function () use ($database): void {
        $database->exec('UPDATE reference_rows SET value=50 WHERE id=1');
        $database->exec('INSERT INTO reference_rows VALUES (1,60)');
    };
    referenceCheck(reference_write_atomic($throws, ['reference_rows']) === false && !$database->inTransaction(), 'native constraint exception rolls back owned work');
    referenceCheck((int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10, 'native exception preserves original persisted value');
    foreach (['CREATE TEMPORARY TABLE reference_rows (id INT PRIMARY KEY, value INT) ENGINE=InnoDB', 'CREATE TEMPORARY TABLE reference_rows (id INT PRIMARY KEY, value INT) ENGINE=MyISAM'] as $sql) {
        $database->exec($sql);
        $called = false;
        referenceCheck(reference_write_atomic(static function () use (&$called): bool {
            $called = true;
            return true;
        }, ['reference_rows']) === false && !$called && !$database->inTransaction(), 'temporary participant rejected before operation');
        $database->exec('DROP TEMPORARY TABLE reference_rows');
    }
    $database->beginTransaction();
    $database->exec('INSERT INTO reference_rows VALUES (3,70)');
    referenceCheck(reference_write_atomic($throws, ['reference_rows']) === false && $database->inTransaction(), 'native exception preserves caller transaction');
    referenceCheck((int) $database->query('SELECT value FROM reference_rows WHERE id=3')->fetchColumn() === 70 && (int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10, 'native exception rolls back only its savepoint');
    $database->rollBack();
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $silentFailure = static function () use ($database): bool {
        $database->exec('UPDATE reference_rows SET value=80 WHERE id=1');
        return $database->exec('INSERT INTO reference_rows VALUES (1,90)') !== false;
    };
    referenceCheck(reference_write_atomic($silentFailure, ['reference_rows']) === false && !$database->inTransaction(), 'checked silent native execution failure rolls back operation');
    referenceCheck((int) $database->query('SELECT value FROM reference_rows WHERE id=1')->fetchColumn() === 10, 'silent failure preserves persisted value');
    referenceCheck(reference_write_atomic(static fn() => $database->exec('UPDATE reference_rows SET value=10 WHERE id=1') !== false, ['reference_rows']) === true, 'unchanged successful write is accepted');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('CREATE VIEW reference_view AS SELECT * FROM reference_rows');
    $database->exec('CREATE TABLE nontransactional_rows (id INT PRIMARY KEY) ENGINE=MyISAM');
    $database->exec("CREATE TABLE misleading_engine_rows (id INT PRIMARY KEY) ENGINE=MyISAM COMMENT='ENGINE=InnoDB'");
    foreach (['misleading_engine_rows', 'nontransactional_rows', 'reference_view', 'missing_rows', 'reference_rows;DROP'] as $table) {
        $called = false;
        referenceCheck(reference_write_atomic(static function () use (&$called): bool {
            $called = true;
            return true;
        }, [$table]) === false && !$called && !$database->inTransaction(), 'unsafe participant rejected before operation');
    }
    $database->exec("ALTER TABLE reference_rows COMMENT='ENGINE=MyISAM'");
    referenceCheck(reference_write_atomic(static fn() => $database->exec('UPDATE reference_rows SET value=10 WHERE id=1') !== false, ['reference_rows']) === true, 'actual persistent InnoDB engine is accepted despite misleading comment');
    unset($database_sessions["$database_hostname:$database_port:$database_default"]);
    referenceCheck(reference_write_atomic($write, ['reference_rows']) === false, 'missing selected connection fails closed');
    echo "PASS native local transaction contract\n";
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    if ($created) {
        $database->exec("DROP DATABASE `$schema`");
    }
}
