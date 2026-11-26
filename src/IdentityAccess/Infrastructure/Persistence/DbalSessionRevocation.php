<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Kadupul\IdentityAccess\Application\Port\SessionCredentialRevoker;
use Kadupul\IdentityAccess\Domain\CredentialGeneration;

/**
 * Doctrine DBAL implementation of atomic session revocation (Logout Everywhere),
 * preserving the transactional InnoDB, FOR UPDATE lock, generation check,
 * readback confirmation, and cache/session deletion invariants of NativeSessionRevocation.
 */
final readonly class DbalSessionRevocation implements SessionCredentialRevoker
{
    public function __construct(
        private Connection $database,
    ) {}

    #[\Override]
    public function revokeAll(
        int $userId,
        CredentialGeneration $expectedCurrentGeneration,
        CredentialGeneration $nextGeneration,
    ): bool {
        if ($userId < 1 || $this->database->isTransactionActive()) {
            return false;
        }

        $platform = $this->database->getDatabasePlatform();
        $isMySql = $platform instanceof AbstractMySQLPlatform;
        if (!$isMySql && !$platform instanceof SQLitePlatform) {
            return false;
        }

        $this->database->beginTransaction();

        try {
            if ($isMySql) {
                $this->assertInnoDbTables(['user_auth', 'settings_user', 'user_auth_cache', 'sessions']);
            }

            $lock = $isMySql ? ' FOR UPDATE' : '';
            $password = $this->database->fetchOne('SELECT password FROM user_auth WHERE id = ?' . $lock, [$userId]);
            if (!is_string($password)) {
                throw new \RuntimeException('Session revocation account read failed.');
            }

            $liveGeneration = DbalSessionCredentialGeneration::forAccount($this->database, $userId, $password);
            if (!$expectedCurrentGeneration->matches($liveGeneration)) {
                throw new \RuntimeException('Session revocation credential is no longer current.');
            }

            $mapping = $nextGeneration->mappingFor($password);
            $this->database->executeStatement(
                "REPLACE INTO settings_user (user_id, name, value) VALUES (?, 'auth_credential_generation', ?)",
                [$userId, $mapping],
            );

            // Confirm readback before deleting sessions and committing.
            $confirmed = DbalSessionCredentialGeneration::forAccount($this->database, $userId, $password);
            if (!$nextGeneration->matches($confirmed)) {
                throw new \RuntimeException('Session revocation generation was not preserved.');
            }

            $this->database->executeStatement('DELETE FROM user_auth_cache WHERE user_id = ?', [$userId]);
            $this->database->executeStatement('DELETE FROM sessions WHERE user_id = ?', [$userId]);

            $this->database->commit();

            return true;
        } catch (\Throwable) {
            if ($this->database->isTransactionActive()) {
                try {
                    $this->database->rollBack();
                } catch (\Throwable) {
                    // Preserve revocation failure even if rollback fails.
                }
            }

            return false;
        }
    }

    /** @param list<string> $tables */
    private function assertInnoDbTables(array $tables): void
    {
        foreach ($tables as $table) {
            $this->database->executeQuery('SELECT * FROM `' . $table . '` LIMIT 0')->free();
            $row = $this->database->fetchNumeric('SHOW CREATE TABLE `' . $table . '`');
            $ddl = is_array($row) && isset($row[1]) && is_string($row[1]) ? $row[1] : null;
            if (
                $ddl === null
                || stripos($ddl, 'CREATE TEMPORARY TABLE') !== false
                || preg_match('/\bENGINE=InnoDB\b/i', $ddl) !== 1
            ) {
                throw new \RuntimeException('Session revocation requires persistent InnoDB tables.');
            }
        }
    }
}
