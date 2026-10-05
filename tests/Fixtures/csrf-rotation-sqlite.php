<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** SQLite boundary adapter; file-backed advisory locks coordinate actual CLI processes. */
final class CsrfRotationSqlite extends PDO
{
    private mixed $lockHandle = null;

    public function __construct(string $file, string $identity, string $lockFile)
    {
        parent::__construct('sqlite:' . $file, options: array(PDO::ATTR_ERRMODE => PDO::ERRMODE_SILENT));
        $this->exec('CREATE TABLE IF NOT EXISTS settings(name TEXT PRIMARY KEY,value TEXT NOT NULL)');
        $this->exec('CREATE TABLE IF NOT EXISTS poller(id INTEGER,last_status TEXT,disabled TEXT)');
        $this->sqliteCreateFunction('DATABASE', static fn() => $identity);
        $this->sqliteCreateFunction('CONNECTION_ID', static fn() => getmypid());
        $this->sqliteCreateFunction('UNIX_TIMESTAMP', static fn(...$args) => $args ? strtotime($args[0]) : time());
        $this->sqliteCreateFunction('GET_LOCK', function ($name, $seconds) use ($lockFile) {
            $handle = fopen($lockFile, 'c+');
            if ($handle === false) {
                return null;
            }
            $deadline = microtime(true) + $seconds;
            do {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    $this->lockHandle = $handle;
                    ftruncate($handle, 0);
                    fwrite($handle, (string) getmypid());
                    fflush($handle);
                    return 1;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            fclose($handle);
            return 0;
        });
        $this->sqliteCreateFunction('IS_USED_LOCK', static fn($name) => is_file($lockFile) ? (int) file_get_contents($lockFile) : null);
        $this->sqliteCreateFunction('RELEASE_LOCK', function ($name) {
            if (!is_resource($this->lockHandle)) {
                return null;
            }
            ftruncate($this->lockHandle, 0);
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
            return 1;
        });
    }

    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        $query = str_replace('ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)', 'ON CONFLICT(name) DO UPDATE SET value = excluded.value', $query);
        return parent::prepare($query, $options);
    }
}
