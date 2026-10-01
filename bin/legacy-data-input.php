<?php

/* SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later */
use Kadupul\DataInput\Domain\DataInputState;
use Kadupul\DataInput\Domain\DataInputNotFound;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('KADUPUL_REDACT_DATABASE_LOGS', true);
define('KADUPUL_THROW_DATABASE_ERRORS', true);
ob_start();
require __DIR__ . '/../include/cli_check.php';
require_once __DIR__ . '/../lib/api_data_source.php';
require_once __DIR__ . '/../lib/poller.php';
require_once __DIR__ . '/../lib/template.php';
require_once __DIR__ . '/../lib/utility.php';
$status = 'failed';
$result = [];
$command = [];
$committed = false;
$db = null;
try {
    $raw = stream_get_contents(STDIN, 65537);
    if (strlen($raw) > 65536) {
        throw new InvalidArgumentException('Invalid payload.');
    }
    $command = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($command) || !is_int($command['actor'] ?? null) || $command['actor'] < 1 || !is_int($command['id'] ?? null) || $command['id'] < 0 || !is_string($command['nonce'] ?? null) || !is_array($command['payload'] ?? null) || !in_array($command['action'] ?? null, ['list', 'find', 'selection', 'save', 'field_save', 'field_delete', 'delete', 'duplicate', 'propagate', 'whitelist', 'bulk_delete', 'bulk_duplicate'], true)) {
        throw new InvalidArgumentException('Invalid command.');
    }
    $db = $database_sessions["$database_hostname:$database_port:$database_default"] ?? null;
    if (!$db instanceof PDO || (int) ($config['poller_id'] ?? 0) !== 1) {
        throw new RuntimeException('Primary database required.');
    }
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
    dataInputWorkerAuthorize($db, $command['actor']);
    $_SESSION['sess_user_id'] = $command['actor'];
    $id = $command['id'];
    $action = $command['action'];
    $payload = $command['payload'];
    $writes = !in_array($action, ['list', 'find', 'selection'], true);
    if ($writes) {
        dataInputWorkerStorage($db);
    }
    if ($action === 'list') {
        $preferences = dataInputWorkerRead($db, "SELECT name,value FROM settings_user WHERE user_id=? AND name LIKE 'twig_data_input_%'", [$command['actor']]);
        $remembered = [];
        foreach ($preferences as $preference) {
            $remembered[substr($preference['name'], 16)] = $preference['value'];
        }
        if (($payload['clear'] ?? false) === true) {
            $remembered = [];
        }
        $default = (int) (read_config_option('num_rows_table') ?: 25);
        $defaultRows = !isset($payload['rows']) ? (!isset($remembered['rows']) || $remembered['rows'] === '-1') : $payload['rows'] === '-1';
        $values = array_replace(['filter' => '', 'rows' => $default, 'sort' => 'name', 'direction' => 'ASC'], $remembered, array_filter($payload, static fn(mixed $value): bool => $value !== null));
        $payload = $values;
        if ($defaultRows) {
            $payload['rows'] = $default;
        }
        $filter = DataInputState::text($payload['filter'] ?? '', 200);
        $rows = filter_var($payload['rows'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
        $page = filter_var($payload['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $sort = $payload['sort'] ?? 'name';
        $direction = $payload['direction'] ?? 'ASC';
        if ($rows === false || $page === false || !in_array($sort, ['name', 'id', 'type_id', 'templates', 'data_sources'], true) || !in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid list filters.');
        }
        // Preserve installed plugin restrictions with the legacy hook; all supplied
        // values are quoted by the PDO connection before trusted plugin code runs.
        $where = "WHERE di.hash NOT IN ('" . implode("','", DataInputState::SYSTEM) . "')";
        if ($filter !== '') {
            $where .= ' AND di.name LIKE ' . $db->quote('%' . $filter . '%');
        }
        $where = api_plugin_hook_function('data_input_sql_where', $where);
        foreach (['filter' => $filter, 'rows' => $defaultRows ? '-1' : (string) $rows, 'sort' => $sort, 'direction' => $direction] as $key => $value) {
            db_execute_prepared('REPLACE INTO settings_user (user_id,name,value) VALUES (?,?,?)', [$command['actor'], 'twig_data_input_' . $key, $value]);
        }
        if (!is_string($where)) {
            throw new RuntimeException('Invalid plugin restriction.');
        }
        $total = (int) $db->query('SELECT COUNT(*) FROM data_input di ' . $where)->fetchColumn();
        $data = dataInputWorkerRead($db, 'SELECT di.*, SUM(CASE WHEN dtd.local_data_id=0 THEN 1 ELSE 0 END) AS templates, SUM(CASE WHEN dtd.local_data_id>0 THEN 1 ELSE 0 END) AS data_sources FROM data_input di LEFT JOIN data_template_data dtd ON dtd.data_input_id=di.id ' . $where . ' GROUP BY di.id ORDER BY ' . $sort . ' ' . $direction . ',di.id LIMIT ' . (int) $rows . ' OFFSET ' . (($page - 1) * $rows));
        $result = ['items' => $data, 'total' => $total, 'filter' => $filter, 'rows' => $rows, 'default_rows' => $defaultRows, 'page' => $page, 'sort' => $sort, 'direction' => $direction];
    } elseif ($action === 'selection') {
        $result = dataInputWorkerSelection($db, $payload['ids'] ?? null);
    } elseif ($action === 'find') {
        $result = $id === 0 ? ['method' => [], 'fields' => [], 'revision' => '', 'whitelist' => 'disabled'] : dataInputWorkerState($db, $id);
        if ($id > 0 && $result['method']['input_string'] !== '' && isset($config['input_whitelist'])) {
            $verified = is_file($config['input_whitelist']) && is_readable($config['input_whitelist'])
                && verify_data_input_whitelist($result['method']['hash'], $result['method']['input_string']) === true;
            $result['whitelist'] = $verified === true ? 'verified' : 'requires_update';
        }
    } elseif (in_array($action, ['bulk_delete', 'bulk_duplicate'], true)) {
        $selection = $payload['selection'] ?? null;
        if (!is_array($selection) || $selection === [] || count($selection) > 100) {
            throw new InvalidArgumentException('Invalid selection.');
        }
        ksort($selection, SORT_NUMERIC);
        $states = [];
        foreach ($selection as $key => $revision) {
            if ((!is_int($key) && !ctype_digit((string) $key)) || (int) $key < 1 || !is_string($revision)) {
                throw new InvalidArgumentException('Invalid selection.');
            }
            $state = dataInputWorkerState($db, (int) $key, true);
            if (!hash_equals($state['revision'], $revision)) {
                throw new DataInputWorkerConflict();
            }
            if ($action === 'bulk_delete') {
                dataInputWorkerMethodUnused($db, (int) $key);
            }
            $states[(int) $key] = $state;
        }
        $ids = [];
        foreach ($states as $target => $state) {
            if ($action === 'bulk_delete') {
                api_data_input_remove($target);
                $ids[] = $target;
            } else {
                $title = DataInputState::text($payload['title'] ?? '<input_title> (1)', 200, true);
                DataInputState::text(str_replace('<input_title>', $state['method']['name'], $title), 200, true);
                $copy = (int) api_data_input_duplicate($target, $title);
                if ($copy < 1) {
                    throw new RuntimeException('Duplicate failed.');
                }
                $ids[] = $copy;
            }
        }
        update_replication_crc(0, 'poller_replicate_data_input_fields_crc');
        update_replication_crc(0, 'poller_replicate_data_input_crc');
        $result = ['ids' => $ids];
    } else {
        $state = $id > 0 ? dataInputWorkerState($db, $id, true) : null;
        if ($id > 0 && (!is_string($payload['revision'] ?? null) || !hash_equals($state['revision'], $payload['revision']))) {
            throw new DataInputWorkerConflict();
        }
        if ($action === 'whitelist' && ($state === null || $state['method']['input_string'] === '')) {
            throw new InvalidArgumentException('Empty input strings do not require a whitelist entry.');
        }
        if ($action === 'save') {
            $data = DataInputState::method($payload['data'] ?? []);
            if ($id === 0 && !in_array($data['type_id'], [1, 5], true)) {
                throw new InvalidArgumentException('New inputs must use a script type.');
            }
            if ($state && !in_array($data['type_id'], [1, 5, (int) $state['method']['type_id']], true)) {
                throw new InvalidArgumentException('Invalid input type change.');
            }
            if (!cacti_input_string_is_safe($data['input_string'])) {
                throw new InvalidArgumentException('Input string contains dangerous shell characters.');
            }
            if ($id === 0) {
                $data['hash'] = get_hash_data_input(0);
                $id = (int) sql_save($data, 'data_input');
            } else {
                $data['id'] = $id;
                sql_save($data, 'data_input');
            }
            if ($id < 1) {
                throw new RuntimeException('Save was not confirmed.');
            }
            db_execute_prepared("UPDATE data_input_fields SET sequence=0 WHERE data_input_id=? AND input_output='in'", [$id]);
            generate_data_input_field_sequences($data['input_string'], $id);
        } elseif ($action === 'field_save') {
            $data = DataInputState::field($payload['data'] ?? []);
            $fieldId = $payload['field'] ?? null;
            if (!is_int($fieldId) || $fieldId < 0) {
                throw new InvalidArgumentException('Invalid field.');
            }
            $existing = null;
            foreach ($state['fields'] as $field) {
                if ((int) $field['id'] === $fieldId) {
                    $existing = $field;
                }
            }
            if ($fieldId > 0 && $existing === null) {
                throw new DataInputNotFound('Field does not belong to this input.');
            }
            if ($existing && $existing['input_output'] !== $data['input_output']) {
                throw new InvalidArgumentException('Field direction cannot change.');
            }
            if (in_array((int) $state['method']['type_id'], [1, 5], true) && $data['input_output'] === 'in' && !in_array($data['data_name'], DataInputState::placeholders($state['method']['input_string']), true)) {
                throw new InvalidArgumentException('Input field must match a command placeholder.');
            }
            foreach ($state['fields'] as $field) {
                if ((int) $field['id'] !== $fieldId && $field['data_name'] === $data['data_name'] && $field['input_output'] === $data['input_output']) {
                    throw new InvalidArgumentException('Field name is already in use.');
                }
            }
            if ($existing && $existing['input_output'] === 'out' && $existing['data_name'] !== $data['data_name']) {
                dataInputWorkerFieldUnused($db, $id, $fieldId);
            }
            $data['id'] = $fieldId;
            $data['data_input_id'] = $id;
            $data['hash'] = $existing['hash'] ?? get_hash_data_input(0, 'data_input_field');
            $data['sequence'] = $existing['sequence'] ?? 0;
            if (!(int) sql_save($data, 'data_input_fields')) {
                throw new RuntimeException('Field save failed.');
            }
            db_execute_prepared("UPDATE data_input_fields SET sequence=0 WHERE data_input_id=? AND input_output='in'", [$id]);
            generate_data_input_field_sequences($state['method']['input_string'], $id);
        } elseif ($action === 'field_delete') {
            $fieldId = $payload['field'] ?? null;
            $field = null;
            foreach ($state['fields'] as $candidate) {
                if (is_int($fieldId) && (int) $candidate['id'] === $fieldId) {
                    $field = $candidate;
                }
            }
            if ($field === null) {
                throw new DataInputNotFound('Field does not belong to this input.');
            }
            if ($field['input_output'] === 'out') {
                dataInputWorkerFieldUnused($db, $id, $fieldId);
            }
            db_execute_prepared('DELETE FROM data_input_data WHERE data_input_field_id=?', [$fieldId]);
            db_execute_prepared('DELETE FROM data_input_fields WHERE id=? AND data_input_id=?', [$fieldId, $id]);
            if ($field['input_output'] === 'in') {
                generate_data_input_field_sequences($state['method']['input_string'], $id);
            }
        } elseif ($action === 'delete') {
            dataInputWorkerMethodUnused($db, $id);
            api_data_input_remove($id);
        } elseif ($action === 'duplicate') {
            $title = DataInputState::text($payload['title'] ?? '<input_title> (1)', 200, true);
            $name = str_replace('<input_title>', $state['method']['name'], $title);
            DataInputState::text($name, 200, true);
            $id = (int) api_data_input_duplicate($id, $title);
            if ($id < 1) {
                throw new RuntimeException('Duplicate was not confirmed.');
            }
        }
        update_replication_crc(0, 'poller_replicate_data_input_fields_crc');
        update_replication_crc(0, 'poller_replicate_data_input_crc');
        $result = ['id' => $id];
    }
    if (!$db->commit()) {
        throw new RuntimeException('Commit was not confirmed.');
    }
    $committed = true;
    $status = 'ok';
    // Network collectors and whitelist files are outside the primary transaction.
    // Failures leave the confirmed local edit intact and expose an explicit retry.
    if (in_array($action, ['save', 'field_save', 'field_delete', 'duplicate', 'propagate'], true)) {
        $database_last_error = '';
        push_out_data_input_method($id);
        if (db_error() !== '' || is_error_message() || array_filter($_SESSION['sess_messages'] ?? [], static fn(array $message): bool => ($message['level'] ?? 0) >= MESSAGE_LEVEL_WARN) !== []) {
            $status = 'partial';
        }
    }
    if ($action === 'bulk_duplicate') {
        foreach ($result['ids'] as $copy) {
            push_out_data_input_method($copy);
        }
        if (db_error() !== '' || is_error_message() || array_filter($_SESSION['sess_messages'] ?? [], static fn(array $message): bool => ($message['level'] ?? 0) >= MESSAGE_LEVEL_WARN) !== []) {
            $status = 'partial';
        }
    }
    if ($action === 'whitelist') {
        if (!isset($config['input_whitelist']) || !is_writable(dirname($config['input_whitelist'])) || (file_exists($config['input_whitelist']) && !is_writable($config['input_whitelist']))) {
            throw new RuntimeException('Whitelist is not writable.');
        }
        $output = [];
        $code = cacti_exec(read_config_option('path_php_binary'), ['-q', $config['base_path'] . '/cli/input_whitelist.php', '--update', '--id=' . $id], $output, false);
        if ($code !== 0 || !is_file($config['input_whitelist']) || !is_readable($config['input_whitelist']) || verify_data_input_whitelist($state['method']['hash'], $state['method']['input_string']) !== true) {
            $status = 'partial';
        } else {
            push_out_data_input_method($id);
            if (db_error() !== '' || is_error_message() || array_filter($_SESSION['sess_messages'] ?? [], static fn(array $message): bool => ($message['level'] ?? 0) >= MESSAGE_LEVEL_WARN) !== []) {
                $status = 'partial';
            }
        }
    }
} catch (DataInputWorkerDenied) {
    $status = 'denied';
} catch (DataInputWorkerConflict) {
    $status = 'conflict';
} catch (DataInputNotFound $error) {
    $status = 'not_found';
    $result = ['message' => $error->getMessage()];
} catch (InvalidArgumentException $error) {
    $status = 'invalid';
    $result = ['message' => $error->getMessage()];
} catch (Throwable $error) {
    $status = $committed ? 'partial' : 'failed';
    preg_match('/Error ([0-9]+):/', (string) ($database_last_error ?? ''), $code);
    $trace = array_map(static fn(array $frame): string => ($frame['function'] ?? '') . ':' . ($frame['line'] ?? 0), array_slice($error->getTrace(), 0, 8));
    cacti_log('Data input operation failed (' . get_class($error) . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . ', SQL code ' . ($code[1] ?? 'none') . ', calls ' . implode(',', $trace) . ').', false, 'DATAINPUT');
} finally {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo 'KADUPUL_DATA_INPUT_RESULT=' . json_encode(['actor' => $command['actor'] ?? 0, 'action' => $command['action'] ?? '', 'request_id' => $command['id'] ?? 0, 'nonce' => $command['nonce'] ?? '', 'status' => $status, 'result' => $result], JSON_THROW_ON_ERROR) . "\n";
exit(in_array($status, ['ok', 'partial'], true) ? 0 : 1);

function dataInputWorkerRead(PDO $db, string $sql, array $params = []): array
{
    $query = $db->prepare($sql);
    $query->execute($params);
    return $query->fetchAll(PDO::FETCH_ASSOC);
}
function dataInputWorkerSelection(PDO $db, mixed $ids): array
{
    if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > 100) {
        throw new InvalidArgumentException('Invalid selection.');
    }
    foreach ($ids as $id) {
        if (!is_int($id) || $id < 1 || $id > 99999999) {
            throw new InvalidArgumentException('Invalid selection.');
        }
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $methods = dataInputWorkerRead($db, "SELECT * FROM data_input WHERE id IN ($placeholders) ORDER BY id", $ids);
    if (count($methods) !== count($ids)) {
        throw new DataInputNotFound('Data input not found.');
    }
    $fields = dataInputWorkerRead($db, "SELECT * FROM data_input_fields WHERE data_input_id IN ($placeholders) ORDER BY data_input_id,id", $ids);
    $children = [];
    foreach ($fields as $field) {
        $children[(int) $field['data_input_id']][] = $field;
    }
    $selection = [];
    $names = [];
    foreach ($methods as $method) {
        if (in_array($method['hash'], DataInputState::SYSTEM, true)) {
            throw new DataInputNotFound('Data input not found.');
        }
        $id = (int) $method['id'];
        $selection[$id] = DataInputState::revision($method, $children[$id] ?? []);
        $names[] = $method['name'];
    }
    return ['selection' => $selection, 'names' => $names];
}
function dataInputWorkerState(PDO $db, int $id, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $method = dataInputWorkerRead($db, 'SELECT * FROM data_input WHERE id=?' . $suffix, [$id])[0] ?? null;
    if ($method === null || in_array($method['hash'], DataInputState::SYSTEM, true)) {
        throw new DataInputNotFound('Data input not found.');
    }
    $fields = dataInputWorkerRead($db, 'SELECT * FROM data_input_fields WHERE data_input_id=? ORDER BY id' . $suffix, [$id]);
    $counts = dataInputWorkerRead($db, 'SELECT SUM(CASE WHEN local_data_id=0 THEN 1 ELSE 0 END) AS templates,SUM(CASE WHEN local_data_id>0 THEN 1 ELSE 0 END) AS data_sources FROM data_template_data WHERE data_input_id=?', [$id])[0];
    $counts = ['templates' => (int) ($counts['templates'] ?? 0), 'data_sources' => (int) ($counts['data_sources'] ?? 0)];
    return ['method' => $method, 'fields' => $fields, 'counts' => $counts, 'revision' => DataInputState::revision($method, $fields), 'whitelist' => 'disabled'];
}
function dataInputWorkerFieldUnused(PDO $db, int $id, int $fieldId): void
{
    if (dataInputWorkerRead($db, 'SELECT id FROM data_template_rrd WHERE data_input_field_id=? FOR UPDATE', [$fieldId]) !== [] || dataInputWorkerRead($db, 'SELECT id FROM data_template_data WHERE data_input_id=? AND local_data_id>0 FOR UPDATE', [$id]) !== []) {
        throw new InvalidArgumentException('Output fields in use cannot be removed or renamed.');
    }
}
function dataInputWorkerMethodUnused(PDO $db, int $id): void
{
    if (dataInputWorkerRead($db, 'SELECT id FROM data_template_data WHERE data_input_id=? FOR UPDATE', [$id]) !== []
        || dataInputWorkerRead($db, 'SELECT r.id FROM data_template_rrd r INNER JOIN data_input_fields f ON f.id=r.data_input_field_id WHERE f.data_input_id=? FOR UPDATE', [$id]) !== []) {
        throw new InvalidArgumentException('Data inputs in use cannot be deleted.');
    }
}
function dataInputWorkerStorage(PDO $db): void
{
    foreach (['data_input', 'data_input_fields', 'data_input_data', 'data_template_data', 'data_template_rrd', 'settings', 'user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members', 'user_auth_group_realm'] as $table) {
        $rows = dataInputWorkerRead($db, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
        if (strtoupper($rows[0]['ENGINE'] ?? '') !== 'INNODB') {
            throw new RuntimeException('Transactional storage required.');
        }
    }
}

function dataInputWorkerAuthorize(PDO $connection, int $actorId): void
{
    $read = static function (string $sql, array $params = []) use ($connection): array {
        $query = $connection->prepare($sql);
        if (!$query->execute($params)) {
            throw new RuntimeException('Authorization query failed');
        }
        return $query->fetchAll(PDO::FETCH_ASSOC);
    };
    $auth = $read("SELECT value FROM settings WHERE name = 'auth_method' LOCK IN SHARE MODE");
    if (isset($auth[0]['value']) && !in_array((int) $auth[0]['value'], [1, 2, 3, 4], true)) {
        throw new DataInputWorkerDenied();
    }
    $users = $read('SELECT id, username, enabled, locked, must_change_password, password_change FROM user_auth WHERE id = ? LOCK IN SHARE MODE', [$actorId]);
    if (count($users) !== 1 || $users[0]['enabled'] !== 'on' || $users[0]['locked'] === 'on'
        || ($users[0]['must_change_password'] === 'on' && (int) ($auth[0]['value'] ?? 1) === 1 && $users[0]['password_change'] === 'on')) {
        throw new DataInputWorkerDenied();
    }
    $guest = $read("SELECT value FROM settings WHERE name = 'guest_user' LOCK IN SHARE MODE");
    if ((int) ($guest[0]['value'] ?? 0) === $actorId || (string) ($guest[0]['value'] ?? '') === (string) $users[0]['username']) {
        throw new DataInputWorkerDenied();
    }
    foreach ([8, 2] as $realm) {
        $direct = $read('SELECT realm_id FROM user_auth_realm WHERE user_id = ? AND realm_id = ? LOCK IN SHARE MODE', [$actorId, $realm]);
        if ($direct !== []) {
            continue;
        }
        $group = $read("SELECT r.realm_id FROM user_auth_group_realm r
            INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
            INNER JOIN user_auth_group g ON g.id = r.group_id AND g.enabled = 'on'
            WHERE m.user_id = ? AND r.realm_id = ? LIMIT 1 LOCK IN SHARE MODE", [$actorId, $realm]);
        if ($group === []) {
            throw new DataInputWorkerDenied();
        }
    }
}

final class DataInputWorkerDenied extends RuntimeException {}
final class DataInputWorkerConflict extends RuntimeException {}
