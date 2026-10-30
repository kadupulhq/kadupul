<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Legacy;

use Doctrine\DBAL\Connection;
use Kadupul\IdentityAccess\Application\Port\ItemPermissions;
use Kadupul\IdentityAccess\Contract\OperatorDatabase;
use Kadupul\IdentityAccess\Domain\ItemType;
use Kadupul\Platform\Contract\LegacyWorkerRunner;

final readonly class LegacyItemPermissions implements ItemPermissions
{
    public const string MARKER = 'KADUPUL_ITEM_PERMISSION_RESULT';

    public function __construct(
        private Connection $localConnection,
        private Connection $mainConnection,
        private LegacyWorkerRunner $worker,
    ) {}

    #[\Override]
    public function userExists(OperatorDatabase $database, int $userId): bool
    {
        return $this->connection($database)->fetchOne('SELECT 1 FROM user_auth WHERE id = ?', [$userId]) !== false;
    }

    #[\Override]
    public function itemExists(OperatorDatabase $database, ItemType $type, int $itemId): bool
    {
        // The table and column come from the enum, never from the operator.
        $sql = match ($type) {
            ItemType::Graph => 'SELECT 1 FROM graph_templates_graph WHERE local_graph_id = ?',
            ItemType::Tree => 'SELECT 1 FROM graph_tree WHERE id = ?',
            ItemType::Host => 'SELECT 1 FROM host WHERE id = ?',
            ItemType::GraphTemplate => 'SELECT 1 FROM graph_templates WHERE id = ?',
        };

        return $this->connection($database)->fetchOne($sql, [$itemId]) !== false;
    }

    #[\Override]
    public function grant(OperatorDatabase $database, int $userId, ItemType $type, int $itemId): bool
    {
        // No timeout: PermissionMutation owns the transaction, and stopping it half way is not safe to retry blind.
        $run = $this->worker->run('legacy-item-permission.php', self::MARKER, [
            'database' => $database === OperatorDatabase::Main ? 'main' : 'local',
            'user' => $userId,
            'type' => $type->value,
            'item' => $itemId,
        ], null);
        if (!$run['ok']) {
            throw new \RuntimeException('Permission grant outcome is unknown.');
        }

        return ($run['result']['granted'] ?? false) === true;
    }

    private function connection(OperatorDatabase $database): Connection
    {
        return $database === OperatorDatabase::Main ? $this->mainConnection : $this->localConnection;
    }
}
