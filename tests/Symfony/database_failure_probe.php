<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require getcwd() . '/include/cli_check.php';
$db = $database_sessions["$database_hostname:$database_port:$database_default"];
$table = 'site_save_failure_' . bin2hex(random_bytes(6));
$db->exec('CREATE TABLE ' . $table . ' (id INT AUTO_INCREMENT PRIMARY KEY, value INT NOT NULL CHECK (value >= 0))');
$db->exec('INSERT INTO ' . $table . ' (id,value) VALUES (42,1)');
try {
    $db->beginTransaction();
    $result = sql_save(['id' => 42, 'value' => -1], $table, 'id', true, $db);
    if ($result !== false || (int) $db->query('SELECT value FROM ' . $table . ' WHERE id=42')->fetchColumn() !== 1) {
        throw new RuntimeException('Failed SQL save reported an existing identifier');
    }
    $db->rollBack();
    if ((int) sql_save(['id' => 42, 'value' => 1], $table, 'id', true, $db) !== 42) {
        throw new RuntimeException('Unchanged successful save lost its identifier');
    }
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $db->exec('DROP TABLE ' . $table);
}

// Simulate a server deadlock rolling back the transaction before the driver
// reports the error. A second statement would execute without the original locks.
final class DeadlockConnection extends PDO
{
    public int $attempts = 0;
    public function __construct(public bool $transaction) {}
    public function inTransaction(): bool
    {
        return $this->transaction;
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        ++$this->attempts;
        return new DeadlockStatement($this);
    }
    public function errorCode(): ?string
    {
        return '00000';
    }
}
final class DeadlockStatement extends PDOStatement
{
    public function __construct(private DeadlockConnection $connection) {}
    public function execute(?array $params = null): bool
    {
        $this->connection->transaction = false;
        return $this->connection->attempts > 1;
    }
    public function errorCode(): ?string
    {
        return $this->connection->attempts === 1 ? '40001' : '00000';
    }
    public function errorInfo(): array
    {
        return ['40001', 1213, 'Injected deadlock'];
    }
    public function closeCursor(): bool
    {
        return true;
    }
    public function rowCount(): int
    {
        return 1;
    }
}
$transaction = new DeadlockConnection(true);
if (db_execute_prepared('UPDATE fixture SET value=1', [], true, $transaction) !== false || $transaction->attempts !== 1) {
    throw new RuntimeException('Transaction statement was retried after losing its locks');
}
$autocommit = new DeadlockConnection(false);
if (db_execute_prepared('UPDATE fixture SET value=1', [], true, $autocommit) !== true || $autocommit->attempts !== 2) {
    throw new RuntimeException('Standalone retry compatibility changed');
}
define('KADUPUL_THROW_DATABASE_ERRORS', true);
foreach ([true, false] as $transactional) {
    $strict = new DeadlockConnection($transactional);
    try {
        try {
            db_execute_prepared('UPDATE fixture SET value=1', [], true, $strict);
        } catch (Exception) {
            throw new LogicException('Legacy recovery swallowed the strict SQL abort');
        }
        throw new LogicException('Strict worker continued after failed SQL');
    } catch (Error $error) {
        if ($error->getMessage() !== 'Database operation failed.' || $strict->attempts !== 1) {
            throw new LogicException('Strict worker retried SQL or exposed diagnostics');
        }
    }
}
echo 'database failures rejected';
