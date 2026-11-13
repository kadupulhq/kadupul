<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Kadupul\Platform\Contract\DatabaseConnection;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/** The native credential-generation transaction shared by both entry paths. */
final readonly class NativeSessionRevocation
{
    public function __construct(private DatabaseConnection $database) {}

    /** Revoke persisted credentials; the caller owns current-session teardown. */
    public function revoke(int $userId, string $credential): bool
    {
        require_once dirname(__DIR__, 4) . '/lib/auth.php';

        return self::onConnection($this->database->get(), $userId, $credential);
    }

    /**
     * Compatibility boundary for lib/auth.php, which already loaded the helpers.
     * Keep legacy ID binding semantics; casting before querying changes identity.
     */
    public static function onConnection(PDO $db, mixed $userId, mixed $credential): bool
    {
        if (!is_scalar($userId) || !is_string($credential) || preg_match('/\A[a-f0-9]{64}\z/D', $credential) !== 1 || (int) $userId <= 0) {
            return false;
        }
        if ($db->inTransaction()) {
            return false;
        }
        try {
            if (!$db->beginTransaction()) {
                return false;
            }
            $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
            if (!$mysql && $db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
                throw new RuntimeException('Unsupported session revocation database.');
            }
            if ($mysql) {
                foreach (['user_auth', 'settings_user', 'user_auth_cache', 'sessions'] as $table) {
                    $query = $db->query('SELECT * FROM `' . $table . '` LIMIT 0');
                    if ($query === false || $query->errorCode() !== '00000') {
                        throw new RuntimeException('Session revocation table could not be inspected.');
                    }
                    $query->closeCursor();
                    $query = $db->query('SHOW CREATE TABLE `' . $table . '`');
                    $row = $query === false ? false : $query->fetch(PDO::FETCH_NUM);
                    if (!is_array($row) || $query->errorCode() !== '00000' || !isset($row[1]) || !is_string($row[1])
                        || stripos($row[1], 'CREATE TEMPORARY TABLE') !== false || preg_match('/\bENGINE=InnoDB\b/i', $row[1]) !== 1) {
                        throw new RuntimeException('Session revocation requires persistent InnoDB tables.');
                    }
                }
            }
            $query = $db->prepare('SELECT password FROM user_auth WHERE id = ?' . ($mysql ? ' FOR UPDATE' : ''));
            if ($query === false || !$query->execute([$userId]) || $query->errorCode() !== '00000') {
                throw new RuntimeException('Session revocation account read failed.');
            }
            $password = self::password($query);
            if (!hash_equals(self::generation($db, $userId, $password), $credential)) {
                throw new RuntimeException('Session revocation credential is no longer current.');
            }
            $mapping = self::credentialKey($password) . ':' . bin2hex(random_bytes(32));
            $query = $db->prepare("REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'auth_credential_generation', ?)");
            if ($query === false || !$query->execute([$userId, $mapping]) || $query->errorCode() !== '00000') {
                throw new RuntimeException('Session revocation generation write failed.');
            }
            // Confirm the stored value before reporting a successful revocation.
            if (self::generation($db, $userId, $password) !== substr($mapping, 65)) {
                throw new RuntimeException('Session revocation generation was not preserved.');
            }
            foreach (['user_auth_cache', 'sessions'] as $table) {
                $query = $db->prepare('DELETE FROM ' . $table . ' WHERE user_id = ?');
                if ($query === false || !$query->execute([$userId]) || $query->errorCode() !== '00000') {
                    throw new RuntimeException('Session revocation deletion failed.');
                }
            }
            if (!$db->commit()) {
                throw new RuntimeException('Session revocation commit failed.');
            }
            return true;
        } catch (Throwable $error) {
            self::rollbackOwned($db);
            return false;
        }
    }

    private static function password(PDOStatement $query): string
    {
        $password = $query->fetchColumn();
        // Fetch can fail after execute reported success; confirm its own state.
        if (!is_string($password) || $query->errorCode() !== '00000') {
            throw new RuntimeException('Session revocation account read failed.');
        }
        return $password;
    }

    private static function generation(PDO $db, mixed $userId, string $password): string
    {
        if (!function_exists('auth_session_credential_generation')) {
            throw new RuntimeException('Native credential helper is unavailable.');
        }
        $generation = \auth_session_credential_generation($userId, $password, $db);
        return $generation;
    }

    private static function credentialKey(string $password): string
    {
        if (!function_exists('auth_session_credential_key')) {
            throw new RuntimeException('Native credential helper is unavailable.');
        }
        $key = \auth_session_credential_key($password);
        if (!is_string($key)) {
            throw new RuntimeException('Native credential key is invalid.');
        }
        return $key;
    }

    private static function rollbackOwned(PDO $db): void
    {
        // Recheck live state after the failed operation, including failed commit.
        if ($db->inTransaction()) {
            try {
                $db->rollBack();
            } catch (Throwable) {
                // Preserve failure even if the connection also refuses cleanup.
            }
        }
    }
}
