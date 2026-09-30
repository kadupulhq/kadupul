<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

use Kadupul\CollectorAdministration\Domain\CollectorSelection;

final readonly class PdoCollectorFullSynchronizer
{
    public function __construct(private PdoCollectorOperatorAuthorization $authorization) {}

    /** @return array{successful:list<int>,failed:list<int>} */
    public function run(\PDO $connection, int $actorId, CollectorSelection $selection, callable $replicate): array
    {
        $successful = [];
        $failed = [];
        $lock = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        foreach ($selection->ids as $id) {
            if (!$connection->beginTransaction()) {
                $failed[] = $id;
                continue;
            }
            try {
                $this->authorization->assertCanManage($connection, $actorId);
                $find = $connection->prepare('SELECT id, dbhost FROM poller WHERE id = ?' . $lock);
                $find->execute([$id]);
                $poller = $find->fetch(\PDO::FETCH_ASSOC);
                if (!$poller || $poller['dbhost'] === 'localhost' || $replicate($id) !== true) {
                    throw new \RuntimeException('Collector synchronization failed.');
                }
                $timestamp = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
                $update = $connection->prepare('UPDATE poller SET last_sync = ' . $timestamp . ' WHERE id = ?');
                if (!$update->execute([$id]) || $update->rowCount() !== 1 || !$connection->commit()) {
                    throw new \RuntimeException('Collector synchronization could not be confirmed.');
                }
                $successful[] = $id;
            } catch (\Throwable) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $failed[] = $id;
            }
        }

        return ['successful' => $successful, 'failed' => $failed];
    }
}
