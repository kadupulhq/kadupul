<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests\Security;

use PHPUnit\Framework\TestCase;

final class TreeOwnershipHardeningTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $entry) {
                if ($entry->isDir() && !$entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }

            @rmdir($dir);
        }

        $this->tempDirs = [];
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700, true);
        $this->tempDirs[] = $dir;

        return $dir;
    }

    /**
     * @param list<string> $args
     * @return array{status: int, stdout: string, stderr: string}
     */
    private function runPhp(string $code, array $args = []): array
    {
        $process = proc_open(
            array_merge([PHP_BINARY, '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-r', $code, $this->root], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        $this->assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'status' => proc_close($process),
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    public function testS501GetAllowedTreeLevelEnforcesTreePermission(): void
    {
        $code = <<<'PHP'
require $argv[1] . '/include/global_constants.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('RAND', static fn(): float => 0.5, 0);
$db->sqliteCreateFunction('FLOOR', static fn($v): int => (int) floor((float) $v), 1);
$db->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, policy_trees INTEGER, policy_hosts INTEGER, policy_graphs INTEGER, policy_graph_templates INTEGER, reset_perms INTEGER NOT NULL DEFAULT 0)");
$db->exec("CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER)");
$db->exec("CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT, policy_trees INTEGER)");
$db->exec("CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)");
$db->exec("CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER)");
$db->exec("CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, name TEXT, enabled TEXT)");
$db->exec("CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INTEGER, parent INTEGER, position INTEGER, title TEXT, host_id INTEGER, site_id INTEGER, local_graph_id INTEGER, host_grouping_type INTEGER)");
$db->exec("CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT)");
$db->exec("CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)");

$db->exec("INSERT INTO user_auth VALUES (5, 2, 1, 1, 1, 0)");
$db->exec("INSERT INTO user_auth_perms VALUES (5, 1, 2)");
$db->exec("INSERT INTO graph_tree VALUES (1, 'Allowed Tree', 'on'), (2, 'Forbidden Tree', 'on')");
$db->exec("INSERT INTO graph_tree_items VALUES (10, 1, 0, 1, 'Allowed Branch', 0, 0, 0, 1), (20, 2, 0, 1, 'Secret Branch', 0, 0, 0, 1)");

function db_fetch_cell_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_assoc_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function read_config_option($name) { return $name === 'auth_method' ? '1' : ''; }
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function kill_session_var($n) { unset($_SESSION[$n]); }

require $argv[1] . '/lib/auth.php';

$_SESSION = ['sess_user_id' => 5];
$allowed = get_allowed_tree_level(1, 0, true, 5);
$forbidden = get_allowed_tree_level(2, 0, true, 5);

echo json_encode([
    'allowed_titles' => array_column($allowed, 'title'),
    'forbidden' => $forbidden,
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code);
        $this->assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        $this->assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['Allowed Branch'], $result['allowed_titles']);
        $this->assertSame([], $result['forbidden']);
    }

    public function testS501TreeApiEnforcesOwnershipAndServerSideEditLock(): void
    {
        $code = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/auth.php';
require $argv[1] . '/lib/api_tree.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('RAND', static fn(): float => 0.5, 0);
$db->sqliteCreateFunction('FLOOR', static fn($v): int => (int) floor((float) $v), 1);
$db->sqliteCreateFunction('NOW', static fn(): string => '2026-11-26 12:00:00');
$db->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, policy_trees INTEGER, reset_perms INTEGER NOT NULL DEFAULT 0)");
$db->exec("CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)");
$db->exec("CREATE TABLE user_auth_perms (user_id INTEGER, item_id INTEGER, type INTEGER)");
$db->exec("CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT, policy_trees INTEGER)");
$db->exec("CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)");
$db->exec("CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)");
$db->exec("CREATE TABLE user_auth_group_perms (group_id INTEGER, item_id INTEGER, type INTEGER)");
$db->exec("CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, name TEXT, enabled TEXT, user_id INTEGER, locked INTEGER, locked_date TEXT, last_modified TEXT, modified_by INTEGER, sort_type INTEGER DEFAULT 1, sequence INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY AUTOINCREMENT, graph_tree_id INTEGER, parent INTEGER, position INTEGER, title TEXT, local_graph_id INTEGER DEFAULT 0, host_id INTEGER DEFAULT 0, site_id INTEGER DEFAULT 0, host_grouping_type INTEGER DEFAULT 1, sort_children_type INTEGER DEFAULT 1)");
$db->exec("CREATE TABLE sites (id INTEGER PRIMARY KEY, name TEXT)");
$db->exec("CREATE TABLE host (id INTEGER PRIMARY KEY, description TEXT)");
$db->exec("CREATE TABLE graph_templates_graph (local_graph_id INTEGER PRIMARY KEY, title_cache TEXT)");

// User 10 owns tree 1; user 20 owns tree 2; user 1 is realm-1 admin.
// Both user 10 and user 20 have Allow tree viewing policy (policy_trees = 1).
$db->exec("INSERT INTO user_auth VALUES (1, 1, 0), (10, 1, 0), (20, 1, 0)");
$db->exec("INSERT INTO user_auth_realm VALUES (1, 1)");
$db->exec("INSERT INTO graph_tree VALUES (1, 'Owner Tree', 'on', 10, 0, '', '', 10, 1, 1), (2, 'Victim Tree', 'on', 20, 1, '2026-11-26 11:00:00', '2026-11-26 11:00:00', 20, 1, 2)");
$db->exec("INSERT INTO graph_tree_items (id, graph_tree_id, parent, position, title) VALUES (100, 1, 0, 1, 'Root1'), (200, 2, 0, 1, 'Root2')");

$GLOBALS['logs'] = [];
$GLOBALS['drawn'] = [];

function db_fetch_cell_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_fetch_assoc_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchAll(PDO::FETCH_ASSOC);
}
function db_execute_prepared($sql, $params = []) {
    return $GLOBALS['db']->prepare($sql)->execute(array_values($params));
}
function db_table_exists($table) {
    return (bool) db_fetch_cell_prepared("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
}
function sql_save($save, $table) {
    $cols = array_keys($save);
    $GLOBALS['db']->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')->execute(array_values($save));
    return (int) $GLOBALS['db']->lastInsertId();
}
function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }
function cacti_log($m, ...$rest) { $GLOBALS['logs'][] = $m; }
function read_config_option($n) { return $n === 'auth_method' ? '1' : ''; }
function set_config_option($n, $v) {}
function kill_session_var($n) { unset($_SESSION[$n]); }
function input_validate_input_number($v) { if (!is_numeric($v)) { throw new InvalidArgumentException('bad int'); } }
function __($t) { return $t; }
function array_rekey($rows, $k, $v) { return array_column($rows, $v, $k); }
function draw_dhtml_tree_level($tree_id, $parent = 0, $editing = false) {
    $GLOBALS['drawn'][] = [$tree_id, $parent, $editing];
    return ["<li>tree:$tree_id</li>"];
}

// 1) Non-owner (user 10) cannot lock, unlock, view for edit, or mutate victim tree 2 even though policy_trees=1 allows viewing.
$_SESSION = ['sess_user_id' => 10];
$lockVictim = api_tree_lock(2, 10);
$unlockVictim = api_tree_unlock(2, 10);
ob_start();
api_tree_get_node(2, '#', true);
$getNodeVictim = ob_get_clean();
ob_start();
$createVictim = api_tree_create_node(2, '#', 0, 'Injected');
api_tree_rename_node(2, 'tbranch:200', 'Pwned');
api_tree_delete_node(2, 'tbranch:200');
ob_end_clean();

// 2) Owner (user 10) cannot mutate their own UNLOCKED tree 1 until they lock it.
ob_start();
$createUnlocked = api_tree_create_node(1, '#', 0, 'ShouldFail');
api_tree_rename_node(1, 'tbranch:100', 'ShouldNotRename');
api_tree_delete_node(1, 'tbranch:100');
ob_end_clean();

// 3) Owner (user 10) locks tree 1, then mutations succeed.
api_tree_lock(1, 10);
ob_start();
api_tree_create_node(1, '#', 0, 'LegitBranch');
api_tree_rename_node(1, 'tbranch:100', 'RenamedRoot1');
api_tree_get_node(1, '#', true);
$ownerOut = ob_get_clean();

// 4) Admin (user 1) still cannot mutate tree 1 while locked by user 10, until admin unlocks and locks as self.
$_SESSION = ['sess_user_id' => 1];
ob_start();
$adminWhileOtherLocked = api_tree_create_node(1, '#', 0, 'StealLock');
ob_end_clean();
api_tree_unlock(1, 1);
api_tree_lock(1, 1);
ob_start();
api_tree_create_node(1, '#', 0, 'AdminLegit');
ob_end_clean();

echo json_encode([
    'lockVictim' => $lockVictim,
    'unlockVictim' => $unlockVictim,
    'getNodeVictim' => $getNodeVictim,
    'createVictim' => $createVictim,
    'createUnlocked' => $createUnlocked,
    'adminWhileOtherLocked' => $adminWhileOtherLocked,
    'ownerOutContainsTree1' => str_contains($ownerOut, '<li>tree:1</li>'),
    'victimLocked' => (int) db_fetch_cell_prepared('SELECT locked FROM graph_tree WHERE id = 2'),
    'victimTitles' => array_column(db_fetch_assoc_prepared('SELECT title FROM graph_tree_items WHERE graph_tree_id = 2 ORDER BY id'), 'title'),
    'ownerTitles' => array_column(db_fetch_assoc_prepared('SELECT title FROM graph_tree_items WHERE graph_tree_id = 1 ORDER BY id'), 'title'),
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code);
        $this->assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        $this->assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($result['lockVictim']);
        $this->assertFalse($result['unlockVictim']);
        $this->assertSame('', $result['getNodeVictim']);
        $this->assertFalse($result['createVictim']);
        $this->assertFalse($result['createUnlocked']);
        $this->assertFalse($result['adminWhileOtherLocked']);
        $this->assertTrue($result['ownerOutContainsTree1']);
        $this->assertSame(1, $result['victimLocked']);
        $this->assertSame(['Root2'], $result['victimTitles']);
        $this->assertSame(['RenamedRoot1', 'LegitBranch', 'AdminLegit'], $result['ownerTitles']);
    }

    public function testS501TreePageEndpointsEnforceOwnershipAndListingFilter(): void
    {
        $code = <<<'PHP'
require $argv[1] . '/tests/Helpers/PhpSource.php';
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/auth.php';

$treeSrc = file_get_contents($argv[1] . '/tree.php');
foreach (['tree_sort_name_asc', 'tree_sort_name_desc', 'tree_up', 'tree_down', 'tree_dnd', 'get_host_sort_type', 'get_branch_sort_type', 'tree_edit'] as $fn) {
    $src = test_php_function_source($treeSrc, $fn);
    $src = preg_replace('/function ' . $fn . '\(([^)]*)\):\s*never/', 'function ' . $fn . '($1)', $src);
    $src = str_replace('exit;', 'return;', $src);
    eval($src);
}

function cacti_sizeof($v) { return is_countable($v) ? count($v) : 0; }

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('RAND', static fn(): float => 0.5, 0);
$db->sqliteCreateFunction('FLOOR', static fn($v): int => (int) floor((float) $v), 1);
$db->exec("CREATE TABLE user_auth_realm (user_id INTEGER, realm_id INTEGER)");
$db->exec("CREATE TABLE user_auth_group (id INTEGER PRIMARY KEY, enabled TEXT)");
$db->exec("CREATE TABLE user_auth_group_members (group_id INTEGER, user_id INTEGER)");
$db->exec("CREATE TABLE user_auth_group_realm (group_id INTEGER, realm_id INTEGER)");
$db->exec("CREATE TABLE graph_tree (id INTEGER PRIMARY KEY, name TEXT, user_id INTEGER, sequence INTEGER, locked INTEGER DEFAULT 0, locked_date TEXT DEFAULT '', modified_by INTEGER DEFAULT 0)");
$db->exec("CREATE TABLE graph_tree_items (id INTEGER PRIMARY KEY, graph_tree_id INTEGER, host_grouping_type INTEGER, sort_children_type INTEGER)");

$db->exec("INSERT INTO user_auth_realm VALUES (1, 1)");
$db->exec("INSERT INTO graph_tree VALUES (1, 'Owner Tree', 10, 1, 0, '', 10), (2, 'Victim Tree', 20, 2, 0, '', 20)");
$db->exec("INSERT INTO graph_tree_items VALUES (10, 1, 1, 3), (20, 2, 2, 4)");

$GLOBALS['request'] = [];
$GLOBALS['messages'] = [];
$GLOBALS['headers'] = [];
$GLOBALS['rawExecs'] = [];

function db_fetch_cell_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchColumn();
}
function db_fetch_row_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetch(PDO::FETCH_ASSOC) ?: [];
}
function db_execute_prepared($sql, $params = []) {
    return $GLOBALS['db']->prepare($sql)->execute(array_values($params));
}
function db_execute($sql) {
    $GLOBALS['rawExecs'][] = $sql;
    return true;
}
function db_table_exists($t) { return true; }
function isset_request_var($n) { return array_key_exists($n, $GLOBALS['request']); }
function isempty_request_var($n) { return empty($GLOBALS['request'][$n]); }
function get_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_nfilter_request_var($n, $d = '') { return $GLOBALS['request'][$n] ?? $d; }
function get_filter_request_var($n) { return $GLOBALS['request'][$n] ?? ''; }
function load_current_session_value($n, $s, $d) {}
function raise_message($id, ...$a) { $GLOBALS['messages'][] = $id; }
function __($t, ...$a) { return $a ? vsprintf($t, $a) : $t; }
function __x($c, $t) { return $t; }
function __esc($t, ...$a) { return htmlspecialchars(__($t, ...$a), ENT_QUOTES); }
function input_validate_input_number($v) { if (!is_numeric($v)) { throw new InvalidArgumentException('bad int'); } }
function tree_check_sequences() {}
function set_config_option($n, $v) {}

// Non-admin user 10 attempts read/write operations on victim tree 2 / branch 20.
$_SESSION = ['sess_user_id' => 10];

$GLOBALS['request'] = ['nodeid' => 'tbranch:20'];
ob_start();
get_host_sort_type();
$victimHostSort = ob_get_clean();
ob_start();
get_branch_sort_type();
$victimBranchSort = ob_get_clean();

// Owner user 10 CAN read sort settings on their own branch 10.
$GLOBALS['request'] = ['nodeid' => 'tbranch:10'];
ob_start();
get_host_sort_type();
$ownerHostSort = ob_get_clean();
ob_start();
get_branch_sort_type();
$ownerBranchSort = ob_get_clean();

// Non-admin cannot run global sortasc/sortdesc, tree_up/tree_down on victim tree, or tree_dnd with victim tree.
tree_sort_name_asc();
tree_sort_name_desc();
$GLOBALS['request'] = ['id' => 2];
tree_up();
tree_down();
$GLOBALS['request'] = ['tree_ids' => ['line2', 'line1']];
tree_dnd();

// Non-admin cannot open tree_edit for victim tree 2.
$GLOBALS['request'] = ['id' => 2];
ob_start();
tree_edit(true);
$victimEditOut = ob_get_clean();

$seqsAfter = $db->query('SELECT id, sequence FROM graph_tree ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);

echo json_encode([
    'victimHostSort' => $victimHostSort,
    'victimBranchSort' => $victimBranchSort,
    'ownerHostSort' => $ownerHostSort,
    'ownerBranchSort' => $ownerBranchSort,
    'rawExecs' => $GLOBALS['rawExecs'],
    'seqsAfter' => $seqsAfter,
    'victimEditOut' => $victimEditOut,
    'treeSourceFiltersListing' => str_contains($treeSrc, "if (!is_realm_allowed(1))") && str_contains($treeSrc, "t.user_id = "),
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code);
        $this->assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        $this->assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('', $result['victimHostSort']);
        $this->assertSame('', $result['victimBranchSort']);
        $this->assertSame('hsgt', $result['ownerHostSort']);
        $this->assertSame('numeric', $result['ownerBranchSort']);
        $this->assertSame([], $result['rawExecs']);
        $this->assertSame([1 => 1, 2 => 2], $result['seqsAfter']);
        $this->assertSame('', $result['victimEditOut']);
        $this->assertTrue($result['treeSourceFiltersListing']);
    }

    public function testS1004GlobalLanguagesRejectsTraversalAndSymlinkEscapes(): void
    {
        $dir = $this->makeTempDir('s10-04-i18n');
        mkdir($dir . '/locales/LC_MESSAGES', 0700, true);
        mkdir($dir . '/plugins/good_plugin/locales/LC_MESSAGES', 0700, true);
        mkdir($dir . '/plugins/escape_mo/locales/LC_MESSAGES', 0700, true);
        mkdir($dir . '/outside/locales/LC_MESSAGES', 0700, true);
        file_put_contents($dir . '/locales/LC_MESSAGES/fr-FR.mo', 'mo-core');
        file_put_contents($dir . '/plugins/good_plugin/locales/LC_MESSAGES/fr-FR.mo', 'mo-good');
        file_put_contents($dir . '/outside/locales/LC_MESSAGES/fr-FR.mo', 'mo-outside');
        symlink($dir . '/outside/locales/LC_MESSAGES/fr-FR.mo', $dir . '/plugins/escape_mo/locales/LC_MESSAGES/fr-FR.mo');

        $code = <<<'PHP'
$root = $argv[1];
$dir = $argv[2];

require $root . '/tests/Helpers/PhpSource.php';
require $root . '/include/global_constants.php';
$fnSrc = file_get_contents($root . '/lib/functions.php');
eval(test_php_function_source($fnSrc, 'cacti_normalize_windows_path'));
eval(test_php_function_source($fnSrc, 'cacti_path_is_within'));
eval(test_php_function_source($fnSrc, 'cacti_sizeof'));

$config = [
    'base_path' => $dir,
    'include_path' => $dir . '/include',
    'i18n_language_handler' => CACTI_LANGUAGE_HANDLER_DEFAULT,
];
$_SESSION = ['sess_user_language' => 'fr-FR'];
$_SERVER = ['REQUEST_METHOD' => 'GET'];

function read_config_option($name) {
    return match ($name) {
        'i18n_language_support' => '1',
        'i18n_auto_detection' => '0',
        'i18n_default_language' => 'fr-FR',
        'i18n_language_handler' => CACTI_LANGUAGE_HANDLER_DEFAULT,
        'i18n_language_debug' => '',
        default => '',
    };
}
function read_default_config_option($name) { return 'en-US'; }
function isset_request_var($n) { return false; }
function isempty_request_var($n) { return true; }
function get_request_var($n) { return ''; }
function set_request_var($n, $v) {}
function get_guest_account() { return 0; }
function set_user_setting($n, $v) {}
function db_fetch_cell_prepared($sql, $params = []) { return false; }
function db_fetch_assoc($sql) {
    return [
        ['directory' => '../outside'],
        ['directory' => 'good_plugin/../../outside'],
        ['directory' => 'escape_mo'],
        ['directory' => 'good_plugin'],
    ];
}

require $root . '/include/global_languages.php';

echo json_encode([
    'domains' => array_keys($cacti_textdomains),
    'good_path' => $cacti_textdomains['good_plugin']['path2catalogue'] ?? null,
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code, [$dir]);
        $this->assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        $this->assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['cacti', 'good_plugin'], $result['domains']);
        $this->assertNotNull($result['good_path']);
        $this->assertStringEndsWith('/plugins/good_plugin/locales/LC_MESSAGES/fr-FR.mo', $result['good_path']);
    }

    public function testS1108UserDisableAlsoDisablesScheduledReportsOwnedByUser(): void
    {
        $code = <<<'PHP'
require $argv[1] . '/include/global_constants.php';
require $argv[1] . '/lib/auth.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->sqliteCreateFunction('RAND', static fn(): float => 0.5, 0);
$db->sqliteCreateFunction('FLOOR', static fn($v): int => (int) floor((float) $v), 1);
$db->exec("CREATE TABLE user_auth (id INTEGER PRIMARY KEY, username TEXT, password TEXT, enabled TEXT, reset_perms INTEGER NOT NULL DEFAULT 0)");
$db->exec("CREATE TABLE reports (id INTEGER PRIMARY KEY, user_id INTEGER, enabled TEXT)");
$db->exec("CREATE TABLE user_auth_cache (user_id INTEGER)");
$db->exec("CREATE TABLE user_auth_row_cache (user_id INTEGER)");
$db->exec("CREATE TABLE sessions (user_id INTEGER)");
$db->exec("CREATE TABLE settings_user (user_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (user_id, name))");

$db->exec("INSERT INTO user_auth VALUES (42, 'victim', 'hash42', 'on', 0), (99, 'other', 'hash99', 'on', 0)");
$db->exec("INSERT INTO reports VALUES (1, 42, 'on'), (2, 42, 'on'), (3, 99, 'on')");

function input_validate_input_number($v) { if (!is_numeric($v)) { throw new InvalidArgumentException('bad int'); } }
function db_table_exists($t) {
    $q = $GLOBALS['db']->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $q->execute([$t]);
    return $q->fetchColumn() !== false;
}
function db_execute_prepared($sql, $params = []) {
    return $GLOBALS['db']->prepare($sql)->execute(array_values($params));
}
function db_fetch_cell_prepared($sql, $params = []) {
    $q = $GLOBALS['db']->prepare($sql);
    $q->execute(array_values($params));
    return $q->fetchColumn();
}

user_disable(42);

echo json_encode([
    'users' => $db->query('SELECT id, enabled FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
    'reports' => $db->query('SELECT id, enabled FROM reports ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR),
], JSON_THROW_ON_ERROR);
PHP;

        $run = $this->runPhp($code);
        $this->assertSame(0, $run['status'], $run['stderr'] . $run['stdout']);
        $this->assertSame('', $run['stderr']);

        $result = json_decode($run['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([42 => '', 99 => 'on'], $result['users']);
        $this->assertSame([1 => '', 2 => '', 3 => 'on'], $result['reports']);
    }
}
