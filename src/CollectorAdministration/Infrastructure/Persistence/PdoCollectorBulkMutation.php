<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\CollectorAdministration\Infrastructure\Persistence;

use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\CollectorAdministration\Domain\CollectorSelection;

final class PdoCollectorBulkMutation
{
    /** @return array{successful:list<int>,failed:list<int>} */
    public function apply(\PDO $connection, int $actorId, CollectorBulkAction $action, CollectorSelection $selection, callable $authorize): array
    {
        if ($action === CollectorBulkAction::FullSync) {
            throw new \InvalidArgumentException('Full synchronization requires the isolated legacy worker.');
        }
        if ($action->protectsPrimary() && in_array(1, $selection->ids, true)) {
            throw new \InvalidArgumentException('The primary data collector cannot be changed by this action.');
        }
        if ($actorId < 1) {
            throw new \InvalidArgumentException('Invalid operator.');
        }

        $ids = $selection->ids;
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if (!$connection->beginTransaction()) {
            throw new \RuntimeException('Collector transaction unavailable.');
        }
        try {
            $authorize($connection, $actorId);
            $lock = $connection->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $exists = $connection->prepare("SELECT id FROM poller WHERE id IN ($placeholders)$lock");
            $exists->execute($ids);
            $found = array_map('intval', $exists->fetchAll(\PDO::FETCH_COLUMN));
            sort($found, SORT_NUMERIC);
            if ($found !== $ids) {
                throw new \OutOfBoundsException('One or more selected data collectors no longer exist.');
            }

            if ($action === CollectorBulkAction::Delete) {
                $processRows = $connection->prepare("SELECT 1 FROM automation_processes WHERE poller_id IN ($placeholders)");
                $processRows->execute($ids);
                if ($processRows->fetchColumn() !== false) {
                    throw new \RuntimeException('A selected data collector still owns tracked automation processes.');
                }
            }

            $statements = match ($action) {
                CollectorBulkAction::Delete => [
                    ['UPDATE host SET poller_id = 1 WHERE deleted = ? AND poller_id IN (' . $placeholders . ')', array_merge([''], $ids)],
                    ['UPDATE automation_networks SET poller_id = 1 WHERE poller_id IN (' . $placeholders . ')', $ids],
                    ['UPDATE poller_command SET poller_id = 1 WHERE poller_id IN (' . $placeholders . ')', $ids],
                    ['UPDATE poller_item SET poller_id = 1 WHERE poller_id IN (' . $placeholders . ')', $ids],
                    ['UPDATE poller_output_realtime SET poller_id = 1 WHERE poller_id IN (' . $placeholders . ')', $ids],
                    ['UPDATE poller_time SET poller_id = 1 WHERE poller_id IN (' . $placeholders . ')', $ids],
                    ['DELETE FROM poller WHERE id IN (' . $placeholders . ')', $ids],
                ],
                CollectorBulkAction::Disable => [['UPDATE poller SET disabled = ? WHERE id IN (' . $placeholders . ')', array_merge(['on'], $ids)]],
                CollectorBulkAction::Enable => [['UPDATE poller SET disabled = ? WHERE id IN (' . $placeholders . ')', array_merge([''], $ids)]],
                CollectorBulkAction::ClearStatistics => [['UPDATE poller SET total_time = 0, max_time = 0, min_time = 9999999, avg_time = 0, total_polls = 0 WHERE id IN (' . $placeholders . ')', $ids]],
                default => throw new \InvalidArgumentException('Unsupported collector operation.'),
            };
            foreach ($statements as [$sql, $parameters]) {
                $statement = $connection->prepare($sql);
                if (!$statement->execute($parameters)) {
                    throw new \RuntimeException('Collector operation failed.');
                }
            }
            if (!$connection->commit()) {
                throw new \RuntimeException('Collector operation commit failed.');
            }
            return ['successful' => $ids, 'failed' => []];
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $error;
        }
    }
}
