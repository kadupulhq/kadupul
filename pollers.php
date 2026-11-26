<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

require('./include/auth.php');

cacti_require_post_actions(array('actions', 'ping'));
require_once($config['base_path'] . '/lib/poller.php');
require_once(__DIR__ . '/src/Platform/Domain/IpAddressPolicy.php');

/* performing a full sync can take a lot of memory and time */
ini_set('memory_limit', '-1');
ini_set('max_execution_time', '900');

$poller_actions = array(
	1 => __('Delete'),
	2 => __('Disable'),
	3 => __('Enable'),
	5 => __('Clear Statistics'),
);

if ($config['poller_id'] == 1) {
	$poller_actions += array(4 =>__('Full Sync'));
}

$poller_status = array(
	0 => '<div class="deviceUnknown">'    . __('New/Idle')     . '</div>',
	1 => '<div class="deviceUp">'         . __('Running')      . '</div>',
	2 => '<div class="deviceRecovering">' . __('Idle')         . '</div>',
	3 => '<div class="deviceDown">'       . __('Down')         . '</div>',
	4 => '<div class="deviceDisabled">'   . __('Disabled')     . '</div>',
	5 => '<div class="deviceDown">'       . __('Recovering')   . '</div>',
	6 => '<div class="deviceDown">'       . __('Heartbeat')    . '</div>',
);

/* file: pollers.php, action: edit */
$fields_poller_edit = array(
	'spacer0' => array(
		'method' => 'spacer',
		'friendly_name' => __('Data Collector Information'),
	),
	'name' => array(
		'method' => 'textbox',
		'friendly_name' => __('Name'),
		'description' => __('The primary name for this Data Collector.'),
		'value' => '|arg1:name|',
		'size' => '50',
		'default' => __('New Data Collector'),
		'max_length' => '100'
	),
	'hostname' => array(
		'method' => 'textbox',
		'friendly_name' => __('Data Collector Hostname'),
		'description' => __('The hostname for this Data Collector.  It may have to be a Fully Qualified Domain name for the remote Pollers to contact it for activities such as re-indexing, Real-time graphing, etc.'),
		'value' => '|arg1:hostname|',
		'size' => '50',
		'default' => '',
		'max_length' => '100'
	),
	'timezone' => array(
		'method' => 'drop_callback',
		'friendly_name' => __('TimeZone'),
		'description' => __('The TimeZone for the Data Collector.'),
		'sql' => 'SELECT Name AS id, Name AS name FROM mysql.time_zone_name ORDER BY name',
		'action' => 'ajax_tz',
		'id' => '|arg1:timezone|',
		'value' => '|arg1:timezone|'
		),
	'notes' => array(
		'method' => 'textarea',
		'friendly_name' => __('Notes'),
		'description' => __('Notes for this Data Collector\'s Database.'),
		'value' => '|arg1:notes|',
		'textarea_rows' => 4,
		'textarea_cols' => 50
	),
	'spacer_collection' => array(
		'method' => 'spacer',
		'friendly_name' => __('Collection Settings'),
	),
	'processes' => array(
		'method' => 'textbox',
		'friendly_name' => __('Processes'),
		'description' => __('The number of Data Collector processes to use to spawn.'),
		'value' => '|arg1:processes|',
		'size' => '10',
		'default' => read_config_option('concurrent_processes'),
		'max_length' => '4'
	),
	'threads' => array(
		'method' => 'textbox',
		'friendly_name' => __('Threads'),
		'description' => __('The number of Spine Threads to use per Data Collector process.'),
		'value' => '|arg1:threads|',
		'size' => '10',
		'default' => read_config_option('max_threads'),
		'max_length' => '4'
	),
	'sync_interval' => array(
		'method' => 'drop_array',
		'friendly_name' => __('Sync Interval'),
		'description' => __('The polling sync interval in use.  This setting will affect how often this poller is checked and updated.'),
		'value' => '|arg1:sync_interval|',
		'default' => read_config_option('poller_sync_interval'),
		'array' => $poller_sync_intervals,
	),
	'spacer_remotedb' => array(
		'method' => 'spacer',
		'friendly_name' => __('Remote Database Connection'),
	),
	'dbhost' => array(
		'method' => 'textbox',
		'friendly_name' => __('Hostname'),
		'description' => __('The hostname for the remote database server.'),
		'value' => '|arg1:dbhost|',
		'size' => '50',
		'default' => '',
		'max_length' => '100'
	),
	'dbdefault' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database Name'),
		'description' => __('The name of the remote database.'),
		'value' => '|arg1:dbdefault|',
		'size' => '20',
		'default' => '',
		'max_length' => '20'
	),
	'dbuser' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database User'),
		'description' => __('The user name to use to connect to the remote database.'),
		'value' => '|arg1:dbuser|',
		'size' => '20',
		'default' => '',
		'max_length' => '20'
	),
	'dbpass' => array(
		'method' => 'textbox_password',
		'friendly_name' => __('Remote Database Password'),
		'description' => __('The user password to use to connect to the remote database.'),
		'value' => '|arg1:dbpass|',
		'size' => '40',
		'default' => '',
		'max_length' => '64'
	),
	'dbport' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database Port'),
		'description' => __('The TCP port to use to connect to the remote database.'),
		'value' => '|arg1:dbport|',
		'size' => '5',
		'default' => '',
		'max_length' => '5'
	),
	'dbretries' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database Retries'),
		'description' => __('The number of times to attempt to retry to connect to the remote database.'),
		'value' => '|arg1:dbretries|',
		'size' => '5',
		'default' => '',
		'max_length' => '5'
	),
	'dbssl' => array(
		'method' => 'checkbox',
		'friendly_name' => __('Remote Database SSL'),
		'description' => __('If the remote database uses SSL to connect, check the checkbox below.'),
		'value' => '|arg1:dbssl|',
		'default' => ''
	),
	'dbsslkey' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database SSL Key'),
		'description' => __('The file holding the SSL Key to use to connect to the remote database.'),
		'value' => '|arg1:dbsslkey|',
		'size' => '50',
		'default' => '',
		'max_length' => '255'
	),
	'dbsslcert' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database SSL Certificate'),
		'description' => __('The file holding the SSL Certificate to use to connect to the remote database.'),
		'value' => '|arg1:dbsslcert|',
		'size' => '50',
		'default' => '',
		'max_length' => '255'
	),
	'dbsslca' => array(
		'method' => 'textbox',
		'friendly_name' => __('Remote Database SSL Authority'),
		'description' => __('The file holding the SSL Certificate Authority to use to connect to the remote database.  This is an optional parameter that can be required by the database provider if they have started SSL using the --ssl-mode=VERIFY_CA option.'),
		'value' => '|arg1:dbsslca|',
		'size' => '50',
		'default' => '',
		'max_length' => '255'
	),
	'id' => array(
		'method' => 'hidden',
		'value' => '|arg1:id|',
	),
	'save_component_poller' => array(
		'method' => 'hidden',
		'value' => '1'
	)
);

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		form_actions();

		break;
	case 'ajax_tz':
		header('Content-Type: application/json');
		print json_encode(db_fetch_assoc_prepared('SELECT Name AS label, Name AS `value`
			FROM mysql.time_zone_name
			WHERE Name LIKE ?
			ORDER BY Name
			LIMIT ' . read_config_option('autocomplete_rows'),
			array('%' . get_nfilter_request_var('term') . '%')));

		break;
	case 'ping':
		test_database_connection();

		break;
	case 'edit':
		top_header();

		poller_edit();

		bottom_footer();
		break;
	default:
		top_header();

		pollers();

		bottom_footer();
		break;
}

/* --------------------------
    Global Form Functions
   -------------------------- */

/* --------------------------
    The Save Function
   -------------------------- */

function poller_validate_remote_host($host) {
	if (!is_string($host)) {
		return false;
	}

	$trimmed = trim($host);
	if (!\Kadupul\Platform\Domain\IpAddressPolicy::isSafeRemoteHostSyntax($trimmed)) {
		return false;
	}

	if (\Kadupul\Platform\Domain\IpAddressPolicy::isValidIpAddress($trimmed)) {
		return !\Kadupul\Platform\Domain\IpAddressPolicy::isLoopbackOrReservedIp($trimmed);
	}

	$resolved_ips = array();
	$records = @dns_get_record($trimmed, DNS_A | DNS_AAAA);
	if (is_array($records)) {
		foreach ($records as $record) {
			if (!empty($record['ip'])) {
				$resolved_ips[] = $record['ip'];
			}
			if (!empty($record['ipv6'])) {
				$resolved_ips[] = $record['ipv6'];
			}
		}
	}

	$ipv4s = @gethostbynamel($trimmed);
	if (is_array($ipv4s)) {
		$resolved_ips = array_merge($resolved_ips, $ipv4s);
	}

	foreach (array_unique($resolved_ips) as $ip) {
		if (\Kadupul\Platform\Domain\IpAddressPolicy::isLoopbackOrReservedIp($ip)) {
			return false;
		}
	}

	return true;
}

function form_save() {
	if (isset_request_var('save_component_poller')) {

		// Common data
		$save['id']       = get_filter_request_var('id');
		$save['name']     = form_input_validate(get_nfilter_request_var('name'), 'name', '', false, 3);
		$save['hostname'] = form_input_validate(get_nfilter_request_var('hostname'), 'hostname', '', false, 3);
		$save['timezone'] = form_input_validate(get_nfilter_request_var('timezone'), 'timezone', '', false, 3);
		$save['notes']    = form_input_validate(get_nfilter_request_var('notes'), 'notes', '', true, 3);

		// Process settings
		$save['processes'] = form_input_validate(get_nfilter_request_var('processes'), 'processes', '^[0-9]+$', false, 3);
		$save['threads']   = form_input_validate(get_nfilter_request_var('threads'), 'threads', '^[0-9]+$', false, 3);

		$error = false;
		$poller_id_int = (int) $save['id'];

		if ($poller_id_int !== 1) {
			$save['sync_interval'] = form_input_validate(get_nfilter_request_var('sync_interval'), 'sync_interval', '^[0-9]+$', false, 3);

			// Database settings
			$save['dbdefault']     = form_input_validate(get_nfilter_request_var('dbdefault'), 'dbdefault', '', true, 3);
			$save['dbhost']        = form_input_validate(get_nfilter_request_var('dbhost'),    'dbhost',    '', true, 3);
			$save['dbuser']        = form_input_validate(get_nfilter_request_var('dbuser'),    'dbuser',    '', true, 3);
			$submitted_pass        = form_input_validate(get_nfilter_request_var('dbpass'),    'dbpass',    '', true, 3);
			if ($submitted_pass !== '' && isset_request_var('dbpass_confirm') && $submitted_pass !== get_nfilter_request_var('dbpass_confirm')) {
				raise_message(4);
				$error = true;
			} elseif ($submitted_pass === '' && $poller_id_int > 1) {
				$existing_pass = db_fetch_cell_prepared('SELECT dbpass FROM poller WHERE id = ?', array($poller_id_int));
				$save['dbpass'] = is_string($existing_pass) ? $existing_pass : '';
			} else {
				$save['dbpass'] = $submitted_pass;
			}
			$save['dbport']        = form_input_validate(get_nfilter_request_var('dbport'),    'dbport',    '^[0-9]+$', true, 3);
			$save['dbretries']     = form_input_validate(get_nfilter_request_var('dbretries'), 'dbretries', '^[0-9]+$', true, 3);
			$save['dbssl']         = isset_request_var('dbssl') ? 'on':'';
			$save['dbsslkey']      = form_input_validate(get_nfilter_request_var('dbsslkey'),  'dbsslkey',  '', true, 3);
			$save['dbsslcert']     = form_input_validate(get_nfilter_request_var('dbsslcert'), 'dbsslcert', '', true, 3);
			$save['dbsslca']       = form_input_validate(get_nfilter_request_var('dbsslca'),   'dbsslca',   '', true, 3);

			if (!poller_validate_remote_host($save['hostname'])) {
				raise_message('poller_hostname_invalid', __('Invalid remote Data Collector hostname. Loopback, link-local, reserved, and socket targets are not permitted.'), MESSAGE_LEVEL_ERROR);
				$error = true;
			}

			if ($save['dbhost'] !== '' && !poller_validate_remote_host($save['dbhost'])) {
				raise_message('poller_dbhost');
				$error = true;
			}

			if ($save['dbport'] !== '' && filter_var($save['dbport'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535))) === false) {
				raise_message('poller_dbport_invalid', __('Remote Database Port must be between 1 and 65535.'), MESSAGE_LEVEL_ERROR);
				$error = true;
			}

			if ($save['dbretries'] !== '' && filter_var($save['dbretries'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 5))) === false) {
				raise_message('poller_dbretries_invalid', __('Remote Database Retries must be between 0 and 5.'), MESSAGE_LEVEL_ERROR);
				$error = true;
			}
		} else {
			$trimmed_host = trim((string) $save['hostname']);
			if ($trimmed_host === '' || preg_match('/[\x00-\x1f\x7f\s;\/\\\\]/', $trimmed_host) === 1
				|| str_starts_with($trimmed_host, '-')
				|| (!\Kadupul\Platform\Domain\IpAddressPolicy::isValidIpAddress($trimmed_host)
					&& filter_var($trimmed_host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false)) {
				raise_message('poller_hostname_invalid', __('Invalid Data Collector hostname.'), MESSAGE_LEVEL_ERROR);
				$error = true;
			}
		}

		if (!is_ipaddress($save['hostname']) && !str_contains($save['hostname'], '.')) {
			raise_message('bad_host', __esc('WARNING: Use of Data Collector Hostname \'%s\' is insecure!  Use either a fully qualified domain name or IP address to avoid equivalent hosts from other domains accessing this service', $save['hostname']), MESSAGE_LEVEL_WARN);
		}

		// Check for duplicate hostname
		if (!$error && poller_check_duplicate_poller_id($save['id'], $save['hostname'], 'hostname')) {
			raise_message('dupe_hostname', __esc('You have already used this hostname \'%s\'.  Please enter a non-duplicate hostname.', $save['hostname']), MESSAGE_LEVEL_ERROR);
			$error = true;
		}

		if (!$error && isset($save['dbhost']) && $save['dbhost'] !== '') {
			if (poller_check_duplicate_poller_id($save['id'], $save['dbhost'], 'dbhost')) {
				raise_message('dupe_dbhost', __esc('You have already used this database hostname \'%s\'.  Please enter a non-duplicate database hostname.', $save['dbhost']), MESSAGE_LEVEL_ERROR);
				$error = true;
			}
		}

		if (!$error && $poller_id_int !== 1 && !empty($save['dbhost']) && poller_host_duplicate($save['id'], $save['dbhost'])) {
			raise_message('poller_nodupe');
		} elseif (!is_error_message() && $error == false) {
			$poller_id = sql_save($save, 'poller');

			if ($poller_id) {
				raise_message(1);
			} else {
				raise_message(2);
			}
		}

		header('Location: pollers.php?header=false&action=edit&id=' . (empty($poller_id) ? get_nfilter_request_var('id') : $poller_id));
	}
}

function poller_check_duplicate_poller_id($poller_id, $hostname, $column) {
	if (!in_array($column, array('hostname', 'dbhost'), true)) {
		return false;
	}

	if (!is_string($hostname) || trim($hostname) === '') {
		return false;
	}

	$ip_addresses  = array();
	$ip_hostnames  = array();

	if (is_ipaddress($hostname)) {
		$address = @gethostbyaddr($hostname);

		if ($address != $hostname) {
			$ip_hostnames[$address] = $address;
		} else {
			$ip_addresses[$address] = $address;
		}

		$ip_addresses[$hostname] = $hostname;
	} elseif (strpos($hostname, '.') !== false) {
		$addresses = @dns_get_record($hostname);
		$ip        = @gethostbyname($hostname);

		if ($ip != $hostname) {
			$ip_addresses[$ip] = $ip;
		}

		$ip_hostnames[$hostname] = $hostname;

		if (cacti_sizeof($addresses)) {
			foreach($addresses as $address) {
				if (isset($address['target'])) {
					$ip_hostnames[$address['host']] = $address['host'];
				}

				if (isset($address['host'])) {
					$ip_hostnames[$address['host']] = $address['host'];
				}

				if (isset($address['ip'])) {
					$ip_addresses[$address['ip']] = $address['ip'];
				}
			}
		}
	} else {
		$ip_hostnames[$hostname] = $hostname;

		$address = @gethostbyname($hostname);

		if ($address != $hostname) {
			$ip_addresses[$address] = $address;
		}
	}

	$params = array((int) $poller_id);
	$conditions = array();

	if (cacti_sizeof($ip_addresses)) {
		$ip_values = array_values($ip_addresses);
		$placeholders = implode(',', array_fill(0, count($ip_values), '?'));
		$conditions[] = "`$column` IN ($placeholders)";
		$params = array_merge($params, $ip_values);
	}

	if (cacti_sizeof($ip_hostnames)) {
		$host_conditions = array();
		foreach($ip_hostnames as $host) {
			$parts = explode('.', $host);
			$host_conditions[] = "(`$column` = ? OR `$column` = ?)";
			$params[] = $parts[0];
			$params[] = $host;
		}
		$conditions[] = '(' . implode(' OR ', $host_conditions) . ')';
	}

	if (!cacti_sizeof($conditions)) {
		return false;
	}

	$sql_where = ' AND (' . implode(' OR ', $conditions) . ')';

	$duplicate = db_fetch_cell_prepared("SELECT id
		FROM poller
		WHERE id != ?
		$sql_where",
		$params);

	if (empty($duplicate)) {
		return false;
	} else {
		return true;
	}
}

function poller_host_duplicate($poller_id, $host) {
	if ($host == 'localhost') {
		return true;
	} else {
		return db_fetch_cell_prepared('SELECT COUNT(*)
			FROM poller
			WHERE dbhost LIKE ?
			AND id != ?',
			array($host . '%', $poller_id));
	}
}

function form_actions() {
	global $poller_actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') { // delete
				$selected_items = array_values(array_filter(array_map('intval', $selected_items), static function ($id) {
					return $id > 1;
				}));

				if (cacti_sizeof($selected_items)) {
					db_execute('DELETE FROM poller WHERE ' . array_to_sql_or($selected_items, 'id'));
					db_execute('UPDATE host SET poller_id=1 WHERE deleted="" AND ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE automation_networks SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE automation_processes SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE poller_command SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE poller_item SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE poller_output_realtime SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));
					db_execute('UPDATE poller_time SET poller_id=1 WHERE ' . array_to_sql_or($selected_items, 'poller_id'));

					cacti_log('NOTE: The poller(s) with the id(s): ' . implode(',', $selected_items) . ' deleted by user ' . $_SESSION['sess_user_id'], false, 'WEBUI');
				}
			} elseif (get_request_var('drp_action') == '2') { // disable
				$selected_items = array_values(array_filter(array_map('intval', $selected_items), static function ($id) {
					return $id > 1;
				}));

				if (cacti_sizeof($selected_items)) {
					db_execute('UPDATE poller SET disabled="on" WHERE ' . array_to_sql_or($selected_items, 'id'));

					cacti_log('NOTE: The poller(s) with the id(s): ' . implode(',', $selected_items) . ' disabled by user ' . $_SESSION['sess_user_id'], false, 'WEBUI');
				}
			} elseif (get_request_var('drp_action') == '3') { // enable
				db_execute('UPDATE poller SET disabled="" WHERE ' . array_to_sql_or($selected_items, 'id'));

				cacti_log('NOTE: The poller(s) with the id(s): ' . implode(',', $selected_items) . ' enabled by user ' . $_SESSION['sess_user_id'], false, 'WEBUI');
			} elseif (get_request_var('drp_action') == '4') { // full sync
				cacti_session_close();

				$success = array();
				$failed  = array();
				$ids     = array();

				foreach($selected_items as $item) {
					// Operation not allowed on the main poller
					if ($item == 1) {
						continue;
					}

					$ids[]   = $item;

					$poller = db_fetch_row_prepared('SELECT *
						FROM poller
						WHERE id = ?',
						array($item));

					if ($poller['dbhost'] == 'localhost') {
						raise_message('poller_dbhost');
						continue;
					} elseif ($item == 1) {
						raise_message('poller_nomain');
						continue;
					} else {
						if (replicate_out($item)) {
							$success[] = $item;

							db_execute_prepared('UPDATE poller
								SET last_sync = NOW()
								WHERE id = ?',
								array($item));
						} else {
							$failed[] = $item;
						}
					}
				}

				cacti_session_start();

				if (cacti_sizeof($failed)) {
					cacti_log('WARNING: Some selected Remote Data Collectors in [' . implode(', ', $ids) . '] failed synchronization by user ' . get_username($_SESSION['sess_user_id']) . ', Successful/Failed[' . cacti_sizeof($success) . '/' . cacti_sizeof($failed) . '].  See log for details.', false, 'WEBUI');
				} else {
					cacti_log('NOTE: All selected Remote Data Collectors in [' . implode(', ', $ids) . '] synchronized correctly by user ' . get_username($_SESSION['sess_user_id']), false, 'WEBUI');
				}
			} elseif (get_request_var('drp_action') == '5') { // clear statistics
				foreach($selected_items as $item) {
					db_execute_prepared('UPDATE poller
						SET total_time = 0, max_time = 0, min_time = 9999999, avg_time = 0, total_polls = 0
						WHERE id = ?',
						array($item));
				}

				raise_message('poller_clear', __('Data Collector Statistics cleared.'), MESSAGE_LEVEL_INFO);
			}
		}

		header('Location: pollers.php?header=false');
		exit;
	}

	/* setup some variables */
	$pollers = ''; $i = 0;

	/* loop through each of the graphs selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			if ((int) $matches[1] === 1 && in_array(get_nfilter_request_var('drp_action'), array('1', '2', '4'), true)) {
				continue;
			}

			$pollers .= '<li>' . html_escape(db_fetch_cell_prepared('SELECT name FROM poller WHERE id = ?', array($matches[1]))) . '</li>';
			$poller_array[$i] = $matches[1];

			$i++;
		}
	}

	top_header();

	form_start('pollers.php');

	html_start_box(escape_page_action($poller_actions, get_nfilter_request_var('drp_action')), '60%', '', '3', 'center', '');

	if (isset($poller_array) && cacti_sizeof($poller_array)) {
		if (get_nfilter_request_var('drp_action') == '1') { // delete
			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to delete the following Data Collector.  Note, all devices will be disassociated from this Data Collector and mapped back to the Main Kadupul Data Collector.', 'Click \'Continue\' to delete all following Data Collectors.  Note, all devices will be disassociated from these Data Collectors and mapped back to the Main Kadupul Data Collector.', cacti_sizeof($poller_array)) . "</p>
					<div class='itemlist'><ul>$pollers</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Delete Data Collector', 'Delete Data Collectors', cacti_sizeof($poller_array)) . "'>";
		} elseif (get_request_var('drp_action') == '2') { // disable
			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to disable the following Data Collector.', 'Click \'Continue\' to disable the following Data Collectors.', cacti_sizeof($poller_array)) . "</p>
					<div class='itemlist'><ul>$pollers</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Disable Data Collector', 'Disable Data Collectors', cacti_sizeof($poller_array)) . "'>";
		} elseif (get_request_var('drp_action') == '3') { // enable
			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to enable the following Data Collector.', 'Click \'Continue\' to enable the following Data Collectors.', cacti_sizeof($poller_array)) . "</p>
					<div class='itemlist'><ul>$pollers</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Enable Data Collector', 'Enable Data Collectors', cacti_sizeof($poller_array)) . "'>";
		} elseif (get_request_var('drp_action') == '4') { // full sync
			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to Synchronize the Remote Data Collector for Offline Operation.', 'Click \'Continue\' to Synchronize the Remote Data Collectors for Offline Operation.', cacti_sizeof($poller_array)) . "</p>
					<div class='itemlist'><ul>$pollers</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Enable Data Collector', 'Synchronize Remote Data Collectors', cacti_sizeof($poller_array)) . "'>";
		} elseif (get_request_var('drp_action') == '5') { // clear statistics
			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to Clear Data Collector Statistics for the Data Collector.', 'Click \'Continue\' to Clear Data Collector Statistics for the Data Collectors.', cacti_sizeof($poller_array)) . "</p>
					<div class='itemlist'><ul>$pollers</ul></div>
				</td>
			</tr>\n";

			$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Clear Statistics for Data Collector', 'Clear Statistics for Data Collectors', cacti_sizeof($poller_array)) . "'>";
		}
	} else {
		raise_message(40);
		header('Location: pollers.php?header=false');
		exit;
	}

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($poller_array) ? serialize($poller_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

/* ---------------------
    Site Functions
   --------------------- */

function poller_edit() {
	global $fields_poller_edit;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	if (!isempty_request_var('id')) {
		$poller = db_fetch_row_prepared('SELECT *
			FROM poller
			WHERE id = ?',
			array(get_request_var('id')));

		$header_label = __esc('Site [edit: %s]', $poller['name']);
	} else {
		$poller = array();

		$header_label = __('Site [new]');
	}

	form_start('pollers.php', 'poller');

	html_start_box($header_label, '100%', true, '3', 'center', '');

	if (cacti_sizeof($poller)) {
		if ($poller['id'] == 1) {
			unset($fields_poller_edit['sync_interval']);
			unset($fields_poller_edit['spacer_remotedb']);
			unset($fields_poller_edit['dbdefault']);
			unset($fields_poller_edit['dbhost']);
			unset($fields_poller_edit['dbuser']);
			unset($fields_poller_edit['dbpass']);
			unset($fields_poller_edit['dbport']);
			unset($fields_poller_edit['dbretries']);
			unset($fields_poller_edit['dbssl']);
			unset($fields_poller_edit['dbsslkey']);
			unset($fields_poller_edit['dbsslcert']);
			unset($fields_poller_edit['dbsslca']);
		} else {
			if (!empty($poller['dbpass'])) {
				$fields_poller_edit['dbpass']['placeholder'] = __('Leave blank to keep existing password');
			}
			$poller['dbpass'] = '';
		}

		if ($poller['timezone'] == '') {
			$poller['timezone'] = ini_get('date.timezone');
		}
	}

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_poller_edit, (isset($poller) ? $poller : array()))
		)
	);

	$tip_text = __('Remote Data Collectors must be able to communicate to the Main Data Collector, and vice versa.  Use this button to verify that the Main Data Collector can communicate to this Remote Data Collector.');

	if (read_config_option('hide_form_description') == 'on') {
		$tooltip = '<br><span class="formFieldDescription">' . $tip_text . '</span>';
	} else {
		$tooltip = '<div class="formTooltip">' . str_replace("\n", '', display_tooltip($tip_text)) . '</div>';
	}

	$row_html = '<div class="formRow odd"><div class="formColumnLeft"><div class="formFieldName">' . __('Test Database Connection') . $tooltip . '</div></div><div class="formColumnRight"><input type="button" class="ui-button ui-corner-all ui-widget" id="dbtest" value="' . __esc('Test Connection') . '"><span id="results"></span></div></div>';

	$pt = read_config_option('poller_type');

	if (isset($poller) && cacti_sizeof($poller)) {
		if ($poller['id'] > 1) {
			?>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
			pt = <?php print $pt;?>;

			function showHideRemoteDB() {
					var hasSSL = $('#dbssl').is(':checked');
					if (hasSSL) {
						$('#row_dbsslkey').show();
						$('#row_dbsslcert').show();
						$('#row_dbsslca').show();
					} else {
						$('#row_dbsslkey').hide();
						$('#row_dbsslcert').hide();
						$('#row_dbsslca').hide();
					}
			}

			$(function() {
				$('#row_dbsslca').after('<?php print $row_html;?>');
				$('#dbssl').on('click', function() {
					showHideRemoteDB();
				});

				$('#dbtest').on('click', function() {
					ping_database();
				});

				showHideRemoteDB();

				if (pt == 1) {
					$('#row_threads').hide();
				}
			});

			function ping_database() {
				dbssl = $('#dbssl').is(':checked') ? 'on':'';

				$.post('pollers.php', {
					__csrf_magic: csrfMagicToken,
					action:       'ping',
					dbdefault:    $('#dbdefault').val(),
					dbhost:       $('#dbhost').val(),
					dbuser:       $('#dbuser').val(),
					dbpass:       $('#dbpass').val(),
					dbport:       $('#dbport').val(),
					dbretries:    $('#dbretries').val(),
					dbssl:        dbssl,
					dbsslkey:     $('#dbsslkey').val(),
					dbsslcert:    $('#dbsslcert').val(),
					dbsslca:      $('#dbsslca').val()
				}).done(function(data) {
					$('#results').empty().show().html(DOMPurify.sanitize(data)).fadeOut(2000);
				});
			}
			</script>
			<?php
		} else {
			?>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
			pt = <?php print $pt;?>;

			$(function() {
				if (pt == 1) {
					$('#row_threads').hide();
				}
			});
			</script>
			<?php
		}
	}

	html_end_box(true, true);

	form_save_button('pollers.php', 'return');
}

function test_database_connection($poller = array()) {
	if (!cacti_sizeof($poller)) {
		$poller['dbtype'] = 'mysql';

		$fields = array(
			'dbhost',
			'dbuser',
			'dbpass',
			'dbdefault',
			'dbport',
			'dbretries',
			'dbssl',
			'dbsslkey',
			'dbsslcert',
			'dbsslca'
		);

		foreach ($fields as $field) {
			if ($field == 'dbssl') {
				if (isset_request_var('dbssl') && get_nfilter_request_var('dbssl') == 'on') {
					$poller['dbssl'] = 'on';
				} else {
					$poller['dbssl'] = '';
				}
			} elseif (isset_request_var($field)) {
				$poller[$field] = get_nfilter_request_var($field);
			} else {
				print 'ERROR: DB Connection Column ' . html_escape($field) . ' Missing';
				return false;
			}
		}
	}

	if (!poller_validate_remote_host((string) ($poller['dbhost'] ?? ''))) {
		print __('Connection Failed');
		return false;
	}

	$dbdefault = (string) ($poller['dbdefault'] ?? '');
	if ($dbdefault === '' || preg_match('/[;\x00-\x1f\x7f]/', $dbdefault) === 1) {
		print __('Connection Failed');
		return false;
	}

	$dbport = ($poller['dbport'] === '' || $poller['dbport'] === null) ? 3306 : filter_var($poller['dbport'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 1, 'max_range' => 65535)));
	if ($dbport === false) {
		print __('Connection Failed');
		return false;
	}

	$dbretries = ($poller['dbretries'] === '' || $poller['dbretries'] === null) ? 1 : filter_var($poller['dbretries'], FILTER_VALIDATE_INT, array('options' => array('min_range' => 0, 'max_range' => 5)));
	if ($dbretries === false) {
		$dbretries = 1;
	}

	$dbssl = ($poller['dbssl'] === 'on' || $poller['dbssl'] === true || $poller['dbssl'] === 1 || $poller['dbssl'] === '1');
	$dbsslca = (string) ($poller['dbsslca'] ?? '');
	$dbsslkey = (string) ($poller['dbsslkey'] ?? '');
	$dbsslcert = (string) ($poller['dbsslcert'] ?? '');

	if ($dbssl) {
		if ($dbsslca === '' || !is_valid_pathname($dbsslca) || str_contains($dbsslca, '..') || is_link($dbsslca) || !is_file($dbsslca) || !is_readable($dbsslca)) {
			print __('Connection Failed');
			return false;
		}
		foreach (array($dbsslkey, $dbsslcert) as $tls_file) {
			if ($tls_file !== '' && (!is_valid_pathname($tls_file) || str_contains($tls_file, '..') || is_link($tls_file) || !is_file($tls_file) || !is_readable($tls_file))) {
				print __('Connection Failed');
				return false;
			}
		}
	}

	$connection = db_connect_real(
		trim((string) $poller['dbhost']),
		(string) ($poller['dbuser'] ?? ''),
		(string) ($poller['dbpass'] ?? ''),
		$dbdefault,
		'mysql',
		(int) $dbport,
		(int) $dbretries,
		$dbssl,
		$dbsslkey,
		$dbsslcert,
		$dbsslca,
		false,
		false
	);

    if (is_object($connection)) {
        db_close($connection);
        print __('Connection Successful');
    } else {
        print __('Connection Failed');
    }
}

function pollers() {
	global $poller_actions, $poller_status, $item_rows;

	/* ================= input validation and session storage ================= */
	$filters = array(
		'rows' => array(
			'filter' => FILTER_VALIDATE_INT,
			'pageset' => true,
			'default' => '-1'
			),
		'page' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '1'
			),
		'refresh' => array(
			'filter' => FILTER_VALIDATE_INT,
			'default' => '20'
			),
		'filter' => array(
			'filter' => FILTER_DEFAULT,
			'pageset' => true,
			'default' => ''
			),
		'sort_column' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'name',
			'options' => array('options' => 'sanitize_search_string')
			),
		'sort_direction' => array(
			'filter' => FILTER_CALLBACK,
			'default' => 'ASC',
			'options' => array('options' => 'sanitize_search_string')
			)
	);

	validate_store_request_vars($filters, 'sess_pollers');
	/* ================= input validation ================= */

	$refresh['page']    = 'pollers.php?header=false';
	$refresh['seconds'] = get_request_var('refresh');
	$refresh['logout']  = 'false';

	set_page_refresh($refresh);

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	html_start_box( __('Data Collectors'), '100%', '', '3', 'center', '');

	?>
	<tr class='even'>
		<td>
			<form id='form_poller' action='pollers.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Collectors');?>
					</td>
					<td>
						<select id='rows'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . html_escape($value) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<?php print __('Refresh');?>
					</td>
					<td>
						<select id='refresh'>
							<?php
							$frequency = array(
								5   => __('%d Seconds', 5),
								10  => __('%d Seconds', 10),
								20  => __('%d Seconds', 20),
								30  => __('%d Seconds', 30),
								45  => __('%d Seconds', 45),
								60  => __('%d Minute', 1),
								120 => __('%d Minutes', 2),
								300 => __('%d Minutes', 5)
							);

							foreach ($frequency as $r => $row) {
								echo "<option value='" . $r . "'" . (isset_request_var('refresh') && $r == get_request_var('refresh') ? ' selected' : '') . '>' . $row . '</option>';
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='submit' class='ui-button ui-corner-all ui-widget' id='go' value='<?php print __esc('Go');?>' title='<?php print __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear');?>' title='<?php print __esc('Clear Filters');?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

			function applyFilter() {
				strURL  = 'pollers.php?header=false';
				strURL += '&filter='+$('#filter').val();
				strURL += '&refresh='+$('#refresh').val();
				strURL += '&rows='+$('#rows').val();
				loadPageNoHeader(strURL);
			}

			function clearFilter() {
				strURL = 'pollers.php?clear=1&header=false';
				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#clear').on('click', function() {
					clearFilter();
				});

				$('#refresh, #rows').on('change', function() {
					applyFilter();
				});

				$('#form_poller').on('submit', function(event) {
					event.preventDefault();
					applyFilter();
				});
			});

			</script>
		</td>
	</tr>
	<?php

	html_end_box();

	/* form the 'where' clause for our main sql query */
	if (get_request_var('filter') != '') {
		$sql_where = 'WHERE name LIKE ' . db_qstr('%' . get_request_var('filter') . '%');
	} else {
		$sql_where = '';
	}

	$total_rows = db_fetch_cell("SELECT COUNT(*) FROM poller $sql_where");

	$sql_order = get_order_string();
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$pollers = db_fetch_assoc("SELECT poller.*, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(poller.last_status) as heartbeat, count(h.id) AS hosts
		FROM poller
		LEFT JOIN host AS h
		ON h.poller_id=poller.id
		$sql_where
		GROUP BY poller.id
		$sql_order
		$sql_limit");

	$nav = html_nav_bar('pollers.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 5, __('Pollers'), 'page', 'main');

	form_start('pollers.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'name' => array(
			'display' => __('Collector Name'),
			'align' => 'left',
			'sort' => 'ASC',
			'tip' => __('The Name of this Data Collector.')
		),
		'id' => array(
			'display' => __('ID'),
			'align' => 'right',
			'sort' => 'ASC',
			'tip' => __('The unique id associated with this Data Collector.')
		),
		'poller.hostname'    => array(
			'display' => __('Hostname'),
			'align' => 'right',
			'sort' => 'ASC',
			'tip' => __('The Hostname where the Data Collector is running.')
		),
		'status'      => array(
			'display' => __('Status'),
			'align' => 'center',
			'sort' => 'DESC',
			'tip' => __('The Status of this Data Collector.')
		),
		'nosort0'   => array(
			'display' => __('Proc/Threads'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The Number of Poller Processes and Threads for this Data Collector.')
		),
		'total_time'  => array(
			'display' => __('Polling Time'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The last data collection time for this Data Collector.')
		),
		'nosort1'     => array(
			'display' => __('Avg/Max'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The Average and Maximum Collector timings for this Data Collector.')
		),
		'hosts'       => array(
			'display' => __('Devices'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The number of Devices associated with this Data Collector.')
		),
		'snmp'        => array(
			'display' => __('SNMP Gets'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The number of SNMP gets associated with this Collector.')
		),
		'script'      => array(
			'display' => __('Scripts'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The number of script calls associated with this Data Collector.')
		),
		'server'      => array(
			'display' => __('Servers'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The number of script server calls associated with this Data Collector.')
		),
		'last_update' => array(
			'display' => __('Last Finished'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The last time this Data Collector completed.')
		),
		'last_status' => array(
			'display' => __('Last Update'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The last time this Data Collector checked in with the main Kadupul site.')
		),
		'last_sync' => array(
			'display' => __('Last Sync'),
			'align' => 'right',
			'sort' => 'DESC',
			'tip' => __('The last time this Data Collector was full synced with the main Kadupul site.')
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (cacti_sizeof($pollers)) {
		foreach ($pollers as $poller) {
			if ($poller['id'] == 1) {
				$disabled = true;
			} else {
				$disabled = false;
			}

			if ($poller['disabled'] == 'on') {
				$poller['status'] = 4;
			}else if ($poller['heartbeat'] > 310) {
				$poller['status'] = 6;
			}

			$mma = round($poller['avg_time']?:0, 2) . '/' .  round(max($poller['max_time']?:1,1), 2);

			if (empty($poller['name'])) {
				$poller['name'] = '&lt;no name&gt;';
			}

			$pt = read_config_option('poller_type');

			form_alternate_row('line' . $poller['id'], true, $disabled);
			form_selectable_cell(filter_value($poller['name'], get_request_var('filter'), 'pollers.php?action=edit&id=' . $poller['id']), $poller['id']);
			form_selectable_cell($poller['id'], $poller['id'], '', 'right');
			form_selectable_ecell($poller['hostname'], $poller['id'], '', 'right');
			form_selectable_cell($poller_status[$poller['status']], $poller['id'], '', 'center');
			form_selectable_cell($poller['processes'] . '/' . ($pt == 2 ? $poller['threads']:'-'), $poller['id'], '', 'right');
			form_selectable_cell(number_format_i18n($poller['total_time'], 2), $poller['id'], '', 'right');
			form_selectable_cell($mma, $poller['id'], '', 'right');
			form_selectable_cell(number_format_i18n($poller['hosts'], '-1'), $poller['id'], '', 'right');
			form_selectable_cell(number_format_i18n($poller['snmp'], '-1'), $poller['id'], '', 'right');
			form_selectable_cell(number_format_i18n($poller['script'], '-1'), $poller['id'], '', 'right');
			form_selectable_cell(number_format_i18n($poller['server'], '-1'), $poller['id'], '', 'right');
			form_selectable_cell(substr($poller['last_update'], 5), $poller['id'], '', 'right');
			form_selectable_cell(substr($poller['last_status'], 5), $poller['id'], '', 'right');

			if ($poller['id'] == 1) {
				form_selectable_cell(__('N/A'), $poller['id'], '', 'right');
			} else {
				form_selectable_cell(substr($poller['last_sync'], 5), $poller['id'], '', 'right');
			}

			form_checkbox_cell($poller['name'], $poller['id'], $disabled);
			form_end_row();
		}
	} else {
		print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No Data Collectors Found') . "</em></td></tr>\n";
	}

	html_end_box(false);

	if (cacti_sizeof($pollers)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($poller_actions);

	form_end();
}
