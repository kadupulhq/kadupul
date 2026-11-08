<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Kadupul\IdentityAccess\Application\Port\LegacyPasswordAccounts;
use Kadupul\IdentityAccess\Application\Query\LegacyPasswordAccount;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\LegacyPasswordHash;

/**
 * The length filter runs in SQL so that only candidate rows are read; the
 * exact check is LegacyPasswordHash, which matches what local login accepts.
 * The stored value is compared here and then dropped.
 */
final readonly class DbalLegacyPasswordAccounts implements LegacyPasswordAccounts
{
    public function __construct(private Connection $localConnection, private Connection $mainConnection) {}

    #[\Override]
    public function withUnsaltedMd5(OperatorDatabase $database): array
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
        $rows = $connection->iterateAssociative(
            // user_log results 1-3 are the successful logins: password, token and password change.
            'SELECT ua.id, ua.username, ua.enabled, ua.password,
                (SELECT MAX(ul.time) FROM user_log AS ul WHERE ul.user_id = ua.id AND ul.result IN (1, 2, 3)) AS last_login
            FROM user_auth AS ua
            WHERE ua.realm = 0 AND LENGTH(ua.password) = ?
            ORDER BY ua.id',
            [LegacyPasswordHash::MD5_LENGTH],
            [ParameterType::INTEGER],
        );
        $accounts = [];
        foreach ($rows as $row) {
            if (!is_string($row['password']) || !LegacyPasswordHash::isUnsaltedMd5($row['password'])) {
                continue;
            }
            $id = filter_var($row['id'], FILTER_VALIDATE_INT);
            if ($id === false) {
                throw new \UnexpectedValueException('user_auth returned an id that is not an integer.');
            }
            $username = is_scalar($row['username']) ? (string) $row['username'] : '';
            $lastLogin = is_scalar($row['last_login']) ? (string) $row['last_login'] : '';
            // A zero timestamp is the column default, not a login.
            $accounts[] = new LegacyPasswordAccount($id, $username, $row['enabled'] === 'on', $lastLogin === '' || str_starts_with($lastLogin, '0000-00-00') ? null : $lastLogin);
        }

        return $accounts;
    }
}
