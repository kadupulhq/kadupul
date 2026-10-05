<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Own one database/session lock, including ambiguous acquisition and release failures. */
function cacti_csrf_rotation_locked(PDO $connection, string $purpose, callable $operation): bool
{
    if ($connection->inTransaction()) {
        return false;
    }
    $database = $connection->query('SELECT DATABASE()');
    $database = $database === false ? false : $database->fetchColumn();
    if (!is_string($database) || $database === '') {
        return false;
    }
    $lock = $purpose . substr(hash('sha256', $database), 0, 64 - strlen($purpose));
    $owned = $connection->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
    if ($owned === false || !$owned->execute(array($lock)) || (string) $owned->fetchColumn() === '1') {
        // Never increment/release a caller's existing reentrant lock.
        return false;
    }
    $acquire = $connection->prepare('SELECT GET_LOCK(?, 5)');
    if ($acquire === false) {
        return false;
    }
    $success = false;
    $released = false;
    try {
        // Even an exception here may mean the server acquired the lock before
        // its reply failed. Finally attempts release on this exact PDO.
        if ($acquire->execute(array($lock)) && (string) $acquire->fetchColumn() === '1'
            && $owned->execute(array($lock)) && (string) $owned->fetchColumn() === '1') {
            $success = $operation($owned, $lock);
        }
    } catch (Throwable $error) {
        $success = false;
    } finally {
        try {
            $release = $connection->prepare('SELECT RELEASE_LOCK(?)');
            $released = $release !== false && $release->execute(array($lock)) && (string) $release->fetchColumn() === '1';
        } catch (Throwable $error) {
            $released = false;
        }
    }

    return $success && $released;
}

/** Rotate a primary key and propagate it without legacy reconnect/retry helpers. */
function cacti_rotate_database_csrf_secret(PDO $primary, string $secret, int $heartbeat_limit, callable $connect, callable $warning): bool
{
    return cacti_csrf_rotation_locked($primary, 'kadupul.csrf.', static function (PDOStatement $owned, string $lock) use ($primary, $secret, $heartbeat_limit, $connect, $warning): bool {
        $sql = 'INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)';
        $write = $primary->prepare($sql);
        if ($write === false || !$write->execute(array('csrf_secret', $secret))) {
            return false;
        }
        $read = $primary->prepare('SELECT value FROM settings WHERE name = ?');
        if ($read === false || !$read->execute(array('csrf_secret')) || $read->fetchColumn() !== $secret) {
            return false;
        }
        // The primary still commits independently. Collector failure cannot
        // roll it back, and must never be reported as complete propagation.
        $pollers = $primary->query('SELECT id, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(last_status) AS last_polled FROM poller WHERE id > 1 AND disabled=""');
        if ($pollers === false || !is_array($rows = $pollers->fetchAll(PDO::FETCH_ASSOC))) {
            return false;
        }
        $success = true;
        foreach ($rows as $poller) {
            $id = (int) $poller['id'];
            if ($poller['last_polled'] > $heartbeat_limit) {
                $warning($id, true);
                $success = false;
                continue;
            }
            $collector = $connect($id);
            if (!$collector instanceof PDO) {
                $warning($id, false);
                $success = false;
                continue;
            }
            if (!$owned->execute(array($lock)) || (string) $owned->fetchColumn() !== '1') {
                return false;
            }
            // A remote statement can outlive a killed client. Its server-side
            // session must release this lock before a newer write can pass.
            // A different purpose avoids reentering the primary lock for aliases.
            $propagated = cacti_csrf_rotation_locked($collector, 'kadupul.csrf.remote.', static function () use ($collector, $secret, $sql): bool {
                $write = $collector->prepare($sql);
                $read = $collector->prepare('SELECT value FROM settings WHERE name = ?');
                return $write !== false && $write->execute(array('csrf_secret', $secret))
                    && $read !== false && $read->execute(array('csrf_secret')) && $read->fetchColumn() === $secret;
            });
            if (!$propagated) {
                $warning($id, false);
                $success = false;
            }
        }
        $read = $primary->prepare('SELECT value FROM settings WHERE name = ?');
        return $success && $read !== false && $read->execute(array('csrf_secret')) && $read->fetchColumn() === $secret;
    });
}
