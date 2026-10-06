<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** The focused SQL harness leaves locale selection to the real HTTP probe. */
function __(string $message): string
{
    return $message;
}

/** Isolated installer logging port; SQL below uses the actual fixture PDO. */
function db_install_add_cache(int $status, string $sql, ?array $params = null): void
{
    $GLOBALS['forward_schema_status'][] = $status;
}

function db_install_execute(string $sql, array $params = [], bool $log = true): int
{
    $db = $GLOBALS['forward_schema_pdo'];
    $statement = $db->prepare($sql);
    $status = $statement !== false && $statement->execute($params) ? DB_STATUS_SUCCESS : DB_STATUS_ERROR;
    db_install_add_cache($status, $sql);
    return $status;
}

function db_index_exists(string $table, string $index): bool
{
    $statement = $GLOBALS['forward_schema_pdo']->query('SHOW INDEXES FROM `' . $table . '`');
    if ($statement === false) {
        throw new RuntimeException('Native fixture index metadata failed.');
    }
    return array_any($statement->fetchAll(PDO::FETCH_ASSOC), static fn(array $row): bool => $row['Key_name'] === $index);
}

function db_install_fetch_cell(string $sql, array $params = []): array
{
    $statement = $GLOBALS['forward_schema_pdo']->prepare($sql);
    if ($statement === false || !$statement->execute($params)) {
        return ['status' => DB_STATUS_ERROR, 'data' => false];
    }
    return ['status' => DB_STATUS_SUCCESS, 'data' => $statement->fetchColumn()];
}
