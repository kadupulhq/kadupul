<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

use Kadupul\DataInput\Domain\DataInputState;
use Kadupul\DataInput\Domain\DataInputNotFound;

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
/** Whitelist confirmation is strict; legacy loose comparisons remain unchanged. */
function dataInputWorkerWhitelist(string $hash, string $command): bool
{
    global $config;
    $path = $config['input_whitelist'] ?? null;
    if (!is_string($path) || !is_file($path) || !is_readable($path)) {
        return false;
    }
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        return false;
    }
    try {
        $whitelist = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        return is_array($whitelist) && is_string($whitelist[$hash] ?? null) && $whitelist[$hash] === $command;
    } catch (JsonException) {
        return false;
    }
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
