<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Legacy;

/** Keep the legacy device-save bridge in the same locking protocol as site deletion. */
final class LegacyDeviceSiteWriter
{
    /**
     * Saves a device row after locking its site, so a concurrent site deletion cannot leave the device pointing at a missing site.
     * Runs in its own transaction unless the connection already has one. When the row is saved as disabled, the device is also disabled through the legacy API first.
     *
     * @param array $fields The device columns to save.
     * @return int|false The device id, or false when the save failed and was rolled back.
     */
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
                if (!\api_device_disable_devices([(int) $fields['id']])) {
                    throw new \RuntimeException('Device disable failed.');
                }
            }
            if (!$db->inTransaction()) {
                throw new \RuntimeException('Device transaction was lost.');
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

    /**
     * Locks the site row until the surrounding transaction ends. Site 0 (no site) needs no lock.
     *
     * @throws \LogicException When no transaction is active.
     * @throws \RuntimeException When the site id is invalid or the site no longer exists.
     */
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
