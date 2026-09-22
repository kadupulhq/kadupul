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
            self::lockSite($db, $fields['site_id'] ?? 0);
            // The site's existence must be established before status/cache or
            // remote disable effects. Primary writes share this transaction.
            if (($fields['disabled'] ?? '') === 'on' && (int) ($fields['id'] ?? 0) > 0) {
                \api_device_disable_devices([(int) $fields['id']]);
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

    public static function lockSite(\PDO $db, mixed $siteId): void
    {
        if (!$db->inTransaction()) {
            throw new \LogicException('Site locks require an owning transaction.');
        }
        $siteId = filter_var($siteId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967295]]);
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
    }
}
