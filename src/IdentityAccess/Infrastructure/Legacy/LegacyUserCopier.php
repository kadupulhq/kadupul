<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Port\UserCopier;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

/**
 * Looks up local accounts with a direct query and runs the copy in the legacy worker script, which owns the copy logic.
 */
final readonly class LegacyUserCopier implements UserCopier
{
    public const string MARKER = 'KADUPUL_USER_COPY_RESULT';

    public function __construct(
        private Connection $localConnection,
        private Connection $mainConnection,
        private LegacyWorkerRunner $worker,
    ) {}

    #[\Override]
    public function localUserExists(OperatorDatabase $database, string $username): bool
    {
        $connection = $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;

        return $connection->fetchOne('SELECT 1 FROM user_auth WHERE username = ? AND realm = 0', [$username]) !== false;
    }

    #[\Override]
    public function copy(OperatorDatabase $database, string $template, string $new): bool
    {
        // No timeout: stopping the copy half way would leave a partial account behind.
        $run = $this->worker->run('legacy-user-copy.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'template' => $template,
            'new' => $new,
        ], null);
        if (!$run['ok']) {
            throw new \RuntimeException('User copy outcome is unknown.');
        }

        return ($run['result']['copied'] ?? false) === true;
    }
}
