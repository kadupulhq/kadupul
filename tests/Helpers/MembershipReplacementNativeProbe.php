<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Production functions are required directly; this adapter only routes their
// SQL to isolated regular tables and exposes deterministic interleaving gates.
$scenario = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
class MembershipReplacementProbePdo extends PDO
{
    public function prepare(string $query, array $options = array()): PDOStatement|false
    {
        if (!empty($GLOBALS['scenario']['fail']) && str_starts_with($query, 'REPLACE INTO user_auth_group_members')) {
            throw new RuntimeException('Injected membership persistence failure');
        }
        return parent::prepare(membership_probe_sql($query), $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query(membership_probe_sql($query)) : parent::query(membership_probe_sql($query), $fetchMode, ...$fetchModeArgs);
    }
}
class MembershipReplacementProbeStatement extends PDOStatement
{
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if (!empty($GLOBALS['scenario']['pause']) && !isset($GLOBALS['paused']) && str_contains($this->queryString, 'SELECT group_id') && str_contains($this->queryString, 'user_auth_group_members') && $this->queryString === membership_probe_sql('SELECT group_id FROM user_auth_group_members WHERE user_id = ? FOR UPDATE')) {
            $GLOBALS['paused'] = true;
            print "SNAPSHOT\n";
            flush();
            fgets(STDIN);
        }
        return $rows;
    }
}
$db = new MembershipReplacementProbePdo($scenario['dsn'], $scenario['user'] ?? null, $scenario['password'] ?? null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_STATEMENT_CLASS => array(MembershipReplacementProbeStatement::class)));
$database_hostname = 'native';
$database_port = 0;
$database_default = 'auth';
$database_sessions = array('native:0:auth' => $db);
$_SESSION = array('sess_user_id' => 42, 'sess_user_realms' => array(99));
function membership_probe_sql($sql)
{
    $sql = preg_replace('/\b(user_auth(?:_group_members|_group_realm|_group_perms|_group|_perms|_realm|_cache|_row_cache)?|settings_user|settings_tree|sessions)\b/', $GLOBALS['scenario']['prefix'] . '_$1', $sql);
    return $GLOBALS['db']->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? str_replace(' FOR UPDATE', '', $sql) : $sql;
}
function db_fetch_assoc_prepared($sql, $params = array())
{
    $query = $GLOBALS['db']->prepare(membership_probe_sql($sql));
    $query->execute($params);
    $rows = $query->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($GLOBALS['scenario']['pause']) && !isset($GLOBALS['paused']) && str_contains($sql, 'SELECT group_id') && str_contains($sql, 'user_auth_group_members') && (int) $params[0] === 42) {
        $GLOBALS['paused'] = true;
        print "SNAPSHOT\n";
        flush();
        fgets(STDIN);
    }
    return $rows;
}
function db_fetch_row_prepared($sql, $params = array())
{
    return db_fetch_assoc_prepared($sql, $params)[0] ?? array();
}
function db_fetch_cell_prepared($sql, $params = array())
{
    $query = $GLOBALS['db']->prepare(membership_probe_sql($sql));
    $query->execute($params);
    return $query->fetchColumn();
}
function db_execute_prepared($sql, $params = array())
{
    if (!empty($GLOBALS['scenario']['fail']) && str_contains($sql, 'REPLACE INTO user_auth_group_members')) {
        throw new RuntimeException('Injected membership persistence failure');
    }
    $query = $GLOBALS['db']->prepare(membership_probe_sql($sql));
    return $query->execute($params);
}
function db_begin_transaction($connection = null)
{
    return $GLOBALS['db']->beginTransaction();
}
function db_commit_transaction($connection = null)
{
    return $GLOBALS['db']->commit();
}
function db_rollback_transaction($connection = null)
{
    return $GLOBALS['db']->rollBack();
}
function sql_save($row, $table, ...$args)
{
    $query = 'UPDATE user_auth SET ' . implode(',', array_map(fn($key) => "`$key` = ?", array_keys($row))) . ' WHERE id = ?';
    $params = array_values($row);
    $params[] = $row['id'];
    db_execute_prepared($query, $params);
    return $row['id'];
}
function input_validate_input_number($value) {}
function cacti_sizeof($rows)
{
    return is_array($rows) ? count($rows) : 0;
}
function api_plugin_hook_function($name, $value)
{
    return $value;
}
function kill_session_var($name)
{
    unset($_SESSION[$name]);
}
require dirname(__DIR__, 2) . '/lib/auth.php';
$nested = !empty($scenario['nested']);
if ($nested) {
    $db->beginTransaction();
    db_execute_prepared('UPDATE user_auth SET full_name = ? WHERE id = ?', array('caller-owned', 43));
}
print 'READY:' . ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? $db->query('SELECT CONNECTION_ID()')->fetchColumn() : 0) . "\n";
flush();
try {
    if ($scenario['action'] === 'copy') {
        user_copy('template', 'alice', 0, 0, true);
    } elseif ($scenario['action'] === 'replace') {
        user_group_replace_memberships(42, 7);
    } else {
        user_group_update_membership(99, 42, true);
    }
    $status = 'COMPLETE';
} catch (Throwable $error) {
    $status = 'REFUSED';
}
$state = array('status' => $status, 'transaction' => $db->inTransaction(), 'members' => db_fetch_assoc_prepared('SELECT group_id FROM user_auth_group_members WHERE user_id = ? ORDER BY group_id', array(42)), 'caller' => db_fetch_cell_prepared('SELECT full_name FROM user_auth WHERE id = ?', array(43)), 'reset' => db_fetch_cell_prepared('SELECT reset_perms FROM user_auth WHERE id = ?', array(42)), 'session' => $_SESSION);
if ($nested) {
    $db->rollBack();
}
print json_encode($state, JSON_THROW_ON_ERROR) . "\n";
