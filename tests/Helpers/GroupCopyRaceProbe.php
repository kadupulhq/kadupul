<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require __DIR__ . '/PhpSource.php';
$scenario = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
function copy_race_sql($sql)
{
    return preg_replace('/\b(user_auth(?:_group_members|_group_realm|_group_perms|_group)?)\b/', $GLOBALS['scenario']['prefix'] . '_$1', $sql);
}
class GroupCopyRacePdo extends PDO
{
    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        return parent::prepare(copy_race_sql($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        return $fetchMode === null ? parent::query(copy_race_sql($query)) : parent::query(copy_race_sql($query), $fetchMode, ...$args);
    }
}
class GroupCopyRaceStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($result && ($GLOBALS['scenario']['action'] ?? 'copy') === 'copy' && str_starts_with($this->queryString, 'INSERT INTO ' . $GLOBALS['scenario']['prefix'] . '_user_auth_group (')) {
            print 'COPIED:' . $GLOBALS['db']->lastInsertId() . "\n";
            flush();
            fgets(STDIN);
        }
        return $result;
    }
}
$db = new GroupCopyRacePdo($scenario['dsn'], $scenario['user'], $scenario['password'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STATEMENT_CLASS => array(GroupCopyRaceStatement::class)));
$database_hostname = 'fixture';
$database_port = 0;
$database_default = 'auth';
$database_sessions = array('fixture:0:auth' => $db);
$config = array();
$_SESSION = array();
function cacti_count($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_sizeof($value)
{
    return is_array($value) ? count($value) : 0;
}
function cacti_log(...$args) {} function cacti_debug_backtrace(...$args) {}
$root = dirname(__DIR__, 2);
require $root . '/lib/database.php';
require $root . '/lib/auth.php';
$source = file_get_contents($root . '/user_group_admin.php');
eval(test_php_function_source($source, 'user_group_copy'));
eval(test_php_function_source($source, 'user_group_remove'));
if (($scenario['action'] ?? 'copy') === 'remove') {
    print 'REMOVER:' . $db->query('SELECT CONNECTION_ID()')->fetchColumn() . "\n";
    flush();
    user_group_remove($scenario['group_id']);
    print 'REMOVED_COMPLETE';
} else {
    if (!user_group_copy(5)) {
        throw new RuntimeException('Native group copy failed');
    }
    print 'COMPLETE_COPY';
}
