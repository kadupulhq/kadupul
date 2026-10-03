<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * Runs shipped functions from an admin page in a child PHP process with the
 * request, database and message helpers stubbed. The scenario names the page,
 * the functions to copy from it and from lib/auth.php, the request, the
 * session, the settings, canned query answers and the call to make. The
 * result lists the SQL reads and writes, permission resets, messages and
 * headers, and is printed even when the code under test exits.
 *
 * Each entry in 'answers' is array(helper, pattern, value[, params]): the
 * first entry whose helper and pattern match the query, and whose params
 * match when given, supplies the answer. Unmatched reads return the helper's
 * empty value.
 */

require_once __DIR__ . '/PhpSource.php';

if (!function_exists('admin_action_probe_run')) {
    /**
     * @param array<string, mixed> $scenario
     *
     * @return array<string, mixed>
     */
    function admin_action_probe_run(array $scenario): array
    {
        $root = dirname(__DIR__, 2);

        $program = 'require_once ' . var_export($root . '/include/vendor/autoload.php', true) . ";\n" . <<<'PHP'
$scenario = json_decode(stream_get_contents(STDIN), true);
define('MESSAGE_LEVEL_ERROR', 3);
define('MESSAGE_LEVEL_INFO', 1);
define('POLLER_VERBOSITY_DEBUG', 5);
$_SESSION = $scenario['session'] ?? array();
$_POST = $scenario['post'] ?? $scenario['request'] ?? array();
$GLOBALS['request'] = $scenario['request'] ?? array();
$GLOBALS['config_options'] = $scenario['config'] ?? array();
$GLOBALS['answers'] = $scenario['answers'] ?? array();
$GLOBALS['executed'] = array();
$GLOBALS['reads'] = array();
$GLOBALS['resets'] = array();
$GLOBALS['messages'] = array();
$GLOBALS['logged'] = array();
$GLOBALS['sent_headers'] = array();
$GLOBALS['printed'] = '';
$GLOBALS['insert_id'] = $scenario['insert_id'] ?? 0;
$GLOBALS['permission_sql'] = $scenario['permission_sql'] ?? false;
foreach ($scenario['globals'] ?? array() as $name => $value) {
    $GLOBALS[$name] = $value;
}
ob_start(function ($buffer) { $GLOBALS['printed'] .= $buffer; return ''; });
register_shutdown_function(function () {
    ob_end_flush();
    fwrite(STDOUT, json_encode(array(
        'returned' => $GLOBALS['probe_returned'] ?? null,
        'executed' => $GLOBALS['executed'],
        'reads' => $GLOBALS['reads'],
        'resets' => $GLOBALS['resets'],
        'messages' => $GLOBALS['messages'],
        'logged' => $GLOBALS['logged'],
        'headers' => $GLOBALS['sent_headers'],
        'printed' => $GLOBALS['printed'],
        'session' => $_SESSION,
        'epochs' => $GLOBALS['permission_sql'] ? $GLOBALS['database_sessions']['probe:0:auth']->query('SELECT id,reset_perms FROM user_auth ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR) : array(),
    )));
});
function probe_normalize($sql) { return trim(preg_replace('/\s+/', ' ', $sql)); }
function probe_answer($helper, $sql, $params, $empty) {
    $GLOBALS['reads'][] = array('helper' => $helper, 'sql' => probe_normalize($sql), 'params' => array_values($params));
    foreach ($GLOBALS['answers'] as $answer) {
        if ($answer[0] !== $helper || !preg_match($answer[1], probe_normalize($sql))) {
            continue;
        }
        if (isset($answer[3]) && array_map('strval', $answer[3]) !== array_map('strval', array_values($params))) {
            continue;
        }
        return $answer[2];
    }
    return $empty;
}
function probe_header($value) { $GLOBALS['sent_headers'][] = $value; }
function isset_request_var($name) { return isset($GLOBALS['request'][$name]); }
function get_filter_request_var($name, $filter = FILTER_VALIDATE_INT, $options = array()) {
    $value = $GLOBALS['request'][$name] ?? '';
    if ($filter === FILTER_VALIDATE_INT && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
        $GLOBALS['messages'][] = 'die_html_input_error:' . $name;
        exit;
    }
    return $value;
}
function get_nfilter_request_var($name, $default = '') { return $GLOBALS['request'][$name] ?? $default; }
function get_request_var($name, $default = '') { return $GLOBALS['request'][$name] ?? $default; }
function set_request_var($name, $value) { $GLOBALS['request'][$name] = $value; }
function input_validate_input_number($value) {
    if (!is_numeric($value)) {
        $GLOBALS['messages'][] = 'die_html_input_error';
        exit;
    }
}
function form_input_validate($value, $name, $regex, $allow_nulls, $error) { return $value; }
function sanitize_unserialize_selected_items($items) {
    $items = unserialize($items, array('allowed_classes' => false));
    return is_array($items) ? $items : false;
}
function read_config_option($name, $force = false) { return $GLOBALS['config_options'][$name] ?? ''; }
function read_user_setting($name, $default = false, $force = false, $user = 0) { return $GLOBALS['user_settings'][$name] ?? $default; }
function db_fetch_cell_prepared($sql, $params = array(), $col = '', $log = true) {
    if (preg_match('/^SELECT id FROM user_auth WHERE id = \? FOR UPDATE$/', probe_normalize($sql))) { return (int) $params[0]; }
    return probe_answer('cell', $sql, $params, false);
}
function db_fetch_cell($sql, $col = '', $log = true) { return probe_answer('cell', $sql, array(), false); }
function db_fetch_row_prepared($sql, $params = array(), $log = true) { return probe_answer('row', $sql, $params, array()); }
function db_fetch_assoc_prepared($sql, $params = array(), $log = true) { return probe_answer('assoc', $sql, $params, array()); }
function db_fetch_assoc($sql, $log = true) { return probe_answer('assoc', $sql, array(), array()); }
function db_execute_prepared($sql, $params = array(), $log = true) {
    $GLOBALS['executed'][] = array('sql' => probe_normalize($sql), 'params' => array_values($params));
    if($GLOBALS['permission_sql'] && preg_match('/^(?:REPLACE INTO|DELETE FROM|UPDATE) user_auth(?:_group_members|_group_perms|_perms|_group)?\b/',probe_normalize($sql))){
        $query=$GLOBALS['database_sessions']['probe:0:auth']->prepare($sql);
        $success=$query->execute($params);$GLOBALS['permission_affected']=$query->rowCount();return $success;
    }
    return true;
}
function db_affected_rows($db){return $GLOBALS['permission_affected']??0;}
function db_execute($sql, $log = true) {
    $GLOBALS['executed'][] = array('sql' => probe_normalize($sql), 'params' => array());
    return true;
}
$database_hostname='probe';$database_port=0;$database_default='auth';$database_sessions=array('probe:0:auth'=>new PDO('sqlite::memory:'));
if($GLOBALS['permission_sql']){
    $probeDb=$database_sessions['probe:0:auth'];
    $probeDb->exec('CREATE TABLE user_auth(id INTEGER PRIMARY KEY, reset_perms INTEGER NOT NULL DEFAULT 1,policy_graphs INTEGER DEFAULT 1,policy_trees INTEGER DEFAULT 1,policy_hosts INTEGER DEFAULT 1,policy_graph_templates INTEGER DEFAULT 1)');
    $probeDb->exec('INSERT INTO user_auth(id) VALUES(42),(43)');
    $probeDb->exec("CREATE TABLE user_auth_group(id INTEGER PRIMARY KEY,enabled TEXT DEFAULT '',policy_graphs INTEGER DEFAULT 1,policy_trees INTEGER DEFAULT 1,policy_hosts INTEGER DEFAULT 1,policy_graph_templates INTEGER DEFAULT 1)");
    foreach($scenario['permission_groups']??array(5,9) as $group){$query=$probeDb->prepare('INSERT INTO user_auth_group(id) VALUES(?)');$query->execute(array($group));}
    $probeDb->exec('CREATE TABLE user_auth_group_members(group_id INTEGER,user_id INTEGER,PRIMARY KEY(group_id,user_id))');
    foreach($scenario['permission_groups']??array(5,9) as $group){$query=$probeDb->prepare('INSERT INTO user_auth_group_members VALUES(?,42),(?,43)');$query->execute(array($group,$group));}
    $probeDb->exec('CREATE TABLE user_auth_perms(user_id INTEGER,item_id INTEGER,type INTEGER,PRIMARY KEY(user_id,item_id,type))');
    $probeDb->exec('CREATE TABLE user_auth_group_perms(group_id INTEGER,item_id INTEGER,type INTEGER,PRIMARY KEY(group_id,item_id,type))');
    foreach(array(1,2,3,4) as $type){$probeDb->exec('INSERT INTO user_auth_perms VALUES(42,9,'.$type.')');foreach($scenario['permission_groups']??array(5,9) as $group){$query=$probeDb->prepare('INSERT INTO user_auth_group_perms VALUES(?,9,?)');$query->execute(array($group,$type));}}
}
function db_begin_transaction() { $GLOBALS['executed'][] = array('sql' => 'BEGIN', 'params' => array()); return $GLOBALS['database_sessions']['probe:0:auth']->beginTransaction(); }
function db_commit_transaction() { $GLOBALS['executed'][] = array('sql' => 'COMMIT', 'params' => array()); return $GLOBALS['database_sessions']['probe:0:auth']->commit(); }
function db_rollback_transaction() { $GLOBALS['executed'][] = array('sql' => 'ROLLBACK', 'params' => array()); return $GLOBALS['database_sessions']['probe:0:auth']->rollBack(); }
function db_fetch_insert_id() { return $GLOBALS['insert_id']; }
function db_qstr($value) { return "'" . addslashes($value) . "'"; }
function sql_save($save, $table) {
    $GLOBALS['executed'][] = array('sql' => 'sql_save ' . $table, 'params' => $save);
    return $save['id'] ?: $GLOBALS['insert_id'];
}
function array_rekey($array, $key, $key_value) {
    $out = array();
    foreach ($array as $row) {
        $out[$row[$key]] = is_array($key_value) ? $row : $row[$key_value];
    }
    return $out;
}
function reset_user_perms($user_id) { $GLOBALS['resets'][] = 'user:' . $user_id; }
// This wiring probe records affected IDs; native epoch fixtures verify actual persistence.
function auth_membership_reset_users($unit, $ids, $required = true) { foreach ($ids as $id) { reset_user_perms($id); } }
function auth_membership_execute($db, $sql, $parameters)
{
    if (!db_execute_prepared($sql, $parameters)) { throw new RuntimeException('Membership write could not be confirmed'); }
}
function auth_membership_rows($db, $sql, $params) {
    if (str_starts_with($sql, 'SELECT id FROM')) {
        $id = db_fetch_cell_prepared($sql . (str_ends_with($sql, ' FOR UPDATE') ? '' : ' FOR UPDATE'), $params);
        return $id === false ? array() : array(array('id' => $id));
    }
    return db_fetch_assoc_prepared($sql, $params);
}
function reset_group_perms($group_id) { $GLOBALS['resets'][] = 'group:' . $group_id; }
function kill_session_var($name) { unset($_SESSION[$name]); }
function raise_message($id, $message = '', $level = 0) { $GLOBALS['messages'][] = $id; }
function is_error_message() { return isset($_SESSION['sess_error_fields']) && cacti_sizeof($_SESSION['sess_error_fields']) > 0; }
function cacti_log($message, $output = false, $environ = 'CMDPHP', $level = '') { $GLOBALS['logged'][] = $message; }
function get_client_addr() { return '192.0.2.10'; }
function cacti_sizeof($array) { return is_array($array) ? count($array) : 0; }
function cacti_count($array) { return is_array($array) ? count($array) : 0; }
function __($text, ...$args) { return vsprintf($text, $args); }
function __esc($text, ...$args) { return htmlspecialchars(vsprintf($text, $args), ENT_QUOTES); }
function html_escape($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function api_plugin_hook_function($name, $parm = null) { return $parm; }
function api_plugin_hook($name) {}
function is_template_account($user_id) { return in_array((string) $user_id, $GLOBALS['template_accounts'] ?? array(), true); }
function compat_password_hash($password, $algo, $options = array()) { return 'hash:' . $password; }
function compat_password_verify($password, $hash) { return $hash === 'hash:' . $password; }
function auth_session_bind_credentials($user_id) {}
function cacti_auth_revoke_user_credentials($user_id) {}
PHP;

        $program .= "\n" . ($scenario['stubs'] ?? '') . "\n";

        $sources = array('lib/auth.php' => array_merge(array('auth_membership_begin', 'auth_membership_finish', 'auth_membership_lock_users', 'auth_membership_lock_groups', 'user_group_change_memberships', 'user_group_replace_memberships', 'user_group_update_membership', 'user_group_execute_child'), $scenario['auth_functions'] ?? array()));
        $sources[$scenario['page']] = array_merge($sources[$scenario['page']] ?? array(), $scenario['functions'] ?? array());

        foreach ($sources as $file => $functions) {
            $source = file_get_contents($root . '/' . $file);

            foreach ($functions as $function) {
                // header() is built in, so the copied source calls a recorder instead.
                $extracted = test_php_function_source($source, $function);
                $extracted = str_replace('__DIR__', var_export(dirname($root . '/' . $file), true), $extracted);
                $program .= "\n" . preg_replace('/\bheader\(/', 'probe_header(', $extracted) . "\n";
            }
        }

        $program .= "\n\$GLOBALS['probe_returned'] = " . $scenario['call'] . ";\n";

        $pipes = array();
        $process = proc_open(
            array(PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . (E_ALL & ~E_DEPRECATED), '-r', $program),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );

        fwrite($pipes[0], json_encode($scenario));
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        if (!is_array($decoded) || $stderr !== '') {
            throw new RuntimeException('child process failed: ' . $stdout . $stderr);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<array{sql: string, params: array<int|string, mixed>}>
     */
    function admin_action_probe_writes(array $result, string $pattern): array
    {
        return array_values(array_filter($result['executed'], function ($row) use ($pattern) {
            return preg_match($pattern, $row['sql']) === 1;
        }));
    }
}
