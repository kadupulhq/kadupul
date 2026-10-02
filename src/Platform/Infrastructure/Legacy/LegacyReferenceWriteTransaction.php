<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Local atomic writes; this never promotes a collector or rolls back another server. */
final class LegacyReferenceWriteTransaction
{
    public function __construct(private readonly \PDO $database) {}

    /** @param list<string> $tables */
    public function run(\Closure $operation, array $tables): mixed
    {
        if ($this->database->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('Native reference writes require MySQL or MariaDB.');
        }
        foreach ($tables as $table) {
            if (preg_match('/^[a-z_][a-z0-9_]*$/D', $table) !== 1) {
                throw new \InvalidArgumentException('Invalid reference write participant.');
            }
            $query = $this->database->query("SHOW CREATE TABLE `$table`");
            if ($query === false || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('Reference write participant metadata could not be read.');
            }
            $row = $query->fetch(\PDO::FETCH_ASSOC);
            if ($query->errorCode() !== '00000' || !is_array($row)
                || !is_string($row['Create Table'] ?? null)
                || preg_match('/\ACREATE TABLE\s/i', $row['Create Table']) !== 1) {
                throw new \RuntimeException('Reference writes require persistent transactional participants.');
            }
            if (!$query->closeCursor() || $query->errorCode() !== '00000') {
                throw new \RuntimeException('Reference write participant metadata close could not be confirmed.');
            }
            // SHOW CREATE comments/column literals can contain ENGINE=InnoDB.
            // After rejecting temporary tables/views, obtain the persistent
            // table's actual engine from native metadata on this same session.
            $status = $this->database->query("SHOW TABLE STATUS WHERE Name = '$table'");
            if ($status === false || $this->database->errorCode() !== '00000') {
                throw new \RuntimeException('Reference write participant engine could not be read.');
            }
            $metadata = $status->fetch(\PDO::FETCH_ASSOC);
            $extra = $status->fetch(\PDO::FETCH_ASSOC);
            if ($status->errorCode() !== '00000' || !is_array($metadata)
                || ($metadata['Name'] ?? null) !== $table
                || ($metadata['Engine'] ?? null) !== 'InnoDB'
                || $extra !== false) {
                throw new \RuntimeException('Reference writes require persistent transactional participants.');
            }
            if (!$status->closeCursor() || $status->errorCode() !== '00000') {
                throw new \RuntimeException('Reference write participant engine close could not be confirmed.');
            }
        }
        $owned = false;
        $savepoint = null;
        $commitAttempted = false;
        try {
            if ($this->database->inTransaction()) {
                $name = 'kadupul_reference_' . bin2hex(random_bytes(8));
                $this->execute('SAVEPOINT ' . $name);
                $savepoint = $name;
            } else {
                $owned = $this->database->beginTransaction();
                if (!$owned || !$this->database->inTransaction()) {
                    throw new \RuntimeException('Reference write transaction could not be started.');
                }
            }
            $result = $operation();
            if ($result === false) {
                throw new \RuntimeException('A reference write could not be confirmed.');
            }
            if ($savepoint !== null) {
                $this->execute('RELEASE SAVEPOINT ' . $savepoint);
                $savepoint = null;
            } else {
                $commitAttempted = true;
                if (!$this->database->commit() || $this->database->inTransaction()) {
                    throw new \RuntimeException('Reference write commit could not be confirmed.');
                }
            }

            return $result;
        } catch (\Throwable $error) {
            try {
                if ($savepoint !== null && $this->database->inTransaction()) {
                    $this->execute('ROLLBACK TO SAVEPOINT ' . $savepoint);
                    $this->execute('RELEASE SAVEPOINT ' . $savepoint);
                } elseif ($owned && $this->database->inTransaction()) {
                    if (!$this->database->rollBack() || $this->database->inTransaction()) {
                        throw new \RuntimeException('Reference write rollback could not be confirmed.');
                    }
                }
            } catch (\Throwable) {
                throw new \RuntimeException('Reference write and cleanup could not be confirmed. Reload before retrying.', 0, $error);
            }
            if ($commitAttempted) {
                throw new \RuntimeException('Reference write commit could not be confirmed. Reload before retrying.', 0, $error);
            }
            throw $error;
        }
    }

    private function execute(string $sql): void
    {
        if ($this->database->exec($sql) === false || $this->database->errorCode() !== '00000') {
            throw new \RuntimeException('Reference write savepoint could not be confirmed.');
        }
    }
}
