<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// The code below comes from Cacti: upgrade_database() and plugin_installed()
// from cli/audit_database.php, moved here with a legacy_audit_ prefix
// because plugin upgrades need the legacy bootstrap.
// Each shell exec call now runs through Symfony Process with an argument
// array, so a plugin's recorded version reaches its upgrade script as one
// argument.
// LegacyInstallationUpgrade is the only caller, after the operator check;
// this file checks nothing itself. It ignores stdin. Everything it prints is
// the script's output, then one KADUPUL_UPGRADE_RESULT line for the adapter.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../include/cli_check.php';

// cli/upgrade_database.php's exit code, or null when the upgrade threw.
$core = null;
try {
    $core = legacy_audit_upgrade_database();
} catch (\Throwable) {
    // The adapter reports an upgrade that did not finish; the text stays hidden.
}
// "ok" only when the core upgrade exited 0: the audit must not run against
// a schema the upgrade left half way. The plugins still ran, as before.
$completed = $core === 0;
print 'KADUPUL_UPGRADE_RESULT=' . json_encode(['status' => $completed ? 'ok' : 'failed', 'core_exit' => $core]) . PHP_EOL;
exit($completed ? 0 : 1);

/**
 * What PHP's exec gave the script: stdout as lines with trailing whitespace
 * removed, and the exit code. The child's stderr passes through, as it did
 * there. No timeout, as exec had none: an upgrade stopped half way would
 * leave the schema between two versions.
 *
 * @param list<string> $command
 * @return array{0: int, 1: list<string>}
 */
function legacy_audit_run(array $command): array
{
    global $config;

    $process = new \Symfony\Component\Process\Process($command, $config['base_path'], null, null, null);
    $process->run(static function (string $type, string $buffer): void {
        if ($type === \Symfony\Component\Process\Process::ERR) {
            fwrite(STDERR, $buffer);
        }
    });
    $stdout = $process->getOutput();
    if ($stdout === '') {
        return [$process->getExitCode() ?? 1, []];
    }
    // PHP's exec split on "\n", kept a last line with no newline, and
    // stripped what isspace() matches from the end of each line.
    $lines = explode("\n", str_ends_with($stdout, "\n") ? substr($stdout, 0, -1) : $stdout);

    return [$process->getExitCode() ?? 1, array_map(static fn(string $line): string => rtrim($line, " \t\n\r\v\f"), $lines)];
}

function legacy_audit_upgrade_database()
{
    global $config;

    $start = microtime(true);

    cacti_log('NOTE: Upgrading Kadupul, this will take a few minutes.', true, 'UPGRADE');

    [$return_var, $output] = legacy_audit_run([PHP_BINARY, $config['base_path'] . '/cli/upgrade_database.php', '--debug']);
    $core_exit = $return_var;

    $end = microtime(true);

    if ($return_var == 0) {
        cacti_log(sprintf('NOTE: Kadupul Upgrade succeeded in %.2f seconds', $end - $start), true, 'UPGRADE');
    } else {
        cacti_log('WARNING: Kadupul Upgrade Encountered Errors.  Messages below.  Details are below, but also in Kadupul upgrade log.', true, 'UPGRADE');
        print '---------------------------------------------------------------------------------------------' . PHP_EOL;
        print implode(PHP_EOL, $output) . PHP_EOL;
        print '---------------------------------------------------------------------------------------------' . PHP_EOL;
    }

    $pistart = microtime(true);

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

    $plugins = $preorder;

    if (cacti_sizeof($plugins)) {
        if (!defined('IN_PLUGIN_INSTALL')) {
            define('IN_PLUGIN_INSTALL', 1);
        }

        foreach ($plugins as $plugin) {
            $parts = explode('/', $plugin);
            $pname = end($parts);
            $ufunc1 = 'plugin_' . $pname . '_upgrade';
            $ufunc2 = $pname . '_upgrade_database';
            $ufunc3 = $pname . '_setup_table_new';

            if (!legacy_audit_plugin_installed($pname)) {
                cacti_log("NOTE: Plugin $pname is not installed, skipping.", true, 'UPGRADE');

                continue;
            }

            if (file_exists($plugin . '/INFO')) {
                // See if the plugin requires upgrading
                $info    = parse_ini_file($plugin . '/INFO', true);
                $version = $info['info']['version'];

                $old = db_fetch_cell_prepared(
                    'SELECT version
                    FROM plugin_config
                    WHERE directory = ?',
                    array($pname)
                );

                if ($version != $old) {
                    if (file_exists($plugin . '/setup.php')) {
                        include_once($plugin . '/setup.php');
                        if (file_exists($plugin . '/includes/database.php')) {
                            include_once($plugin . '/includes/database.php');
                        }

                        // Always run the new function if it's there
                        // Some plugins don't upgrade in the proper way
                        if (function_exists($ufunc3)) {
                            cacti_log("NOTE: Running Plugin $pname install function due to some plugins not upgrading properly.", true, 'UPGRADE');
                            $ufunc3(true);
                        }

                        if (function_exists($ufunc2)) {
                            cacti_log("NOTE: Upgrading Plugin $pname from $old to $version using alternate upgrade path.", true, 'UPGRADE');
                            $ufunc2(true);
                        } elseif (function_exists($ufunc1)) {
                            cacti_log("NOTE: Upgrading Plugin $pname from $old to $version using standard upgrade path.", true, 'UPGRADE');
                            // The script named the function without calling it; kept, so
                            // an upgrade does not start running code it never ran.
                            $ufunc1;
                        } else {
                            cacti_log("WARNING: Plugin $pname lacks an upgrade function.", true, 'UPGRADE');
                        }

                        if (file_exists($plugin . '/database_upgrade.php')) {
                            cacti_log("NOTE: Upgrading Plugin $pname from $old to $version using upgrade script.", true, 'UPGRADE');

                            [$return_var, $output] = legacy_audit_run([PHP_BINARY, $config['base_path'] . '/plugins/' . $pname . '/database_upgrade.php', '--type=large', '--force-ver=' . $old]);

                            if ($return_var == 0) {
                                print implode(PHP_EOL, $output) . PHP_EOL;
                                cacti_log("NOTE: Kadupul Plugin $pname Upgrade Succeeded.", true, 'UPGRADE');
                                print '---------------------------------------------------------------------------------------------' . PHP_EOL;
                                print implode(PHP_EOL, $output) . PHP_EOL;
                                print '---------------------------------------------------------------------------------------------' . PHP_EOL;
                            } else {
                                cacti_log("WARNING: Kadupul Plugin $pname Upgrade Encountered Errors.", true, 'UPGRADE');
                                print '---------------------------------------------------------------------------------------------' . PHP_EOL;
                                print implode(PHP_EOL, $output) . PHP_EOL;
                                print '---------------------------------------------------------------------------------------------' . PHP_EOL;
                            }
                        }
                    } else {
                        cacti_log("WARNING: Plugin $pname lacks a setup file.", true, 'UPGRADE');
                    }
                } else {
                    cacti_log("NOTE: Plugin $pname Does not Require Upgrade", true, 'UPGRADE');
                }
            } else {
                cacti_log("WARNING: Plugin $pname lacks an INFO file.  Can not upgrade!", true, 'UPGRADE');
            }
        }
    }

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

    $end = microtime(true);

    cacti_log(sprintf('NOTE: Kadupul Plugin Upgrades completed in %.2f seconds', $end - $pistart), true, 'UPGRADE');

    cacti_log(sprintf('NOTE: Audit Upgrade completed in %.2f seconds.', $end - $start), true, 'UPGRADE');

    return $core_exit;
}

function legacy_audit_plugin_installed($plugin)
{
    $installed = db_fetch_cell_prepared(
        'SELECT COUNT(*)
        FROM plugin_config
        WHERE directory = ?
        AND status = 1',
        array($plugin)
    );

    return $installed ? true : false;
}
