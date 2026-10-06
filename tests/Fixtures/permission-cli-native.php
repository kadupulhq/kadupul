<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/** Read-only PDO boundary for the unchanged trusted CLI list commands. */
function permission_cli_query(string $sql, array $parameters = []): PDOStatement
{
    if (!preg_match('/^\s*SELECT\b/i', $sql)) {
        throw new RuntimeException('Permission listing attempted a mutation');
    }
    $GLOBALS['permissionQueries'][] = [$sql, $parameters];
    $statement = $GLOBALS['permissionDatabase']->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function db_fetch_assoc(string $sql): array
{
    return permission_cli_query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_assoc_prepared(string $sql, array $parameters): array
{
    return permission_cli_query($sql, $parameters)->fetchAll(PDO::FETCH_ASSOC);
}
function db_fetch_cell(string $sql): mixed
{
    return permission_cli_query($sql)->fetchColumn();
}
function __(string $text, mixed ...$arguments): string
{
    return $arguments === [] ? $text : sprintf($text, ...$arguments);
}

function permission_cli_snapshot(): array
{
    $snapshot = [];
    foreach ($GLOBALS['permissionTables'] as $table) {
        $snapshot[$table] = $GLOBALS['permissionDatabase']->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $snapshot;
}

function permission_cli_observe(): void
{
    if (!isset($GLOBALS['permissionBefore']) || permission_cli_snapshot() !== $GLOBALS['permissionBefore']) {
        throw new RuntimeException('Permission CLI changed persisted records');
    }
    $database = $GLOBALS['permissionDatabase'];
    $readOnly = $database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? $database->query('PRAGMA query_only')->fetchColumn()
        : $database->query('SELECT @@session.tx_read_only')->fetchColumn();
    $result = ['unchanged' => true, 'queries' => $GLOBALS['permissionQueries'], 'readOnly' => (int) $readOnly, 'engine' => $database->getAttribute(PDO::ATTR_DRIVER_NAME)];
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    if (file_put_contents($GLOBALS['permissionDirectory'] . '/state.json', $json) !== strlen($json)) {
        throw new RuntimeException('Cannot preserve permission CLI observer');
    }
    $GLOBALS['nativeChildCoverageMarkers'][] = 'permission-cli-persisted-state-unchanged';
}

function permission_cli_prepare(string $root, string $directory, array $scenario): void
{
    $schema = file_get_contents($root . '/cacti.sql');
    if ($schema === false) throw new RuntimeException('Cannot read canonical permission schema');
    $dsn = getenv('NATIVE_PERMISSION_MYSQL_DSN');
    if (is_string($dsn) && $dsn !== '') {
        $database = new PDO($dsn, getenv('NATIVE_PERMISSION_MYSQL_USER') ?: null, getenv('NATIVE_PERMISSION_MYSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Native permission backend must be MySQL-compatible');
        $ownedSchema = 'kadupul_native_permission_' . bin2hex(random_bytes(10));
        $database->exec('CREATE DATABASE `' . $ownedSchema . '`');
        $database->exec('USE `' . $ownedSchema . '`');
        register_shutdown_function(static function () use ($database, $ownedSchema): void {
            if ($database->inTransaction()) $database->rollBack();
            $database->exec('SET SESSION TRANSACTION READ WRITE');
            $database->exec('DROP DATABASE `' . $ownedSchema . '`');
        });
    } else {
        $database = new PDO('sqlite:' . $directory . '/permissions.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    $tables = ['host', 'host_template', 'graph_tree', 'graph_local', 'graph_templates_graph', 'graph_templates', 'version', 'user_auth_perms'];
    foreach ($tables as $table) {
        if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=/s', $schema, $match) !== 1) throw new RuntimeException('Missing canonical permission table');
        if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            if (preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \((.*?)\) ENGINE=[^;]+;/s', $schema, $ddl) !== 1) throw new RuntimeException('Cannot read full canonical permission table');
            $database->exec($ddl[0]);
            continue;
        }
        $columns = [];
        foreach (explode("\n", $match[1]) as $line) {
            $line = rtrim(trim($line), ',');
            if ($line === '' || preg_match('/^(?:KEY|UNIQUE KEY|CONSTRAINT) /i', $line)) continue;
            $line = preg_replace('/\b(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?/i', 'INTEGER', $line);
            if ($line === null) throw new RuntimeException('Cannot adapt canonical integer column');
            $line = preg_replace('/\b(?:varchar|char)\(\d+\)/i', 'TEXT', $line);
            if ($line === null) throw new RuntimeException('Cannot adapt canonical text column');
            $line = preg_replace('/\bAUTO_INCREMENT\b|\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $line);
            if ($line === null) throw new RuntimeException('Cannot adapt canonical column attributes');
            $columns[] = $line;
        }
        if ($columns === []) throw new RuntimeException('Canonical permission table has no columns');
        $database->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
    }
    $version = file_get_contents($root . '/include/cacti_version');
    if ($version === false || preg_match('/^\d+\.\d+\.\d+\s*$/D', $version) !== 1) throw new RuntimeException('Cannot read current permission version');
    define('CACTI_VERSION', trim($version));
    $database->prepare('INSERT INTO version (cacti) VALUES (?)')->execute([CACTI_VERSION]);
    $database->exec('INSERT INTO user_auth_perms (user_id, item_id, type) VALUES (42, 101, 1)');
    if (!($scenario['empty'] ?? false)) {
        $database->exec("INSERT INTO graph_tree (id, sort_type, name) VALUES (7, 1, 'First tree'), (9, 1, 'Adjacent tree')");
        $database->exec("INSERT INTO host (id, description) VALUES (21, 'Selected device'), (22, 'Adjacent device')");
        $database->exec("INSERT INTO graph_templates (id, name) VALUES (31, 'Traffic template')");
        $database->exec('INSERT INTO graph_local (id, host_id, graph_template_id) VALUES (101, 21, 31), (202, 22, 31)');
        $database->exec("INSERT INTO graph_templates_graph (id, local_graph_id, title_cache) VALUES (1, 101, 'Selected graph'), (2, 202, 'Adjacent graph')");
    }
    if ($database->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $database->exec('PRAGMA query_only=ON');
    } else {
        $database->exec('SET SESSION TRANSACTION READ ONLY');
        $database->beginTransaction();
    }
    $GLOBALS['permissionDatabase'] = $database;
    $GLOBALS['permissionTables'] = $tables;
    $GLOBALS['permissionDirectory'] = $directory;
    $GLOBALS['permissionQueries'] = [];
    $GLOBALS['permissionBefore'] = permission_cli_snapshot();
    require $root . '/include/global_constants.php';
    require $root . '/lib/functions.php';
    // CLI locale/configuration port; actual list querying/rendering is unchanged.
    $GLOBALS['tree_sort_types'] = [TREE_ORDERING_NONE => 'Manual Ordering (No Sorting)'];
    $GLOBALS['config'] = ['base_path' => $root, 'poller_id' => 1];
    $_SERVER['argv'] = array_merge([$directory . '/cli/add_perms.php'], $scenario['arguments']);
}
