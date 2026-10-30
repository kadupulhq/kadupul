<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\IdentityAccess\Application\Command\UserManagementDenied;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AdministrativeActorPolicy;
use PDO;

/** Rechecks an authenticated worker's actor; native session bootstrap remains required. */
final readonly class NativeAdministrativeActorPolicy implements AdministrativeActorPolicy
{
    private const array TABLES = ['user_auth', 'user_auth_realm', 'user_auth_group', 'user_auth_group_members',
        'user_auth_group_realm', 'settings', 'settings_user'];

    public function __construct(private PDO $database, private int $requiredRealm)
    {
        if ($requiredRealm < 1) {
            throw new \InvalidArgumentException('Invalid administrative realm.');
        }
    }

    public function authorize(int $actorId, #[\SensitiveParameter] string $credential): Actor
    {
        if ($actorId < 1 || preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1) {
            throw new UserManagementDenied();
        }
        if (!$this->database->inTransaction()) {
            throw new \LogicException('Administrative mutation authorization requires its active transaction.');
        }
        if ($this->database->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('Administrative mutation authorization requires MySQL or MariaDB.');
        }
        require_once dirname(__DIR__, 4) . '/lib/auth.php';
        $this->assertParticipants();
        $users = $this->rows('SELECT username, password, enabled, locked,
            must_change_password, password_change FROM user_auth WHERE id = ? FOR UPDATE', [$actorId]);
        if (count($users) !== 1) {
            throw new UserManagementDenied($actorId);
        }
        $user = $users[0];
        if (!is_string($user['username'] ?? null) || !is_string($user['password'] ?? null)
            || $user['enabled'] !== 'on' || $user['locked'] === 'on') {
            throw new UserManagementDenied($actorId);
        }
        $auth = $this->rows("SELECT value FROM settings WHERE name = 'auth_method' LOCK IN SHARE MODE", []);
        $method = $auth[0]['value'] ?? '1';
        if (!in_array($method, ['1', '2', '3', '4', 1, 2, 3, 4], true)
            || ($user['must_change_password'] === 'on' && ($method === '1' || $method === 1) && $user['password_change'] === 'on')) {
            throw new UserManagementDenied($actorId);
        }
        $guests = $this->rows("SELECT value FROM settings WHERE name = 'guest_user' LOCK IN SHARE MODE", []);
        if ($guests !== []) {
            $guest = $guests[0]['value'];
            if ((!is_string($guest) && !is_int($guest)) || $actorId === (int) $guest || $user['username'] === $guest) {
                throw new UserManagementDenied($actorId);
            }
        }
        $generation = \auth_session_credential_generation($actorId, $user['password'], $this->database);
        if (!hash_equals($generation, $credential)) {
            throw new UserManagementDenied($actorId);
        }
        foreach (array_unique([8, $this->requiredRealm]) as $realm) {
            $direct = $this->rows('SELECT realm_id FROM user_auth_realm
                WHERE user_id = ? AND realm_id = ? LOCK IN SHARE MODE', [$actorId, $realm]);
            if ($direct !== []) {
                continue;
            }
            $groups = $this->rows("SELECT r.realm_id FROM user_auth_group_realm r
                INNER JOIN user_auth_group_members m ON m.group_id = r.group_id
                INNER JOIN user_auth_group g ON g.id = r.group_id AND g.enabled = 'on'
                WHERE m.user_id = ? AND r.realm_id = ? LIMIT 1 LOCK IN SHARE MODE", [$actorId, $realm]);
            if ($groups === []) {
                throw new UserManagementDenied($actorId);
            }
        }
        return new Actor($actorId, $user['username']);
    }

    /** @param list<int|string> $parameters
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $parameters): array
    {
        $query = BrowserAuthenticationSql::execute($this->database, $sql, $parameters);
        $rows = $query->fetchAll(PDO::FETCH_ASSOC);
        if ($query->errorCode() !== '00000' || !array_is_list($rows)) {
            throw new \RuntimeException('Administrative policy read could not be confirmed.');
        }
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Administrative policy row is invalid.');
            }
            $validated = [];
            foreach ($row as $key => $value) {
                if (!is_string($key)) {
                    throw new \RuntimeException('Administrative policy column is invalid.');
                }
                $validated[$key] = $value;
            }
            $result[] = $validated;
        }
        if (!$query->closeCursor()) {
            throw new \RuntimeException('Administrative policy read could not be confirmed.');
        }
        return $result;
    }

    private function assertParticipants(): void
    {
        // Retain metadata locks on the actual session tables before checking their engines.
        $tables = implode(', ', array_map(static fn(string $table): string => '`' . $table . '`', self::TABLES));
        $this->rows('SELECT * FROM ' . $tables . ' LIMIT 0', []);
        $placeholders = implode(',', array_fill(0, count(self::TABLES), '?'));
        // Alias every metadata column: MySQL preserves physical ENGINE casing.
        $metadata = $this->rows('SELECT table_name AS name, engine AS engine FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name IN (' . $placeholders . ')', self::TABLES);
        $engines = [];
        foreach ($metadata as $table) {
            if (!is_string($table['name'] ?? null) || !is_string($table['engine'] ?? null)) {
                throw new \RuntimeException('Administrative policy table metadata is unavailable.');
            }
            $engines[$table['name']] = $table['engine'];
        }
        foreach (self::TABLES as $table) {
            $query = $this->database->query('SHOW CREATE TABLE `' . $table . '`');
            if ($query === false) {
                throw new \RuntimeException('Administrative policy table definition is unavailable.');
            }
            $definition = $query->fetch(PDO::FETCH_NUM);
            if (!is_array($definition) || !is_string($definition[1] ?? null) || $query->errorCode() !== '00000'
                || preg_match('/\ACREATE TABLE\s/i', $definition[1]) !== 1 || ($engines[$table] ?? null) !== 'InnoDB') {
                throw new \RuntimeException('Administrative policy requires persistent InnoDB participants.');
            }
            if (!$query->closeCursor()) {
                throw new \RuntimeException('Administrative policy metadata read could not be confirmed.');
            }
        }
    }
}
