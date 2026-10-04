#!/usr/bin/env php
<?php
/**
 * plugin_manage.php
 *
 * Installs, enables, disables, or removes Cacti plugins.
 *
 * @package Cacti\CLI
 */
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');

$parms     = $_SERVER['argv'];
$install   = false;
$enable    = false;
$disable   = false;
$uninstall = false;
$allperms  = false;
$plugins   = array();

if (cacti_sizeof($parms)) {
	$shortopts = 'VvHh';

	$longopts = array(
		'plugin::',
		'install',
		'enable',
		'disable',
		'uninstall',
		'allperms',
		'version',
		'help'
	);

	$options = getopt($shortopts, $longopts);

	foreach($options as $arg => $value) {
		switch($arg) {
			case 'plugin':
				if (is_array($value)) {
					$plugins = $value;
				} else {
					$plugins[] = $value;
				}

				break;
			case 'install':
				$install = true;

				break;
			case 'uninstall':
				$uninstall = true;

				break;
			case 'disable':
				$disable = true;

				break;
			case 'enable':
				$enable = true;

				break;
			case 'allperms':
				$allperms = true;

				break;
			case 'version':
			case 'V':
			case 'v':
				display_version();
				exit(0);
			case 'help':
			case 'H':
			case 'h':
				display_help();
				exit(0);
			default:
				print "ERROR: Invalid Argument: ($arg)" . PHP_EOL . PHP_EOL;
				display_help();
				exit(1);
		}
	}

	// Sanity checks
	if ($enable && $disable) {
		print 'FATAL: Options --enable and --disable are mutually exclusive' . PHP_EOL;
		exit(1);
	} elseif ($install && $uninstall) {
		print 'FATAL: Options --install and --uninstall are mutually exclusive' . PHP_EOL;
		exit(1);
	} elseif ($install && $disable) {
		print 'FATAL: Options --install and --disable are mutually exclusive' . PHP_EOL;
		exit(1);
	} elseif ($uninstall && $enable) {
		print 'FATAL: Options --uninstall and --enable are mutually exclusive' . PHP_EOL;
		exit(1);
	}
} else {
	display_help();
	exit(1);
}

print 'NOTE: ' . cacti_sizeof($plugins) . ' Plugins to be acted on.' . PHP_EOL;
$exit_code = 0;

if (cacti_sizeof($plugins)) {
	foreach($plugins as $plugin) {
		print "NOTE: Plugin '$plugin' processing started" . PHP_EOL;

		if ($install) {
			$installed = false;

			if (is_dir($config['base_path'] . '/plugins/' . $plugin)) {
				print "NOTE: Plugin directory for Plugin $plugin exists" . PHP_EOL;

				if (!api_plugin_installed($plugin)) {
					$message = '';

					if (api_plugin_can_install($plugin, $message)) {
						api_plugin_install($plugin);

						if (api_plugin_installed($plugin)) {
							print "NOTE: Plugin $plugin installed successfully." . PHP_EOL;

							$installed = true;

							if ($enable) {
								api_plugin_enable($plugin);

								print "NOTE: Plugin $plugin enabled." . PHP_EOL;
							}
						} else {
							print "ERROR: Plugin '$plugin' installation failed." . PHP_EOL;
							$exit_code = 1;
						}
					} else {
						print "ERROR: Plugin '$plugin' can not install.  Message is: $message" . PHP_EOL;
						$exit_code = 1;
					}
				} else {
					$installed = true;

					print "WARNING: Plugin '$plugin' already installed." . PHP_EOL;
				}
			} else {
				print "WARNING: Plugin '$plugin' missing plugin directory.  Plugin not installed" . PHP_EOL;
				$exit_code = 1;
			}

			if ($installed && $allperms) {
				if (!plugin_manage_install_allrealms($plugin)) {
					$exit_code = 1;
				}
			}
		} elseif ($uninstall || $disable || $enable) {
			if ($disable) {
				print "NOTE: Disabling Plugin $plugin." . PHP_EOL;
				api_plugin_disable($plugin);
			}

			if ($uninstall) {
				print "NOTE: Uninstalling Plugin $plugin." . PHP_EOL;
				api_plugin_uninstall($plugin);
			}

			if ($enable) {
				print "NOTE: Enabling Plugin $plugin." . PHP_EOL;
				api_plugin_enable($plugin);
			}
		}
	}
}

exit($exit_code);

function plugin_manage_install_allrealms($plugin) {
	$admin_user = read_config_option('admin_user');

	if (!is_numeric($admin_user) || (int) $admin_user < 1) {
		print "ERROR: Could not grant Plugin '$plugin' permissions: configured administrator is invalid." . PHP_EOL;

		return false;
	}

	$admin = db_fetch_row_prepared('SELECT id
		FROM user_auth
		WHERE id = ?',
		array((int) $admin_user));

	if (empty($admin['id'])) {
		print "ERROR: Could not grant Plugin '$plugin' permissions: configured administrator was not found." . PHP_EOL;

		return false;
	}

	$realms = db_fetch_assoc_prepared('SELECT *
		FROM plugin_realms
		WHERE plugin = ?',
		array($plugin));

	$success = true;

	foreach($realms as $realm) {
		$realm_id = (int) $realm['id'] + 100;
		$granted = db_execute_prepared('REPLACE INTO user_auth_realm
			(user_id, realm_id)
			VALUES (?, ?)',
			array((int) $admin_user, $realm_id));

		if (!$granted) {
			print "ERROR: Could not grant Plugin '$plugin' permission for realm {$realm['id']}." . PHP_EOL;
			$success = false;

			continue;
		}

		$verified = db_fetch_cell_prepared('SELECT 1
			FROM user_auth_realm
			WHERE user_id = ?
			AND realm_id = ?',
			array((int) $admin_user, $realm_id));

		if (!$verified) {
			print "ERROR: Could not verify Plugin '$plugin' permission for realm {$realm['id']}." . PHP_EOL;
			$success = false;
		}
	}

	if ($success) {
		print "NOTE: Enabled Plugin '$plugin' permissions for the configured administrator." . PHP_EOL;
	}

	return $success;
}

/**
 * display_version - displays version information
 */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Install Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/**
 *display_help - displays the usage of the function
 */
function display_help () {
	print 'usage: plugin_manage.php [--plugin=S] [--install --enable --allperms] [--uninstall ] [--disable]' . PHP_EOL . PHP_EOL;

	print  'A utility to install/uninstall a Cacti plugin or plugins' . PHP_EOL . PHP_EOL;

	print 'Required:' . PHP_EOL;
	print '  --plugin=S   - The plugin to install.  Use the option multiple time for more plugins' . PHP_EOL . PHP_EOL;
	print 'Optional:' . PHP_EOL;
	print '  --install    - Install the plugin or plugins' . PHP_EOL;
	print '  --enable     - Enable the plugin or plugins' . PHP_EOL;
	print '  --allperms   - Enable all permission for plugin or plugins' . PHP_EOL;
	print '  --uninstall  - Uninstall the plugin or plugins' . PHP_EOL;
	print '  --disable    - Disable the plugin or plugins' . PHP_EOL;
}
