<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/* include cacti base functions */
include('./include/auth.php');

cacti_require_post_actions(array('actions'));
include_once('./lib/snmp.php');
include_once('./lib/poller.php');

$network_actions = array(
	1 => __('Delete'),
	2 => __('Disable'),
	3 => __('Enable'),
	4 => __('Discover Now'),
	5 => __('Cancel Discovery')
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
case 'edit':
	// Symfony owns the edit form. Relative targets keep subdirectory installations working.
	$network_id = get_filter_request_var('id');
	header('Location: ' . (empty($network_id) ? 'app.php/automation/networks/new' : 'app.php/automation/networks/' . $network_id . '/edit'), true, 302);
	exit;
default:
	top_header();
	networks();
	bottom_footer();

	break;
}

/* --------------------------
    The Save Function
   -------------------------- */

function form_save() {
	// The edit form moved to Symfony; replaying an old submission must not write.
	http_response_code(409);
	header('Cache-Control: private, no-store');
	print html_escape(__('This Network Discovery Range form has expired. Open the network again and use its current form.'));

	exit;
}

function api_networks_remove($network_id){
	db_execute_prepared('DELETE FROM automation_networks
		WHERE id = ?',
		array($network_id));

	db_execute_prepared('DELETE FROM automation_devices
		WHERE network_id = ?',
		array($network_id));
}

function api_networks_enable($network_id){
	db_execute_prepared('UPDATE automation_networks
		SET enabled="on"
		WHERE id = ?',
		array($network_id));
}

function api_networks_disable($network_id){
	db_execute_prepared('UPDATE automation_networks
		SET enabled=""
		WHERE id = ?',
		array($network_id));
}

function api_networks_cancel($network_id){
	db_execute_prepared('UPDATE IGNORE automation_processes
		SET command="cancel"
		WHERE task="tmaster"
		AND network_id = ?',
		array($network_id));
}

function api_networks_discover($network_id, $discover_debug) {
	global $config;

	$enabled   = db_fetch_cell_prepared('SELECT enabled
		FROM automation_networks
		WHERE id = ?',
		array($network_id));

	$running = db_fetch_cell_prepared('SELECT count(*)
		FROM automation_processes
		WHERE network_id = ?',
		array($network_id));

	$name = db_fetch_cell_prepared('SELECT name
		FROM automation_networks
		WHERE id = ?',
		array($network_id));

	$poller_id = db_fetch_cell_prepared('SELECT poller_id
		FROM automation_networks
		WHERE id = ?',
		array($network_id));

	if ($enabled == 'on') {
		if (!$running) {
			if ($config['poller_id'] == $poller_id) {
				$argv = array('-q', read_config_option('path_webroot') . '/poller_automation.php', '--network=' . (int)$network_id, '--force');
				if ($discover_debug) {
					$argv[] = '--debug';
				}
				exec_background(read_config_option('path_php_binary'), $argv);
			} else {
				$args_debug = ($discover_debug) ? '&debug=true' : '';

				$url = $config['url_path'] . 'remote_agent.php?action=discover&network=' . $network_id . $args_debug;

				$response = call_remote_data_collector($poller_id, $url, 'AUTOM8');
			}
		} else {
			$_SESSION['automation_message'] = __esc('Can Not Restart Discovery for Discovery in Progress for Network \'%s\'', $name);
			raise_message('automation_message');
		}
	} else {
		$_SESSION['automation_message'] = __esc('Can Not Perform Discovery for Disabled Network \'%s\'', $name);
		raise_message('automation_message');
	}

	force_session_data();
}

/* ------------------------
    The 'actions' function
   ------------------------ */

function form_actions() {
	global $network_actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action');
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));
		if ($selected_items != false) {
			if (get_nfilter_request_var('drp_action') == '1') { /* delete */
				foreach($selected_items as $item) {
					api_networks_remove($item);
				}
			} elseif (get_nfilter_request_var('drp_action') == '3') { /* enable */
				foreach($selected_items as $item) {
					api_networks_enable($item);
				}
			} elseif (get_nfilter_request_var('drp_action') == '2') { /* disable */
				foreach($selected_items as $item) {
					api_networks_disable($item);
				}
			} elseif (get_nfilter_request_var('drp_action') == '4') { /* run now */
				$discover_debug = isset_request_var('discover_debug');

				foreach($selected_items as $item) {
					api_networks_discover($item, $discover_debug);
				}

				sleep(2);
			} elseif (get_nfilter_request_var('drp_action') == '5') { /* cancel */
				foreach($selected_items as $item) {
					api_networks_cancel($item);
				}
			}
		}

		header('Location: automation_networks.php?header=false');

		exit;
	}

	/* setup some variables */
	$networks_list = ''; $i = 0;

	/* loop through each of the device types selected on the previous page and get more info about them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$networks_info = db_fetch_row_prepared('SELECT name FROM automation_networks WHERE id = ?', array($matches[1]));

			if (!cacti_sizeof($networks_info)) {
				continue;
			}

			$networks_list .= '<li>' . html_escape($networks_info['name']) . '</li>';
			$networks_array[$i] = $matches[1];
		}

		$i++;
	}

	top_header();

	form_start('automation_networks.php');

	html_start_box(escape_page_action($network_actions, get_nfilter_request_var('drp_action')), '60%', '', '3', 'center', '');

	if (get_nfilter_request_var('drp_action') == '1') { /* delete */
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to delete the following Network(s).') . "</p>
				<div class='itemlist'><ul>$networks_list</ul></div>
			</td>
		</tr>";
	} elseif (get_nfilter_request_var('drp_action') == '3') { /* enable */
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to enable the following Network(s).') . "</p>
				<div class='itemlist'><ul>$networks_list</ul></div>
			</td>
		</tr>";
	} elseif (get_nfilter_request_var('drp_action') == '2') { /* disable */
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to disable the following Network(s).') . "</p>
				<div class='itemlist'><ul>$networks_list</ul></div>
			</td>
		</tr>";
	} elseif (get_nfilter_request_var('drp_action') == '4') { /* discover now */
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to discover the following Network(s).') . "</p>
				<div class='itemlist'><ul>$networks_list</ul></div>
				<p><input type='checkbox' id='discover_debug' name='discover_debug' value='1'>
				<label id='discover_debug_label' for='discover_debug'>" . __('Run discover in debug mode') . "</label></p>
			</td>
		</tr>";
	} elseif (get_nfilter_request_var('drp_action') == '5') { /* cancel discovery now */
		print "<tr>
			<td class='textArea'>
				<p>" . __('Click \'Continue\' to cancel on going Network Discovery(s).') . "</p>
				<div class='itemlist'><ul>$networks_list</ul></div>
			</td>
		</tr>";
	}

	if (!isset($networks_array)) {
		raise_message(40);
		header('Location: automation_networks.php?header=false');
		exit;
	} else {
		$save_html = "<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' name='save'>";
	}

	print "<tr>
		<td colspan='2' class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='" . (isset($networks_array) ? serialize($networks_array) : '') . "'>
			<input type='hidden' name='drp_action' value='" . html_escape(get_nfilter_request_var('drp_action')) . "'>" . ($save_html != '' ? "
			<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' name='cancel' value='" . __esc('Cancel') . "'>
			$save_html" : "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' name='cancel' value='" . __esc('Return') . "'>") . "
		</td>
	</tr>";

	html_end_box();

	form_end();

	bottom_footer();
}

function get_networks(&$sql_where, $rows, $apply_limits = true) {
	if (get_request_var('filter') != '') {
		$sql_where = ' WHERE (automation_networks.name LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	}

	$sql_order = get_order_string();

	if ($apply_limits) {
		$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;
	} else {
		$sql_limit = '';
	}

	$query_string = "SELECT automation_networks.*, poller.name AS data_collector
		FROM automation_networks
		LEFT JOIN poller
		ON automation_networks.poller_id=poller.id
		$sql_where
		$sql_order
		$sql_limit";

	return db_fetch_assoc($query_string);
}

function networks() {
	global $network_actions;

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

	validate_store_request_vars($filters, 'sess_networks');
	/* ================= input validation ================= */

	$refresh['page']    = 'automation_networks.php?header=false';
	$refresh['seconds'] = get_request_var('refresh');
	$refresh['logout']  = 'false';

	set_page_refresh($refresh);

	if (get_request_var('rows') == -1) {
		$rows = read_config_option('num_rows_table');
	} elseif (get_request_var('rows') == -2) {
		$rows = 99999999;
	} else {
		$rows = get_request_var('rows');
	}

	html_start_box(__('Network Filters'), '100%', '', '3', 'center', 'app.php/automation/networks/new');
	networks_filter();
	html_end_box();

	$sql_where = '';

	$networks = get_networks($sql_where, $rows);

	$total_rows = db_fetch_cell('SELECT COUNT(*) FROM automation_networks ' . $sql_where);

	$nav = html_nav_bar('automation_networks.php', MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 14, __('Networks'), 'page', 'main');

	form_start('automation_networks.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$sched_types = array(
		'1' => __('Manual'),
		'2' => __('Daily'),
		'3' => __('Weekly'),
		'4' => __('Monthly'),
		'5' => __('Monthly on Day')
	);

	$display_text = array(
		'name'           => array('display' => __('Network Name'), 'align' => 'left', 'sort' => 'ASC'),
		'data_collector' => array('display' => __('Data Collector'), 'align' => 'left', 'sort' => 'DESC'),
		'sched_type'     => array('display' => __('Schedule'), 'align' => 'left', 'sort' => 'DESC'),
		'total_ips'      => array('display' => __('Total IPs'), 'align' => 'right', 'sort' => 'DESC'),
		'nosort1'        => array('display' => __('Status'), 'align' => 'right', 'sort' => 'DESC', 'tip' => __('The Current Status of this Networks Discovery')),
		'nosort2'        => array('display' => __('Progress'), 'align' => 'right', 'sort' => 'DESC', 'tip' => __('Pending/Running/Done')),
		'nosort3'        => array('display' => __('Up/SNMP Hosts'), 'align' => 'right', 'sort' => 'DESC'),
		'threads'        => array('display' => __('Threads'), 'align' => 'right', 'sort' => 'DESC'),
		'last_runtime'   => array('display' => __('Last Runtime'), 'align' => 'right', 'sort' => 'ASC'),
		'nosort4'        => array('display' => __('Next Start'), 'align' => 'right', 'sort' => 'ASC'),
		'last_started'   => array('display' => __('Last Started'), 'align' => 'right', 'sort' => 'ASC'));

	$status = 'Idle';

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	if (cacti_sizeof($networks)) {
		foreach ($networks as $network) {
			if ($network['enabled'] == '') {
				$mystat   = "<span class='disabled'>" . __('Disabled') . "</span>";
				$progress = "0/0/0";
				$status   = array();
				$updown['up'] = $updown['snmp'] = '0';
			} else {
				$running = db_fetch_cell_prepared('SELECT COUNT(*)
					FROM automation_processes
					WHERE network_id = ?
					AND status != "done"',
					array($network['id']));

				if ($running > 0) {
					$status = db_fetch_row_prepared('SELECT
						COUNT(*) AS total,
						SUM(CASE WHEN status=0 THEN 1 ELSE 0 END) AS pending,
						SUM(CASE WHEN status=1 THEN 1 ELSE 0 END) AS running,
						SUM(CASE WHEN status=2 THEN 1 ELSE 0 END) AS done
						FROM automation_ips
						WHERE network_id = ?', array($network['id']));

					$mystat   = "<span class='running'>" . __('Running') . "</span>";

					if (empty($status['total'])) {
						$progress = "0/0/0";
					} else {
						$progress = $status['pending'] . '/' . $status['running'] . '/' . $status['done'];
					}

					$updown = db_fetch_row_prepared("SELECT SUM(up_hosts) AS up, SUM(snmp_hosts) AS snmp
						FROM automation_processes
						WHERE network_id = ?", array($network['id']));

					if (empty($updown['up'])) {
						$updown['up']   = 0;
						$updown['snmp'] = 0;
					}
				} else {
					db_execute_prepared('DELETE FROM automation_processes
						WHERE network_id = ?',
						array($network['id']));

					$updown['up']   = $network['up_hosts'];
					$updown['snmp'] = $network['snmp_hosts'];

					$mystat   = "<span class='idle'>" . __('Idle') . "</span>";
					$progress = "0/0/0";
				}
			}

			form_alternate_row('line' . $network['id'], true);
			form_selectable_cell('<a class="linkEditMain" href="' . html_escape('app.php/automation/networks/' . $network['id'] . '/edit') . '">' . html_escape($network['name']) . '</a>', $network['id']);
			form_selectable_ecell($network['data_collector'], $network['id']);
			form_selectable_cell($sched_types[$network['sched_type']], $network['id']);
			form_selectable_cell(number_format_i18n($network['total_ips']), $network['id'], '', 'right');
			form_selectable_cell($mystat, $network['id'], '', 'right');
			form_selectable_cell($progress, $network['id'], '', 'right');
			form_selectable_cell(number_format_i18n($updown['up']) . '/' . number_format_i18n($updown['snmp']), $network['id'], '', 'right');
			form_selectable_cell(number_format_i18n($network['threads']), $network['id'], '', 'right');
			form_selectable_cell(round($network['last_runtime'],2), $network['id'], '', 'right');
			form_selectable_cell($network['enabled'] == '' || $network['sched_type'] == '1' ? __('N/A'):($network['next_start'] == '0000-00-00 00:00:00' ? substr($network['start_at'],0,16):substr($network['next_start'],0,16)), $network['id'], '', 'right');
			form_selectable_cell($network['last_started'] == '0000-00-00 00:00:00' ? __('Never'):substr($network['last_started'],0,16), $network['id'], '', 'right');
			form_checkbox_cell($network['name'], $network['id']);
			form_end_row();
		}
	} else {
		print "<tr class='tableRow'><td colspan='" . (cacti_sizeof($display_text)+1) . "'><em>" . __('No Networks Found') . "</em></td></tr>";
	}
	html_end_box(false);

	if (cacti_sizeof($networks)) {
		/* put the nav bar on the bottom as well */
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($network_actions);

	form_end();
}

function networks_filter() {
	global $item_rows;

	?>
	<tr class='even'>
		<td>
			<form id='networks' action='automation_networks.php'>
			<table class='filterTable'>
				<tr>
					<td>
						<?php print __('Search');?>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' size='25' value='<?php print html_escape_request_var('filter');?>'>
					</td>
					<td>
						<?php print __('Networks');?>
					</td>
					<td>
						<select id='rows'>
							<option value='-1'<?php if (get_request_var('rows') == '-1') {?> selected<?php }?>><?php print __('Default');?></option>
							<?php
							if (cacti_sizeof($item_rows)) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . $value . "</option>";
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
							<input type='button' class='ui-button ui-corner-all ui-widget' id='go' title='<?php print __esc('Search');?>' value='<?php print __esc('Go');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' title='<?php print __esc('Clear Filtered');?>' value='<?php print __esc('Clear');?>'>
						</span>
					</td>
				</tr>
			</table>
			</form>
			<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
			function applyFilter() {
				strURL  = '?rows=' + $('#rows').val();
				strURL += '&filter=' + $('#filter').val();
				strURL += '&refresh=' + $('#refresh').val();
				strURL += '&header=false';

				loadPageNoHeader(strURL);
			}

			$('#network, #refresh').on('change', function() {
				applyFilter();
			});

			function clearFilter() {
				strURL = '?clear=true&header=false';

				loadPageNoHeader(strURL);
			}

			$(function() {
				$('#go').on('click', function() {
					applyFilter();
				});

				$('#clear').on('click', function() {
					clearFilter();
				});

				$('#networks').on('submit', function(event) {
					event.preventDefault();
					applyFilter();
				});
			});
			</script>
		</td>
	</tr>
	<?php
}
