<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The tree editor sends a tree id and node ids separately. Node changes must
 * be refused for a tree the user may not access, and a branch of such a tree
 * must not be used as the parent or source of a change in an allowed tree.
 * The production tree, page and permission functions run against SQLite.
 */

namespace TreeNodeAccessTest;

require_once __DIR__ . '/../../../Helpers/PhpSource.php';

$root = dirname(__DIR__, 4);
$functions = array(
    'lib/auth.php' => array('auth_check_perms', 'is_tree_allowed'),
    'lib/api_tree.php' => array(
        'api_tree_node_access',
        'api_tree_branch_in_tree',
        'api_tree_copy_node',
        'api_tree_create_node',
        'api_tree_branch_exists',
        'api_tree_delete_node',
        'api_tree_delete_node_content',
        'api_tree_move_node',
        'api_tree_parse_node_data',
        'api_tree_rename_node',
    ),
    'tree.php' => array('tree_branch_editable', 'set_host_sort_type', 'set_branch_sort_type'),
    'graphs.php' => array('add_tree_names_to_actions_array'),
);
foreach ($functions as $file => $names) {
    $source = file_get_contents($root . '/' . $file);
    if ($source === false) {
        throw new \RuntimeException('Unable to read ' . $file);
    }
    foreach ($names as $name) {
        try {
            $code = test_php_function_source($source, $name);
        } catch (\RuntimeException $e) {
            // A guard that does not exist yet must leave the old behaviour observable.
            continue;
        }
        eval('namespace ' . __NAMESPACE__ . '; ' . $code);
    }
}

const USER = 5;
const ALLOWED = 1;
const FORBIDDEN = 2;
const TREE_ORDERING_INHERIT = 1;
const TREE_ORDERING_NONE = 2;
const TREE_ORDERING_ALPHABETIC = 3;
const TREE_ORDERING_NUMERIC = 4;
const TREE_ORDERING_NATURAL = 5;
const HOST_GROUPING_GRAPH_TEMPLATE = 1;
const HOST_GROUPING_DATA_QUERY_INDEX = 2;
const MESSAGE_LEVEL_ERROR = 3;
const TREE_ITEM_TYPE_GRAPH = 2;

function db(): \PDO
{
    return $GLOBALS['tree_node_access_db'];
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = array())
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetch(\PDO::FETCH_ASSOC) ?: array();
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = array())
{
    return db()->prepare($sql)->execute($params);
}
function sql_save($save, $table)
{
    $columns = array_keys($save);
    db()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')
        ->execute(array_values($save));

    return (int) db()->lastInsertId();
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = 0)
{
    $GLOBALS['tree_node_access_log'][] = $message;
}
function read_config_option($name)
{
    return $name === 'auth_method' ? 1 : '';
}
function set_config_option($name, $value) {}
function auth_perm_cache_check_reset($user_id) {}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
function input_validate_input_number($value)
{
    if ($value !== '' && $value !== null && !is_numeric($value)) {
        throw new \InvalidArgumentException('Validation error');
    }
}
function __($text)
{
    return $text;
}
function api_tree_sort_branch($leaf_id, $tree_id = 0, $lock = true) {}
function isset_request_var($name)
{
    return isset($GLOBALS['tree_node_access_request'][$name]);
}
function get_request_var($name)
{
    return $GLOBALS['tree_node_access_request'][$name] ?? '';
}
function set_request_var($name, $value)
{
    $GLOBALS['tree_node_access_request'][$name] = $value;
}
function sanitize_search_string($value)
{
    return $value;
}
function header($value) {}
function __esc($text, ...$arguments)
{
    return sprintf($text, ...$arguments);
}
function db_fetch_assoc($sql)
{
    return db_fetch_assoc_prepared($sql);
}
function get_filter_request_var($name)
{
    return get_request_var($name);
}
function get_nfilter_request_var($name)
{
    return get_request_var($name);
}
function cacti_count($value)
{
    return count($value);
}
function raise_message($id, $message = '', $level = 0)
{
    $GLOBALS['tree_node_access_log'][] = $message;
}
function api_tree_item_save(...$arguments)
{
    $GLOBALS['tree_node_access_placed'][] = array($arguments[1], $arguments[3], $arguments[5]);
}

/** The graphs.php form handler block that places the selected graphs on a tree. */
function place_graphs(int $tree_id, int $branch): array
{
    $GLOBALS['tree_node_access_placed'] = array();
    $GLOBALS['tree_node_access_request'] = array('drp_action' => 'tr_' . $tree_id, 'tree_id' => $tree_id, 'tree_item_id' => $branch);
    $selected_items = array(100, 101);
    $source = file_get_contents(dirname(__DIR__, 4) . '/graphs.php');
    eval('namespace ' . __NAMESPACE__ . '; if (' . test_php_block_source($source, "preg_match('/^tr_([0-9]+)$/', get_request_var('drp_action'), \$matches))") . ';');

    return $GLOBALS['tree_node_access_placed'];
}

/** Two trees: the user holds an exception for the first only, under a deny-all policy. */
function seed(): void
{
    $db = new \PDO('sqlite::memory:', null, null, array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION));
    $db->exec('CREATE TABLE graph_tree (id INTEGER, name VARCHAR(20), enabled VARCHAR(2))');
    $db->exec('CREATE TABLE user_auth (id INTEGER, policy_trees INTEGER)');
    $db->exec('CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER)');
    $db->exec('CREATE TABLE user_auth_group (id INTEGER, enabled VARCHAR(2), policy_trees INTEGER)');
    $db->exec('CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)');
    $db->exec('CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER)');
    $db->exec('CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY AUTOINCREMENT, graph_tree_id INTEGER, parent INTEGER, position INTEGER,
        title VARCHAR(255), local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0,
        host_grouping_type INTEGER DEFAULT 1, sort_children_type INTEGER DEFAULT 1)');
    $db->exec("INSERT INTO graph_tree VALUES (1, 'allowed', 'on'), (2, 'forbidden', 'on')");
    $db->exec('INSERT INTO user_auth VALUES (' . USER . ', 2)');
    $db->exec('INSERT INTO user_auth_perms VALUES (' . USER . ', ' . ALLOWED . ', 2)');
    $db->exec("INSERT INTO graph_tree_items (id, graph_tree_id, parent, position, title) VALUES
        (10, 1, 0, 1, 'allowed branch'), (11, 1, 10, 1, 'allowed child'),
        (20, 2, 0, 1, 'forbidden branch'), (21, 2, 20, 1, 'forbidden child'), (22, 2, 20, 2, 'forbidden sibling')");
    $GLOBALS['tree_node_access_db'] = $db;
    $GLOBALS['tree_node_access_log'] = array();
    $GLOBALS['tree_node_access_request'] = array();
    $_SESSION = array('sess_user_id' => USER);
}

function items(): array
{
    return db()->query('SELECT * FROM graph_tree_items ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
}

/** Run a production entry point, discarding the JSON it prints for the tree editor. */
function call(callable $operation): array
{
    $before = items();
    ob_start();
    try {
        $operation();
    } finally {
        ob_end_clean();
    }

    return array($before, items());
}

beforeEach(fn() => seed());

test('node changes in a tree the user may not access leave the tree unchanged', function (callable $operation) {
    [$before, $after] = call($operation);

    expect($after)->toBe($before)
        ->and(implode("\n", $GLOBALS['tree_node_access_log']))->toContain('not permitted');
})->with(array(
    'create' => fn() => api_tree_create_node(FORBIDDEN, 'tbranch:20', 0, 'added'),
    'delete' => fn() => api_tree_delete_node(FORBIDDEN, 'tbranch:21'),
    'rename' => fn() => api_tree_rename_node(FORBIDDEN, 'tbranch:21', 'renamed'),
    'move' => fn() => api_tree_move_node(FORBIDDEN, 'tbranch:21', '#', 0),
    'copy' => fn() => api_tree_copy_node(FORBIDDEN, 'thost:7', 'tbranch:20', 0),
));

test('a branch of another tree cannot be the parent or source of a change', function (callable $operation) {
    [$before, $after] = call($operation);

    expect($after)->toBe($before)
        ->and(implode("\n", $GLOBALS['tree_node_access_log']))->toContain('is not part of TreeID');
})->with(array(
    'create under it' => fn() => api_tree_create_node(ALLOWED, 'tbranch:20', 0, 'added'),
    'move under it' => fn() => api_tree_move_node(ALLOWED, 'tbranch:11', 'tbranch:20', 0),
    'copy under it' => fn() => api_tree_copy_node(ALLOWED, 'thost:7', 'tbranch:20', 0),
    'copy its title' => fn() => api_tree_copy_node(ALLOWED, 'tbranch:21', 'tbranch:10', 0),
));

test('branch sort settings of a tree the user may not access are not changed', function (string $function, string $type) {
    $GLOBALS['tree_node_access_request'] = array('nodeid' => 'tbranch:20', 'type' => $type);
    [$before, $after] = call(__NAMESPACE__ . '\\' . $function);

    expect($after)->toBe($before);
})->with(array(
    array('set_branch_sort_type', 'alpha'),
    array('set_host_sort_type', 'hsdq'),
));

test('node changes in an allowed tree still apply', function () {
    call(fn() => api_tree_create_node(ALLOWED, 'tbranch:10', 0, 'added'));
    call(fn() => api_tree_rename_node(ALLOWED, 'tbranch:11', 'renamed'));
    call(fn() => api_tree_copy_node(ALLOWED, 'thost:7', 'tbranch:10', 0));
    $GLOBALS['tree_node_access_request'] = array('nodeid' => 'tbranch:10', 'type' => 'alpha');
    call(__NAMESPACE__ . '\\set_branch_sort_type');
    $GLOBALS['tree_node_access_request'] = array('nodeid' => 'tbranch:10', 'type' => 'hsdq');
    call(__NAMESPACE__ . '\\set_host_sort_type');
    call(fn() => api_tree_move_node(ALLOWED, 'tbranch:11', '#', 0));
    $rows = array_column(items(), null, 'id');

    expect($rows[11]['title'])->toBe('renamed')
        ->and((int) $rows[11]['parent'])->toBe(0)
        ->and((int) $rows[10]['sort_children_type'])->toBe(TREE_ORDERING_ALPHABETIC)
        ->and((int) $rows[10]['host_grouping_type'])->toBe(HOST_GROUPING_DATA_QUERY_INDEX)
        ->and(array_values(array_filter($rows, fn(array $row): bool => (int) $row['graph_tree_id'] === ALLOWED && $row['title'] === 'added')))->toHaveCount(1)
        ->and(array_values(array_filter($rows, fn(array $row): bool => (int) $row['host_id'] === 7 && (int) $row['parent'] === 10)))->toHaveCount(1);

    call(fn() => api_tree_delete_node(ALLOWED, 'tbranch:11'));
    expect(array_column(items(), null, 'id'))->not->toHaveKey(11)
        ->and(count(items()))->toBe(6);
});

test('graphs are placed only on a tree the user may access', function () {
    expect(place_graphs(FORBIDDEN, 20))->toBe(array())
        ->and(implode("\n", $GLOBALS['tree_node_access_log']))->toContain('not permitted')
        ->and(place_graphs(ALLOWED, 10))->toBe(array(array(ALLOWED, 10, 100), array(ALLOWED, 10, 101)));
});

test('the graph action menu offers only trees the user may access', function () {
    $GLOBALS['graph_actions'] = array();
    add_tree_names_to_actions_array();

    expect($GLOBALS['graph_actions'])->toBe(array('tr_1' => 'Place on a Tree (allowed)'));
});
