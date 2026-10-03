<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Database seams execute the unchanged production SQL against owned SQLite tables. */
function variable_title_statement(string $sql, array $parameters): PDOStatement
{
    $statement = $GLOBALS['variable_title_database']->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}

function db_fetch_assoc_prepared($sql, $parameters = [])
{
    return variable_title_statement($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
}

function db_fetch_row_prepared($sql, $parameters = [])
{
    return variable_title_statement($sql, $parameters)->fetch(PDO::FETCH_ASSOC) ?: [];
}

function db_fetch_cell_prepared($sql, $parameters = [])
{
    return variable_title_statement($sql, $parameters)->fetchColumn();
}

function db_execute_prepared($sql, $parameters = [])
{
    variable_title_statement($sql, $parameters);
    $GLOBALS['variable_title_writes'][] = $parameters;
    return true;
}

function api_plugin_hook_function($name, $payload)
{
    $GLOBALS['variable_title_hooks'][] = [$name, $payload];
    return $payload;
}
