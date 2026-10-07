<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2004-2026 The Cacti Group
// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

namespace Kadupul\Platform\Infrastructure\Legacy;

/** Shared native legacy plugin ordering and pruning; callers own upgrade failure policy. */
final class LegacyUpgradePluginLifecycle
{
    /**
     * Retain the legacy locals visible to setup.php and includes/database.php.
     * @param list<string>|null $preorder
     * @return list<string>
     */
    public static function orderedDirectories(?array &$preorder, mixed &$p): array
    {
        global $config;

        // Upgrade plugins now
        $plugins = glob($config['base_path'] . '/plugins/*', GLOB_ONLYDIR);

        // Do syslog and thold first if found
        $preorder[] = $config['base_path'] . '/plugins/thold';
        $preorder[] = $config['base_path'] . '/plugins/syslog';

        foreach ($plugins as $p) {
            if (strpos($p, 'thold') !== false) {
                // Skip, upgrading this first
            } elseif (strpos($p, 'syslog') !== false) {
                // Skip, upgrading this second
            } else {
                $preorder[] = $p;
            }
        }

        return $preorder;
    }

    public static function prune(): void
    {
        global $config;

        // Unregister plugins that no longer exist
        // We keep legacy tables due to potential
        // issues.

        print '---------------------------------------------------------------------------------------------' . PHP_EOL;
        cacti_log('NOTE: Pruning invalid and deprecated plugins while preserving tables', true, 'UPGRADE');

        $plugins = db_fetch_assoc('SELECT directory FROM plugin_config');
        if (cacti_sizeof($plugins)) {
            foreach ($plugins as $p) {
                $pname = $p['directory'];

                if (!file_exists($config['base_path'] . '/plugins/' . $pname . '/INFO')) {
                    if (file_exists($config['base_path'] . '/plugins/' . $pname . '/setup.php')) {
                        cacti_log("NOTE: Uninstalling Plugin $pname which is not supported.  Preserving tables.", true, 'UPGRADE');

                        api_plugin_uninstall($pname, false);
                    } else {
                        cacti_log("NOTE: Uninstalling Plugin $pname which is not supported and setup.php not found.  Preserving tables.", true, 'UPGRADE');
                        db_execute_prepared('DELETE FROM plugin_config WHERE directory = ?', array($pname));
                        db_execute_prepared('DELETE FROM plugin_db_changes WHERE plugin = ?', array($pname));
                        db_execute_prepared('DELETE FROM plugin_hooks WHERE name = ?', array($pname));
                        db_execute_prepared('DELETE FROM plugin_realms WHERE plugin = ?', array($pname));
                    }
                }
            }
        }
        print '---------------------------------------------------------------------------------------------' . PHP_EOL;
    }
}
