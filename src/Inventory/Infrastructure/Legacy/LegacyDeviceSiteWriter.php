<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Keep the legacy device-save bridge in the same locking protocol as site deletion. */
final class LegacyDeviceSiteWriter
{
    public static function save(\PDO $db, array $fields): int|false
    {
        $ownsTransaction = !$db->inTransaction();
        try {
            if ($ownsTransaction && !$db->beginTransaction()) {
                return false;
            }
            $siteId = filter_var($fields['site_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
            if ($siteId === false) {
                throw new \RuntimeException('Invalid site assignment.');
            }
            if ($siteId > 0) {
                // A locking read sees a concurrent deletion's committed result.
                // Hold this lock until the host write commits (including caller-owned transactions).
                $site = $db->prepare('SELECT id FROM sites WHERE id = ? FOR UPDATE');
                $site->execute([$siteId]);
                if ($site->fetchColumn() === false) {
                    throw new \RuntimeException('The selected site no longer exists.');
                }
            }
            $id = \sql_save($fields, 'host', 'id', true, $db);
            if (!$id || \is_error_message()) {
                throw new \RuntimeException('Device save failed.');
            }
            if ($ownsTransaction && !$db->commit()) {
                throw new \RuntimeException('Device commit could not be confirmed.');
            }
            return (int) $id;
        } catch (\Throwable) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return false;
        }
    }
}
