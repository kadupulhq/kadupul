<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Isolated process fixture: exercise the real legacy helper and writer with
// driver failures, including a deadlock that has already rolled back the server.
final class DisableFailureConnection extends PDO
{
    public int $saves = 0;
    public int $updates = 0;
    public int $reads = 0;
    public function __construct(public bool $transaction, public string $failure) {}
    public function inTransaction(): bool
    {
        return $this->transaction;
    }
    public function beginTransaction(): bool
    {
        return $this->transaction = true;
    }
    public function commit(): bool
    {
        $this->transaction = false;
        return true;
    }
    public function rollBack(): bool
    {
        $this->transaction = false;
        return true;
    }
}
function db_execute_prepared(...$args): bool
{
    global $disableDb;
    ++$disableDb->updates;
    if ($disableDb->failure === 'deadlock') {
        $disableDb->transaction = false;
        return false;
    }
    return !($disableDb->failure === 'primary' || ($disableDb->failure === 'remote' && $disableDb->updates === 2));
}
function db_fetch_cell_prepared(...$args): int
{
    global $disableDb;
    ++$disableDb->reads;
    if ($disableDb->failure === 'lost') {
        $disableDb->transaction = false;
    }
    return $disableDb->failure === 'remote' ? 2 : 1;
}
function remote_poller_up(...$args): bool
{
    return true;
}
function poller_push_to_remote_db_connect(...$args): PDO
{
    global $disableDb;
    return $disableDb;
}
function sql_save(...$args): int
{
    global $disableDb;
    ++$disableDb->saves;
    return 42;
}
function is_error_message(): bool
{
    return false;
}
