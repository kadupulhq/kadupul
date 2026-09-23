<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

use Kadupul\Inventory\Infrastructure\Persistence\DeviceVisibilityRules;
use Kadupul\Platform\Contract\DatabaseConnection;

final readonly class LegacyDeviceVisibility
{
    public function __construct(private DatabaseConnection $database) {}
    public function predicate(int $userId, bool $lock = false): string
    {
        $db = $this->database->get();
        if ($lock && !$db->inTransaction()) {
            throw new \LogicException('Visibility locks require a transaction.');
        }
        $suffix = $lock ? ' LOCK IN SHARE MODE' : '';
        $mode = DeviceVisibilityRules::mode($db->query(DeviceVisibilityRules::MODE . $suffix)->fetchColumn());
        if (!$lock) {
            $query = $db->prepare(DeviceVisibilityRules::POLICIES);
            $query->execute([$userId, $userId]);

            return DeviceVisibilityRules::predicate($mode, $query->fetchAll());
        }
        // UNION does not reliably lock source rows; writes need current locks.
        $query = $db->prepare(DeviceVisibilityRules::USER_POLICY . $suffix);
        $query->execute([$userId]);
        $policies = $query->fetchAll();
        $query = $db->prepare(DeviceVisibilityRules::GROUP_POLICY . $suffix);
        $query->execute([$userId]);
        $policies = array_merge($policies, $query->fetchAll());

        // Materialize current locked exceptions instead of evaluating a
        // non-locking subquery against an earlier MVCC snapshot.
        return DeviceVisibilityRules::predicate($mode, $policies, static fn(string $exceptions): string => implode(',', array_map(intval(...), $db->query($exceptions . $suffix)->fetchAll(\PDO::FETCH_COLUMN))));
    }
}
