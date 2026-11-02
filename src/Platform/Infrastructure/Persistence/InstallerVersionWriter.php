<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

/** Confirm migration markers using the caller's exact native connection and owned transaction. */
final readonly class InstallerVersionWriter
{
    /** Refuse caller-owned transactions; failures retain the existing boolean retry contract. */
    public function record(PDO $db, string $version): bool
    {
        $started = false;
        try {
            if ($db->inTransaction()) {
                return false;
            }
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'mysql') {
                $metadata = $db->query('SHOW CREATE TABLE `version`');
                if ($metadata === false) {
                    return false;
                }
                $table = $metadata->fetch(PDO::FETCH_ASSOC);
                if ($this->errorCode($metadata) !== '00000' || $this->errorCode($db) !== '00000'
                    || !is_array($table) || !is_string($table['Create Table'] ?? null)
                    || preg_match('/\ACREATE TABLE\s/i', $table['Create Table']) !== 1
                    || !$metadata->closeCursor() || $this->errorCode($metadata) !== '00000') {
                    return false;
                }
                $status = $db->query("SHOW TABLE STATUS WHERE Name = 'version'");
                if ($status === false || $this->errorCode($db) !== '00000') {
                    return false;
                }
                $engine = $status->fetch(PDO::FETCH_ASSOC);
                $additional = $status->fetch(PDO::FETCH_ASSOC);
                if ($this->errorCode($status) !== '00000' || $this->errorCode($db) !== '00000'
                    || !is_array($engine) || ($engine['Name'] ?? null) !== 'version'
                    || ($engine['Engine'] ?? null) !== 'InnoDB' || $additional !== false
                    || !$status->closeCursor() || $this->errorCode($status) !== '00000') {
                    return false;
                }
            } elseif ($driver !== 'sqlite') {
                return false;
            }
            if (!$db->beginTransaction()) {
                return false;
            }
            $started = true;
            if ($this->errorCode($db) !== '00000') {
                throw new RuntimeException('Database version transaction could not be confirmed.');
            }
            $previous = $db->query('SELECT cacti FROM version' . ($driver === 'mysql' ? ' FOR UPDATE' : ''));
            if ($previous === false) {
                throw new RuntimeException('Database version snapshot is unavailable.');
            }
            $rows = $previous->fetchAll(PDO::FETCH_COLUMN);
            if ($this->errorCode($previous) !== '00000' || $this->errorCode($db) !== '00000' || count($rows) > 1) {
                throw new RuntimeException('Database version snapshot could not be confirmed.');
            }
            $sql = $rows === [] ? 'INSERT INTO version (cacti) VALUES (?)' : 'UPDATE version SET cacti = ?';
            if (($write = $db->prepare($sql)) === false || !$write->execute([$version])
                || $this->errorCode($write) !== '00000' || $this->errorCode($db) !== '00000') {
                throw new RuntimeException('Database version write could not be confirmed.');
            }
            $confirmed = $db->query('SELECT cacti FROM version');
            if ($confirmed === false || $confirmed->fetchAll(PDO::FETCH_COLUMN) !== [$version]
                || $this->errorCode($confirmed) !== '00000' || $this->errorCode($db) !== '00000') {
                throw new RuntimeException('Database version readback could not be confirmed.');
            }
            if (!$db->commit() || $this->errorCode($db) !== '00000') {
                throw new RuntimeException('Database version commit could not be confirmed.');
            }
            return true;
        } catch (Throwable $error) {
            try {
                if ($started && $db->inTransaction()) {
                    if (!$db->rollBack() || $this->errorCode($db) !== '00000') {
                        throw new RuntimeException('Database version rollback could not be confirmed.');
                    }
                }
            } catch (Throwable $cleanup) {
                // Never publish a successful outcome after uncertain cleanup.
            }
            return false;
        }
    }

    /**
     * Driver status changes after each operation; do not memoize prior successful reads.
     * @phpstan-impure
     */
    private function errorCode(PDO|\PDOStatement $source): ?string
    {
        return $source->errorCode();
    }

}
