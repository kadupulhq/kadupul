<?php

declare(strict_types=1);

/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

/*
 * graph_view.php opens a tree by id through is_tree_allowed(), while the tree
 * picker lists get_allowed_trees(). Both run here against the same tables, so
 * a direct request can never open a tree the picker hides, or the reverse.
 */

namespace TreeGroupExceptionParityTest;

require_once dirname(__DIR__, 3).'/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 4).'/lib/auth.php');
if ($source === false) { throw new \RuntimeException('Unable to read native tree authorization'); }
foreach (['auth_check_perms', 'is_tree_allowed', 'get_allowed_trees'] as $name) {
    eval('namespace '.__NAMESPACE__.';'.test_php_function_source($source, $name));
}

const USER = 5;
const TREES = [1, 2, 3];

function read_config_option($name) { return $name === 'auth_method' ? 1 : ''; }
function auth_valid_user($user_id) { return true; }
function auth_perm_cache_check_reset($user_id) {}
function get_total_row_data($user_id, $sql, $params, $type) { return 0; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }

function db_fetch_cell_prepared($sql, $params = []) {
    $statement = connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = []) {
    $statement = connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_assoc_prepared($sql, $params = []) {
    $statement = connection()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll(\PDO::FETCH_ASSOC);
}
function db_fetch_assoc($sql) { return db_fetch_assoc_prepared($sql); }

function tableDefinitions(): array {
    return [
        'graph_tree' => 'id INTEGER, name VARCHAR(20), enabled VARCHAR(2)',
        'user_auth' => 'id INTEGER, policy_trees INTEGER',
        'user_auth_perms' => 'user_id INTEGER, item_id INTEGER, type INTEGER',
        'user_auth_group' => 'id INTEGER, enabled VARCHAR(2), policy_trees INTEGER',
        'user_auth_group_members' => 'group_id INTEGER, user_id INTEGER',
        'user_auth_group_perms' => 'group_id INTEGER, item_id INTEGER, type INTEGER',
    ];
}

function connection(): \PDO {
    static $db;
    if ($db instanceof \PDO) { return $db; }
    if (!getenv('AUTH_POLICY_NATIVE_DB')) {
        $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        foreach (tableDefinitions() as $table => $columns) { $db->exec('CREATE TABLE '.$table.' ('.$columns.')'); }
        return $db;
    }
    $host = getenv('BOOST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('BOOST_DB_PORT') ?: '3306';
    $name = getenv('AUTH_POLICY_DB_NAME') ?: (getenv('BOOST_DB_NAME') ?: 'cacti_boost_contract');
    $db = new \PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", getenv('BOOST_DB_USER') ?: 'root', getenv('BOOST_DB_PASSWORD') ?: '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    // The native graph policy contract shares this schema in the same process
    // and owns two of these tables, so only the missing ones are created here.
    foreach (tableDefinitions() as $table => $columns) {
        $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $exists->execute([$table]);
        if ((int) $exists->fetchColumn() === 0) {
            $db->exec('CREATE TABLE '.$table.' ('.$columns.')');
            $GLOBALS['tree_parity_created'][] = $table;
        }
    }
    return $db;
}

/**
 * @param array{policy: int, exceptions: array<int, int>, collide: bool} $user
 * @param array<int, array{enabled: string, policy: int, exceptions: array<int, int>, collide: bool}> $groups
 */
function load(array $user, array $groups): void {
    $db = connection();
    foreach (array_keys(tableDefinitions()) as $table) { $db->exec('DELETE FROM '.$table); }
    foreach (TREES as $tree) { $db->exec("INSERT INTO graph_tree VALUES ($tree, 'tree $tree', 'on')"); }
    $db->exec('INSERT INTO user_auth VALUES ('.USER.', '.$user['policy'].')');
    foreach ($user['exceptions'] as $tree) { $db->exec('INSERT INTO user_auth_perms VALUES ('.USER.", $tree, 2)"); }
    if ($user['collide']) {
        // graph, device and template exceptions whose ids equal a tree id
        foreach ([1, 3, 4] as $type) { $db->exec('INSERT INTO user_auth_perms VALUES ('.USER.", 3, $type)"); }
    }
    foreach ($groups as $index => $group) {
        $id = 10 + $index;
        $db->exec("INSERT INTO user_auth_group VALUES ($id, '{$group['enabled']}', {$group['policy']})");
        $db->exec("INSERT INTO user_auth_group_members VALUES ($id, ".USER.')');
        foreach ($group['exceptions'] as $tree) { $db->exec("INSERT INTO user_auth_group_perms VALUES ($id, $tree, 2)"); }
        if ($group['collide']) {
            foreach ([1, 3, 4] as $type) { $db->exec("INSERT INTO user_auth_group_perms VALUES ($id, 3, $type)"); }
        }
    }
    // a deny group the user is not in, listing every tree, must never grant
    $db->exec("INSERT INTO user_auth_group VALUES (99, 'on', 2)");
    foreach (TREES as $tree) { $db->exec("INSERT INTO user_auth_group_perms VALUES (99, $tree, 2)"); }
    $db->exec('INSERT INTO user_auth_group_members VALUES (99, 6)');
}

/** @return array{direct: array<int, bool>, listed: array<int, bool>} */
function answers(array $user, array $groups): array {
    load($user, $groups);
    $_SESSION = [];
    $rows = 0;
    $listed = array_map('intval', array_column(get_allowed_trees(false, false, '', 'name', '', $rows, USER), 'id'));
    $direct = [];
    $inList = [];
    foreach (TREES as $tree) {
        $_SESSION = [];
        $direct[$tree] = is_tree_allowed($tree, USER);
        $inList[$tree] = in_array($tree, $listed, true);
    }
    return ['direct' => $direct, 'listed' => $inList];
}

/**
 * A tree is visible when the user or any enabled group the user belongs to
 * grants it: an Allow policy grants every tree it has no type 2 exception
 * for, and a Deny policy grants only the trees it has a type 2 exception for.
 */
function expected(array $user, array $groups, int $tree): bool {
    $owners = [$user];
    foreach ($groups as $group) {
        if ($group['enabled'] === 'on') { $owners[] = $group; }
    }
    foreach ($owners as $owner) {
        if (in_array($tree, $owner['exceptions'], true) === ($owner['policy'] === 2)) { return true; }
    }
    return false;
}

function matrix(): array {
    $users = [];
    foreach ([1, 2] as $policy) {
        foreach ([[], [1]] as $exceptions) {
            foreach ([false, true] as $collide) {
                $users[] = ['policy' => $policy, 'exceptions' => $exceptions, 'collide' => $collide];
            }
        }
    }
    $variants = [];
    foreach (['on', ''] as $enabled) {
        foreach ([1, 2] as $policy) {
            foreach ([[], [2], [1, 2]] as $exceptions) {
                foreach ([false, true] as $collide) {
                    $variants[] = ['enabled' => $enabled, 'policy' => $policy, 'exceptions' => $exceptions, 'collide' => $collide];
                }
            }
        }
    }
    $memberships = [[]];
    foreach ($variants as $first) {
        $memberships[] = [$first];
        foreach ($variants as $second) { $memberships[] = [$first, $second]; }
    }
    $cases = [];
    foreach ($users as $user) {
        foreach ($memberships as $groups) { $cases[] = [$user, $groups]; }
    }
    return $cases;
}

afterAll(function () {
    foreach (array_reverse($GLOBALS['tree_parity_created'] ?? []) as $table) { connection()->exec('DROP TABLE `'.$table.'`'); }
});

test('the matrix covers every policy, exception, membership and collision combination', function () {
    // 8 user variants x (1 + 24 + 24 * 24) group memberships
    expect(count(matrix()))->toBe(8 * (1 + 24 + 24 * 24));
});

test('a direct tree request agrees with the tree picker for every combination', function () {
    $mismatches = [];
    foreach (matrix() as [$user, $groups]) {
        $result = answers($user, $groups);
        foreach (TREES as $tree) {
            $want = expected($user, $groups, $tree);
            if ($result['direct'][$tree] !== $result['listed'][$tree] || $result['listed'][$tree] !== $want) {
                $mismatches[] = json_encode(['tree' => $tree, 'user' => $user, 'groups' => $groups, 'direct' => $result['direct'][$tree], 'listed' => $result['listed'][$tree], 'expected' => $want]);
            }
        }
    }
    expect(array_slice($mismatches, 0, 5))->toBe([])->and(count($mismatches))->toBe(0);
});

test('a group Allow exception hides the tree from a user with no exceptions of their own', function () {
    $user = ['policy' => 2, 'exceptions' => [], 'collide' => false];
    $groups = [['enabled' => 'on', 'policy' => 1, 'exceptions' => [2], 'collide' => false]];
    expect(answers($user, $groups))->toBe([
        'direct' => [1 => true, 2 => false, 3 => true],
        'listed' => [1 => true, 2 => false, 3 => true],
    ]);
});

test('an exception of another permission type does not open a tree under a group Deny policy', function () {
    $user = ['policy' => 2, 'exceptions' => [], 'collide' => false];
    $groups = [['enabled' => 'on', 'policy' => 2, 'exceptions' => [], 'collide' => true]];
    expect(answers($user, $groups)['direct'])->toBe([1 => false, 2 => false, 3 => false]);
});

test('each group policy reads only that group exceptions', function () {
    $user = ['policy' => 2, 'exceptions' => [], 'collide' => false];
    $groups = [
        ['enabled' => 'on', 'policy' => 1, 'exceptions' => [1, 2], 'collide' => false],
        ['enabled' => 'on', 'policy' => 2, 'exceptions' => [2], 'collide' => false],
    ];
    expect(answers($user, $groups)['direct'])->toBe([1 => false, 2 => true, 3 => true]);
});

test('a disabled group grants nothing', function () {
    $user = ['policy' => 2, 'exceptions' => [], 'collide' => false];
    $groups = [['enabled' => '', 'policy' => 1, 'exceptions' => [], 'collide' => false]];
    expect(answers($user, $groups)['direct'])->toBe([1 => false, 2 => false, 3 => false]);
});
