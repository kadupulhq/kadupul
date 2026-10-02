<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$scenario = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$prefix = 'placement_contract_' . bin2hex(random_bytes(8)) . '_';
$tables = ['graph_tree', 'graph_tree_items', 'reports', 'reports_items', 'host'];
$database = new class (getenv('KADUPUL_TEST_MYSQL_DSN'), getenv('KADUPUL_TEST_MYSQL_USER') ?: 'root', getenv('KADUPUL_TEST_MYSQL_PASSWORD') ?: '') extends PDO {
    /** @var array<string, int> */
    public array $calls = ['begin' => 0, 'commit' => 0, 'rollback' => 0];

    public function beginTransaction(): bool
    {
        $this->calls['begin']++;
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        $this->calls['commit']++;
        return parent::commit();
    }

    public function rollBack(): bool
    {
        $this->calls['rollback']++;
        return parent::rollBack();
    }
};
$database->exec("SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'");
$database_hostname = 'fixture';
$database_port = 3306;
$database_default = 'owned-placement-contract';
$database_sessions = ['fixture:3306:owned-placement-contract' => $database];
$_SESSION['sess_user_id'] = 7;

function placement_sql(string $sql): string
{
    return preg_replace_callback('/\b(' . implode('|', $GLOBALS['tables']) . ')\b/', static fn(array $match): string => $GLOBALS['prefix'] . $match[1], $sql);
}

function db_fetch_cell_prepared(string $sql, array $parameters = []): mixed
{
    $statement = $GLOBALS['database']->prepare(placement_sql($sql));
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

function db_fetch_cell(string $sql): mixed
{
    return db_fetch_cell_prepared($sql);
}

function db_execute_prepared(string $sql, array $parameters = []): bool
{
    $statement = $GLOBALS['database']->prepare(placement_sql($sql));
    $result = $statement->execute($parameters);
    if ($GLOBALS['scenario']['throw'] && str_starts_with($sql, 'INSERT INTO ')) {
        throw new RuntimeException('Placement fixture failure after write');
    }
    return $result;
}

// UI/authorization and sorting are boundaries outside this transaction contract.
function cacti_authorize_resource(mixed ...$arguments): bool
{
    return true;
}
function input_validate_input_number(mixed $value): void {}
function form_input_validate(mixed $value, mixed ...$arguments): mixed
{
    return $value;
}
function is_error_message(): bool
{
    return false;
}
function raise_message(mixed ...$arguments): void {}
function set_config_option(mixed ...$arguments): void {}
function api_tree_sort_branch(mixed ...$arguments): void {}
function __(string $message, mixed ...$arguments): string
{
    return $arguments ? sprintf($message, ...$arguments) : $message;
}

function sql_save(array $values, string $table): int|false
{
    unset($values['id']);
    $sql = 'INSERT INTO ' . $table . ' (`' . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')';
    db_execute_prepared($sql, array_values($values));
    return (int) $GLOBALS['database']->lastInsertId();
}

require $root . '/include/global_constants.php';
require $root . '/tests/Helpers/PhpSource.php';
$databaseSource = file_get_contents($root . '/lib/database.php');
foreach (['db_begin_transaction', 'db_commit_transaction', 'db_rollback_transaction'] as $function) {
    eval(test_php_function_source($databaseSource, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
}
foreach (['lib/api_tree.php' => ['api_tree_item_save', 'api_tree_item_save_locked'], 'lib/reports.php' => ['reports_add_devices', 'reports_add_devices_locked']] as $file => $functions) {
    $source = file_get_contents($root . '/' . $file);
    foreach ($functions as $function) {
        eval(test_php_function_source($source, $function)); // nosemgrep: php.lang.security.eval-use.eval-use
    }
}

try {
    $schema = file_get_contents($root . '/cacti.sql');
    foreach ($tables as $table) {
        if (!preg_match('/CREATE TABLE `?' . preg_quote($table, '/') . '`? \(.*?;\n/s', $schema, $match)) {
            throw new RuntimeException('Missing install table: ' . $table);
        }
        $database->exec(placement_sql($match[0]));
    }
    $database->exec(placement_sql("INSERT INTO graph_tree (id, name) VALUES (9,'owned tree')"));
    $database->exec(placement_sql("INSERT INTO reports (id, name, user_id) VALUES (9,'owned report',7)"));
    $database->exec(placement_sql("INSERT INTO host (id, description, hostname) VALUES (11,'owned device','127.0.0.1')"));
    if ($scenario['caller']) {
        $database->beginTransaction();
        $database->exec(placement_sql("UPDATE host SET description='caller work' WHERE id=11"));
    }
    $database->calls = ['begin' => 0, 'commit' => 0, 'rollback' => 0];
    $target = $scenario['missing'] ? 99 : 9;
    $failure = false;
    try {
        $result = $scenario['kind'] === 'tree'
            ? api_tree_item_save(0, $target, TREE_ITEM_TYPE_HOST, 0, '', 0, 11, 0, 1, 1, false)
            : reports_add_devices($target, [11], 7, 2);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Placement fixture failure after write') {
            throw $error;
        }
        $failure = true;
        $result = false;
    }
    $active = $database->inTransaction();
    $calls = $database->calls;
    $items = $scenario['kind'] === 'tree' ? 'graph_tree_items' : 'reports_items';
    $inside = (int) $database->query(placement_sql('SELECT COUNT(*) FROM ' . $items))->fetchColumn();
    $callerWork = $database->query(placement_sql('SELECT description FROM host WHERE id=11'))->fetchColumn();
    if ($active) {
        $database->rollBack();
    }
    $after = (int) $database->query(placement_sql('SELECT COUNT(*) FROM ' . $items))->fetchColumn();
    echo json_encode(['result' => $result, 'failure' => $failure, 'active' => $active, 'calls' => $calls, 'inside' => $inside, 'after' => $after, 'caller_work' => $callerWork], JSON_THROW_ON_ERROR);
} finally {
    if ($database->inTransaction()) {
        $database->rollBack();
    }
    foreach (array_reverse($tables) as $table) {
        $database->exec('DROP TABLE IF EXISTS ' . $prefix . $table);
    }
}
