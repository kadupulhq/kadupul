<?php
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

include('./include/auth.php');
include_once('./lib/api_data_source.php');
include_once('./lib/poller.php');
include_once('./lib/template.php');
include_once('./lib/utility.php');

$di_actions = array(
	1 => __('Delete'),
	2 => __('Duplicate')
);

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		form_save();

		break;
	case 'actions':
		/* Without selected_items this only renders the confirmation page. */
		if (isset_request_var('selected_items')) {
			csrf_require_post(true);
		}

		form_actions();

		break;
	case 'field_remove_confirm':
		field_remove_confirm();

		break;
	case 'field_remove':
		csrf_require_post(true);
		data_input_require_removable_field('field_remove');

		field_remove();

		header('Location: data_input.php?header=false&action=edit&id=' . get_filter_request_var('data_input_id'));

		break;
	case 'field_edit':
		top_header();

		field_edit();

		bottom_footer();

		break;
	case 'whitelist_update':
		/* csrf-magic only validates the token on POST. A GET to this
		 * action would bypass the token check and let a CSRF gadget
		 * trigger the worker below. The UI uses loadPageUsingPost
		 * so a POST is the only legitimate caller. */
		if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
			cacti_log('WARNING: Rejected non-POST request to data_input.php?action=whitelist_update', false, 'AUTH');

			header('Location: data_input.php?header=false&action=edit&id=' . get_filter_request_var('id'));
			exit;
		}

		$id = get_filter_request_var('id');

		$output = array();
		$status = cacti_exec(read_config_option('path_php_binary'), array(
			'-q', $config['base_path'] . '/cli/input_whitelist.php',
			'--update', '--push', '--id=' . (int) $id
		), $output, null);

		$level = $status === 0 ? MESSAGE_LEVEL_INFO : MESSAGE_LEVEL_ERROR;
		raise_message('whitelist_updated', html_escape(implode("\n", $output)), $level);

		/* fall through */
	case 'edit':
		top_header();
		data_edit();
		bottom_footer();

		break;
	default:
		top_header();
		data();
		bottom_footer();

		break;
}

/**
 * form_save - Saves the data input method
 */
function form_save() {
	global $config, $registered_cacti_names;

	if (isset_request_var('save_component_data_input')) {
		/* ================= input validation ================= */
		get_filter_request_var('id');
		/* ==================================================== */

		$save['id']           = get_nfilter_request_var('id');
		$save['hash']         = get_hash_data_input(get_nfilter_request_var('id'));
		$save['name']         = form_input_validate(get_nfilter_request_var('name'), 'name', '', false, 3);
		$save['input_string'] = form_input_validate(get_nfilter_request_var('input_string'), 'input_string', '', true, 3);
		$save['type_id']      = form_input_validate(get_nfilter_request_var('type_id'), 'type_id', '^[0-9]+$', true, 3);

		// Reject shell metacharacters outside of <placeholder> markers to prevent command injection
		if (!is_error_message()) {
			if (!cacti_input_string_is_safe($save['input_string'])) {
				raise_message('validation_error', __('Input string contains dangerous shell characters'), MESSAGE_LEVEL_ERROR);
				header('Location: data_input.php?action=edit&id=' . (empty($save['id']) ? '' : $save['id']));
				exit;
			}

			$input_string = db_fetch_cell_prepared('SELECT input_string FROM data_input WHERE id = ?', [$save['id']]);

			if ($save['id'] > 0 && $input_string != $save['input_string'] && isset($config['input_whitelist'])) {
				raise_message('whitelist_change', __('Input Whitelisting is in effect.  Changes in this Data Input Method require rerunning of the Input Whitelist CLI script (cli/input_whitelist.php) using both the --audit and --update options before Data Source will be queried again.'), MESSAGE_LEVEL_WARN);
			}
		}

		if (!is_error_message()) {
			$data_input_id = sql_save($save, 'data_input');

			if ($data_input_id) {
				data_input_save_message($data_input_id);

				/* get a list of each field so we can note their sequence of occurrence in the database */
				if (!isempty_request_var('id')) {
					db_execute_prepared('UPDATE data_input_fields SET sequence = 0 WHERE data_input_id = ?', array(get_nfilter_request_var('id')));

					generate_data_input_field_sequences(get_nfilter_request_var('input_string'), get_nfilter_request_var('id'));

					update_replication_crc(0, 'poller_replicate_data_input_fields_crc');
				}

				push_out_data_input_method($data_input_id);
			} else {
				raise_message(2);
			}
		}

		header('Location: data_input.php?header=false&action=edit&id=' . (empty($data_input_id) ? get_nfilter_request_var('id') : $data_input_id));
	} elseif (isset_request_var('save_component_field')) {
		/* ================= input validation ================= */
		get_filter_request_var('id');
		get_filter_request_var('data_input_id');
		get_filter_request_var('sequence');
		get_filter_request_var('input_output', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^(in|out)$/')));
		/* ==================================================== */

		$save['id']            = get_request_var('id');
		$save['hash']          = get_hash_data_input(get_nfilter_request_var('id'), 'data_input_field');
		$save['data_input_id'] = get_request_var('data_input_id');
		$save['name']          = form_input_validate(get_nfilter_request_var('fname'), 'fname', '', false, 3);
		$save['data_name']     = form_input_validate(get_nfilter_request_var('data_name'), 'data_name', '', false, 3);
		$save['input_output']  = get_nfilter_request_var('input_output');
		$save['update_rra']    = form_input_validate((isset_request_var('update_rra') ? get_nfilter_request_var('update_rra') : ''), 'update_rra', '', true, 3);
		$save['sequence']      = get_request_var('sequence');
		$save['type_code']     = form_input_validate((isset_request_var('type_code') ? get_nfilter_request_var('type_code') : ''), 'type_code', '', true, 3);
		$save['regexp_match']  = form_input_validate((isset_request_var('regexp_match') ? get_nfilter_request_var('regexp_match') : ''), 'regexp_match', '', true, 3);
		$save['allow_nulls']   = form_input_validate((isset_request_var('allow_nulls') ? get_nfilter_request_var('allow_nulls') : ''), 'allow_nulls', '', true, 3);

		if (!is_error_message() && $save['input_output'] == 'in' && $save['type_code'] == '' && defined('VALID_HOST_FIELDS') && preg_match('/^(?:' . VALID_HOST_FIELDS . ')$/i', $save['data_name']) === 1) {
			$_SESSION[SESS_ERROR_FIELDS]['type_code'] = 'type_code';
			raise_message('validation_error', __esc('Input field <%s> requires Special Type Code "%s".', $save['data_name'], $save['data_name']), MESSAGE_LEVEL_ERROR);
		}

		if (!is_error_message()) {
			$data_input_field_id = sql_save($save, 'data_input_fields');

			if ($data_input_field_id) {
				data_input_save_message(get_request_var('data_input_id'), 'field');

				if ((!empty($data_input_field_id)) && (get_request_var('input_output') == 'in')) {
					generate_data_input_field_sequences(db_fetch_cell_prepared('SELECT input_string FROM data_input WHERE id = ?', array(get_request_var('data_input_id'))), get_request_var('data_input_id'));
				}

				update_replication_crc(0, 'poller_replicate_data_input_fields_crc');
			} else {
				raise_message(2);
			}
		}

		if (is_error_message()) {
			header('Location: data_input.php?header=false&action=field_edit&data_input_id=' . get_request_var('data_input_id') . '&id=' . (empty($data_input_field_id) ? get_request_var('id') : $data_input_field_id) . (!isempty_request_var('input_output') ? '&type=' . get_request_var('input_output') : ''));
		} else {
			header('Location: data_input.php?header=false&action=edit&id=' . get_request_var('data_input_id'));
		}
	}
}

function data_input_save_message($data_input_id, $type = 'input') {
	$counts = db_fetch_row_prepared("SELECT
		SUM(CASE WHEN dtd.local_data_id=0 THEN 1 ELSE 0 END) AS templates,
		SUM(CASE WHEN dtd.local_data_id>0 THEN 1 ELSE 0 END) AS data_sources
		FROM data_input AS di
		LEFT JOIN data_template_data AS dtd
		ON di.id=dtd.data_input_id
		WHERE di.id = ?",
		array($data_input_id));

	if (!cacti_sizeof($counts) || !isset($counts['templates'], $counts['data_sources'])) {
		raise_message(2);
		return;
	}

	if ($counts['templates'] == 0 && $counts['data_sources'] == 0) {
		raise_message(1);
	} elseif ($counts['templates'] > 0 && $counts['data_sources'] == 0) {
		if ($type == 'input') {
			raise_message('input_save_wo_ds');
		} else {
			raise_message('input_field_save_wo_ds');
		}
	} else {
		if ($type == 'input') {
			raise_message('input_save_w_ds');
		} else {
			raise_message('input_field_save_w_ds');
		}
	}
}

/* The list hides the built-in methods and disables the checkbox of a method
   that a data template or data source collects with, but the request names the
   ids, so the delete checks again. api_data_input_remove() deletes the method
   and every value collected for its fields. */
function data_input_unused($selected_items) {
	$unused = array();

	foreach ($selected_items as $id) {
		$used = db_fetch_cell_prepared('SELECT COUNT(*)
			FROM data_template_data
			WHERE data_input_id = ?',
			array($id));

		if (empty(get_nonsystem_data_input($id))) {
			cacti_log('WARNING: Refused to delete built-in Data Input Method ' . (int) $id, false, 'AUTH');
		} elseif ($used > 0) {
			cacti_log('WARNING: Refused to delete Data Input Method ' . (int) $id . ', which ' . (int) $used . ' Data Template(s) or Data Source(s) use', false, 'AUTH');
		} else {
			$unused[] = $id;
		}
	}

	if (cacti_sizeof($unused) < cacti_sizeof($selected_items)) {
		raise_message('data_input_in_use', __('Data Input Methods that are built in or in use were not deleted.'), MESSAGE_LEVEL_ERROR);
	}

	return $unused;
}

function form_actions() {
	global $di_actions;

	/* ================= input validation ================= */
	get_filter_request_var('drp_action', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^([a-zA-Z0-9_]+)$/')));
	/* ==================================================== */

	/* if we are to save this form, instead of display it */
	if (isset_request_var('selected_items')) {
		$selected_items = sanitize_unserialize_selected_items(get_nfilter_request_var('selected_items'));

		if ($selected_items != false && get_request_var('drp_action') == '1') {
			$selected_items = data_input_unused($selected_items);
		}

		if ($selected_items != false) {
			if (get_request_var('drp_action') == '1') { // delete
				for ($i=0;($i<cacti_count($selected_items));$i++) {
					api_data_input_remove($selected_items[$i]);
				}
			} elseif (get_request_var('drp_action') == '2') { // duplicate
				for ($i=0;($i<cacti_count($selected_items));$i++) {
					api_data_input_duplicate($selected_items[$i], get_nfilter_request_var('input_title'));
				}
			}
		}

		header('Location: data_input.php?header=false');
		exit;
	}

	/* setup some variables */
	$di_list = ''; $i = 0;

	/* loop through each of the data inputs and process them */
	foreach ($_POST as $var => $val) {
		if (preg_match('/^chk_([0-9]+)$/', $var, $matches)) {
			/* ================= input validation ================= */
			input_validate_input_number($matches[1]);
			/* ==================================================== */

			$di_list .= '<li>' . html_escape(db_fetch_cell_prepared('SELECT name FROM data_input WHERE id = ?', array($matches[1]))) . '</li>';
			$di_array[$i] = $matches[1];

			$i++;
		}
	}

	top_header();

	form_start('data_input.php');

	html_start_box(escape_page_action($di_actions, get_nfilter_request_var('drp_action')), '60%', '', '3', 'center', '');

	if (isset($di_array) && cacti_sizeof($di_array)) {
		if (get_request_var('drp_action') == '1') { // delete
			$graphs = array();

			print "<tr>
				<td class='textArea' class='odd'>
					<p>" . __n('Click \'Continue\' to delete the following Data Input Method', 'Click \'Continue\' to delete the following Data Input Method', cacti_sizeof($di_array)) . "</p>
					<div class='itemlist'><ul>$di_list</ul></div>
				</td>
			</tr>\n";
		} elseif (get_request_var('drp_action') == '2') { // duplicate
			print "<tr>
				<td class='textArea'>
					<p>" . __('Click \'Continue\' to duplicate the following Data Input Method(s). You can optionally change the title format for the new Data Input Method(s).') . "</p>
                    <div class='itemlist'><ul>$di_list</ul></div>
                    <p><strong>" . __('Input Name:'). "</strong><br>"; form_text_box('input_title', '<input_title> (1)', '', '255', '30', 'text'); print "</p>
                </td>
			</tr>\n";
		}

		$save_html = "<input type='button' class='ui-button ui-corner-all ui-widget cactiReturnTo' value='" . __esc('Cancel') . "'>&nbsp;<input type='submit' class='ui-button ui-corner-all ui-widget' value='" . __esc('Continue') . "' title='" . __n('Delete Data Input Method', 'Delete Data Input Methods', cacti_sizeof($di_array)) . "'>";
	} else {
		raise_message(40);
		header('Location: data_input.php?header=none');
		exit;
	}

	$selected_items_html = (isset($di_array) ? serialize($di_array) : '');
	$selected_items_html = htmlspecialchars($selected_items_html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$selected_items_html = str_replace('`', '&#96;', $selected_items_html);
	$action_html = htmlspecialchars((string) get_nfilter_request_var('drp_action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	$action_html = str_replace('`', '&#96;', $action_html);

	print "<tr>
		<td class='saveRow'>
			<input type='hidden' name='action' value='actions'>
			<input type='hidden' name='selected_items' value='$selected_items_html'>
			<input type='hidden' name='drp_action' value='$action_html'>
			$save_html
		</td>
	</tr>\n";

	html_end_box();

	form_end();

	bottom_footer();
}

/* --------------------------
    CDEF Item Functions
   -------------------------- */

/* field_remove() deletes by field id alone. The edit page lists only the
   fields of the method it shows, never shows a built-in method, and disables
   the delete marker of an output field once a data source collects with the
   method, so check the same here. */
function data_input_require_removable_field($action) {
	$id            = get_filter_request_var('id');
	$data_input_id = get_filter_request_var('data_input_id');

	$field = db_fetch_row_prepared('SELECT input_output
		FROM data_input_fields
		WHERE id = ?
		AND data_input_id = ?',
		array($id, $data_input_id));

	if (!cacti_sizeof($field)) {
		$reason = 'is not a field of Data Input Method ' . (int) $data_input_id;
	} elseif (empty(get_nonsystem_data_input($data_input_id))) {
		$reason = 'belongs to a built-in Data Input Method';
	} else {
		$data_sources = db_fetch_cell_prepared('SELECT COUNT(*)
			FROM data_template_data
			WHERE data_input_id = ?
			AND local_data_id > 0',
			array($data_input_id));

		if ($field['input_output'] != 'out' || $data_sources == 0) {
			return;
		}

		$reason = 'is an output field of a method that ' . (int) $data_sources . ' Data Source(s) use';
	}

	cacti_log('WARNING: Rejected data_input.php?action=' . $action . ' because field ' . (int) $id . ' ' . $reason, false, 'AUTH');

	header('Location: data_input.php?header=false');
	exit;
}

function field_remove_confirm() {
	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('data_input_id');
	/* ==================================================== */

	$field = db_fetch_row_prepared('SELECT *
		FROM data_input_fields
		WHERE id = ?',
		array(get_request_var('id')));

	if (!cacti_sizeof($field)) {
		raise_message(2);
		return;
	}

	form_start('data_input.php?action=edit&id' . get_request_var('data_input_id'));

	html_start_box('', '100%', '', '3', 'center', '');

	?>
	<tr>
		<td class='topBoxAlt'>
			<p><?php print __('Click \'Continue\' to delete the following Data Input Field.');?></p>
			<p><?php print __esc('Field Name: %s', $field['data_name']);?><br>
			<p><?php print __esc('Friendly Name: %s', $field['name']);?><br>
		</td>
	</tr>
	<tr>
		<td class='right'>
			<input type='button' class='ui-button ui-corner-all ui-widget' id='cancel' value='<?php print __esc('Cancel');?>' name='cancel'>
			<input type='button' class='ui-button ui-corner-all ui-widget' id='continue' value='<?php print __esc('Continue');?>' name='continue' title='<?php print __esc('Remove Data Input Field');?>'>
		</td>
	</tr>
	<?php

	html_end_box();

	form_end();

	?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>
	$(function() {
		$('#continue').on('click', function(data) {
			$.post('data_input.php?action=field_remove', {
				__csrf_magic: csrfMagicToken,
				data_input_id: <?php print get_request_var('data_input_id');?>,
				id: <?php print get_request_var('id');?>
			}, function(data) {
				loadPageNoHeader('data_input.php?action=edit&header=false&id=<?php print get_request_var('data_input_id');?>');
			});
		});
	});
	</script>
	<?php
}

function field_remove() {
	global $registered_cacti_names;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('data_input_id');
	/* ==================================================== */

	/* get information about the field we're going to delete so we can re-order the seqs */
	$field = db_fetch_row_prepared('SELECT input_output, data_input_id
		FROM data_input_fields
		WHERE id = ?',
		array(get_request_var('id')));

	if (!cacti_sizeof($field)) {
		raise_message(2);
		return;
	}

	db_execute_prepared('DELETE FROM data_input_fields WHERE id = ?', array(get_request_var('id')));
	db_execute_prepared('DELETE FROM data_input_data WHERE data_input_field_id = ?', array(get_request_var('id')));

	/* when a field is deleted; we need to re-order the field sequences */
	if (($field['input_output'] == 'in') && (preg_match_all('/<([_a-zA-Z0-9]+)>/', db_fetch_cell_prepared('SELECT input_string FROM data_input WHERE id = ?', array($field['data_input_id'])), $matches))) {
		$j = 0;
		for ($i=0; ($i < cacti_count($matches[1])); $i++) {
			if (in_array($matches[1][$i], $registered_cacti_names) == false) {
				$j++;
				db_execute_prepared("UPDATE data_input_fields SET sequence = ? WHERE data_input_id = ? AND input_output = 'in' AND data_name = ?", array($j, $field['data_input_id'], $matches[1][$i]));
			}
		}
	}

	update_replication_crc(0, 'poller_replicate_data_input_fields_crc');
}

function field_edit() {
	global $registered_cacti_names, $fields_data_input_field_edit_1, $fields_data_input_field_edit_2, $fields_data_input_field_edit;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	get_filter_request_var('data_input_id');
	get_filter_request_var('type', FILTER_VALIDATE_REGEXP, array('options' => array('regexp' => '/^(in|out)$/')));
	/* ==================================================== */

	$array_field_names = array();

	if (!isempty_request_var('id')) {
		$field = db_fetch_row_prepared('SELECT *
			FROM data_input_fields
			WHERE id = ?',
			array(get_request_var('id')));

		if (!cacti_sizeof($field)) {
			raise_message(2);
			return;
		}
	}

	if (!isempty_request_var('type')) {
		$current_field_type = get_request_var('type');
	} else {
		if (!isset($field['input_output'])) {
			raise_message(2);
			return;
		}
		$current_field_type = $field['input_output'];
	}

	$data_input = db_fetch_row_prepared('SELECT type_id, name
		FROM data_input
		WHERE id = ?',
		array(get_request_var('data_input_id')));

	if (!cacti_sizeof($data_input)) {
		raise_message(2);
		return;
	}

	/* obtain a list of available fields for this given field type (input/output) */
	if (($current_field_type == 'in') && (preg_match_all('/<([_a-zA-Z0-9]+)>/', db_fetch_cell_prepared('SELECT input_string FROM data_input WHERE id = ?', array(!isempty_request_var('data_input_id') ? get_request_var('data_input_id') : $field['data_input_id'])), $matches))) {
		for ($i=0; ($i < cacti_count($matches[1])); $i++) {
			if (in_array($matches[1][$i], $registered_cacti_names) == false) {
				$current_field_name = $matches[1][$i];
				$array_field_names[$current_field_name] = $current_field_name;

				if (!isset($field)) {
					$field_id = db_fetch_cell_prepared('SELECT id FROM data_input_fields
						WHERE data_name = ?
						AND data_input_id = ?',
						array($current_field_name, get_filter_request_var('data_input_id')));

					if (!$field_id > 0) {
						$field = array();
						$field['name'] = ucwords($current_field_name);
						$field['data_name'] = $current_field_name;
					}
				}
			}
		}
	}

	/* if there are no input fields to choose from, complain */
	if ((!isset($array_field_names)) && (isset_request_var('type') ? get_request_var('type') == 'in' : false) && ($data_input['type_id'] == '1')) {
		raise_message('invalid_inputs', __('This script appears to have no input values, therefore there is nothing to add.'), MESSAGE_LEVEL_WARN);
		header('Location: data_input.php?header=false&action=edit&id=' . get_filter_request_var('data_input_id'));
		exit;
	}

	if ($current_field_type == 'out') {
		$header_name = __esc('Output Fields [edit: %s]', $data_input['name']);
		$dfield      = __('Output Field');
	} elseif ($current_field_type == 'in') {
		$header_name = __esc('Input Fields [edit: %s]', $data_input['name']);
		$dfield      = __('Input Field');
	}

	if (isset($field)) {
		$dfield .= ' ' . html_escape($field['data_name']);
	}
	form_start('data_input.php', 'data_input');

	html_start_box($header_name, '100%', true, '3', 'center', '');

	$form_array = array();

	/* field name */
	if ((($data_input['type_id'] == '1') || ($data_input['type_id'] == '5')) && ($current_field_type == 'in')) { /* script */
		$form_array = inject_form_variables($fields_data_input_field_edit_1, $dfield, $array_field_names, (isset($field) ? $field : array()));
	} elseif ($current_field_type == 'out' || ($data_input['type_id'] != 1 && $data_input['type_id'] != 5)) {
		$form_array = inject_form_variables($fields_data_input_field_edit_2, $dfield, (isset($field) ? $field : array()));
	}

	/* ONLY if the field is an input */
	if ($current_field_type == 'in') {
		unset($fields_data_input_field_edit['update_rra']);
	} elseif ($current_field_type == 'out') {
		unset($fields_data_input_field_edit['regexp_match']);
		unset($fields_data_input_field_edit['allow_nulls']);
		unset($fields_data_input_field_edit['type_code']);
	}

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => $form_array + inject_form_variables($fields_data_input_field_edit, (isset($field) ? $field : array()), $current_field_type, $_REQUEST)
		)
	);

	html_end_box(true, true);

	form_save_button('data_input.php?action=edit&id=' . get_request_var('data_input_id'));
}

/* -----------------------
    Data Input Functions
   ----------------------- */

function data_edit() {
	global $config, $fields_data_input_edit;

	/* ================= input validation ================= */
	get_filter_request_var('id');
	/* ==================================================== */

	if (!isempty_request_var('id')) {
		$data_id = get_nonsystem_data_input(get_request_var('id'));
		if ($data_id == 0 || $data_id == NULL) {
			header('Location: data_input.php');
			return;
		}

		$data_input = db_fetch_row_prepared('SELECT *
			FROM data_input
			WHERE id = ?',
			array(get_request_var('id')));

		if (!cacti_sizeof($data_input)) {
			raise_message(2);
			return;
		}

		$header_label = __esc('Data Input Method [edit: %s]', $data_input['name']);
	} else {
		$data_input = array();

		$header_label = __('Data Input Method [new]');
	}

	$whitelist_issues = false;

	if (!isset($config['input_whitelist'])) {
		unset($fields_data_input_edit['whitelist_verification']);
	}

	form_start('data_input.php', 'data_input');

	html_start_box($header_label, '100%', true, '3', 'center', '');

	if (cacti_sizeof($data_input)) {
		switch ($data_input['type_id']) {
		case DATA_INPUT_TYPE_SNMP:
			$fields_data_input_edit['type_id']['array'][DATA_INPUT_TYPE_SNMP] = __('SNMP Get');
			break;
		case DATA_INPUT_TYPE_SNMP_QUERY:
			$fields_data_input_edit['type_id']['array'][DATA_INPUT_TYPE_SNMP_QUERY] = __('SNMP Query');
			break;
		case DATA_INPUT_TYPE_SCRIPT_QUERY:
			$fields_data_input_edit['type_id']['array'][DATA_INPUT_TYPE_SCRIPT_QUERY] = __('Script Query');
			break;
		case DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER:
			$fields_data_input_edit['type_id']['array'][DATA_INPUT_TYPE_QUERY_SCRIPT_SERVER] = __('Script Server Query');
			break;
		}

		if (isset($config['input_whitelist']) && isset($data_input['hash'])) {
			$aud = verify_data_input_whitelist($data_input['hash'], $data_input['input_string']);

			if ($aud === true) {
				$fields_data_input_edit['whitelist_verification']['value'] = __('White List Verification Succeeded.');
			} elseif ($aud == false) {
				$fields_data_input_edit['whitelist_verification']['value'] = __('White List Verification Failed.  Run CLI script input_whitelist.php to correct.');

				if (is_writable(dirname($config['input_whitelist'])) && (!file_exists($config['input_whitelist']) || is_writable($config['input_whitelist']))) {
					$whitelist_issues = true;
				}
			} elseif ($aud == '-1') {
				$fields_data_input_edit['whitelist_verification']['value'] = __('Input String does not exist in White List.  Run CLI script input_whitelist.php to correct.');

				if (is_writable(dirname($config['input_whitelist'])) && (!file_exists($config['input_whitelist']) || is_writable($config['input_whitelist']))) {
					$whitelist_issues = true;
				}
			}
		}
	}

	draw_edit_form(
		array(
			'config' => array('no_form_tag' => true),
			'fields' => inject_form_variables($fields_data_input_edit, $data_input)
		)
	);

	html_end_box(true, true);

	if (!isempty_request_var('id')) {
		if (api_data_input_more_inputs(get_request_var('id'), $data_input['input_string'])) {
			$url = 'data_input.php?action=field_edit&type=in&data_input_id=' . get_request_var('id');
		} else {
			$url = '';
		}

		html_start_box(__('Input Fields'), '100%', '', '3', 'center', $url);

		print "<tr class='tableHeader'>";
		DrawMatrixHeaderItem(__('Name'), '', 1);
		DrawMatrixHeaderItem(__('Friendly Name'), '', 1);
		DrawMatrixHeaderItem(__('Field Order'), '', 2);
		print '</tr>';

		$fields = db_fetch_assoc_prepared("SELECT id, data_name, name, sequence
			FROM data_input_fields
			WHERE data_input_id = ?
			AND input_output = 'in'
			ORDER BY sequence, data_name",
			array(get_request_var('id')));

		$counts = db_fetch_row_prepared("SELECT
			SUM(CASE WHEN dtd.local_data_id=0 THEN 1 ELSE 0 END) AS templates,
			SUM(CASE WHEN dtd.local_data_id>0 THEN 1 ELSE 0 END) AS data_sources
			FROM data_input AS di
			LEFT JOIN data_template_data AS dtd
			ON di.id=dtd.data_input_id
			WHERE di.id = ?",
			array(get_request_var('id')));

		$output_disabled  = false;
		$save_alt_message = false;
		if (!cacti_sizeof($counts)) {
			$output_disabled  = false;
			$save_alt_message = false;
		} elseif ($counts['data_sources'] > 0) {
			$output_disabled  = true;
			$save_alt_message = true;
		} elseif ($counts['templates'] > 0) {
			$output_disabled  = false;
			$save_alt_message = true;
		}

		$i = 0;
		if (cacti_sizeof($fields)) {
			foreach ($fields as $field) {
				form_alternate_row('', true);
				?>
				<td>
<?php
        $automation_output_0 = 'data_input.php?action=field_edit&id='
            . $field['id']
            . '&data_input_id='
            . get_request_var('id');
        $automation_output_0 = htmlspecialchars(
            (string)$automation_output_0,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_0 = str_replace('`', '&#96;', $automation_output_0);
        $automation_output_1 = $field['data_name'];
        $automation_output_1 = htmlspecialchars(
            (string)$automation_output_1,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_1 = str_replace('`', '&#96;', $automation_output_1);
        print '<a class="linkEditMain" href="';
        print $automation_output_0;
        print '">';
        print $automation_output_1;
        print '</a>';
        ?>
				</td>
				<td>
					<?php print html_escape($field['name']);?>
				</td>
				<td>
					<?php print $field['sequence']; if ($field['sequence'] == '0') { print ' ' . __('(Not In Use)'); }?>
				</td>
				<td class="right">
<?php
        $automation_output_0 = 'data_input.php?action=field_remove_confirm&id='
            . $field['id']
            . '&data_input_id='
            . get_request_var('id');
        $automation_output_0 = htmlspecialchars(
            (string)$automation_output_0,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_0 = str_replace('`', '&#96;', $automation_output_0);
        print '<a class=\'delete deleteMarker fa fa-times\' href=\'';
        print $automation_output_0;
        print '\' title=\'';
        print __esc('Delete');
        print '\'></a>';
        ?>
				</td>
				<?php
				form_end_row();
			}
		} else {
			print '<tr><td colspan="4"><em>' . __('No Input Fields') . '</em></td></tr>';
		}
		html_end_box();

		html_start_box(__('Output Fields'), '100%', '', '3', 'center', 'data_input.php?action=field_edit&type=out&data_input_id=' . get_request_var('id'));
		print "<tr class='tableHeader'>";
		DrawMatrixHeaderItem(__('Name'),'',1);
		DrawMatrixHeaderItem(__('Friendly Name'),'',1);
		DrawMatrixHeaderItem(__('Update RRA'),'',2);
		print '</tr>';

		$fields = db_fetch_assoc_prepared("SELECT id, name, data_name, update_rra, sequence
			FROM data_input_fields
			WHERE data_input_id = ?
			AND input_output = 'out'
			ORDER BY sequence, data_name",
			array(get_request_var('id')));

		$i = 0;
		if (cacti_sizeof($fields)) {
			foreach ($fields as $field) {
				form_alternate_row('', true);
				?>
				<td>
<?php
        $automation_output_0 = 'data_input.php?action=field_edit&id='
            . $field['id']
            . '&data_input_id='
            . get_request_var('id');
        $automation_output_0 = htmlspecialchars(
            (string)$automation_output_0,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_0 = str_replace('`', '&#96;', $automation_output_0);
        $automation_output_1 = $field['data_name'];
        $automation_output_1 = htmlspecialchars(
            (string)$automation_output_1,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_1 = str_replace('`', '&#96;', $automation_output_1);
        print '<a class=\'linkEditMain\' href=\'';
        print $automation_output_0;
        print '\'>';
        print $automation_output_1;
        print '</a>';
        ?>
				</td>
				<td>
					<?php print html_escape($field['name']);?>
				</td>
				<td>
					<?php print html_boolean_friendly($field['update_rra']);?>
				</td>
				<td class='right'>
					<?php if ($output_disabled) {?>
					<a class='deleteMarkerDisabled fa fa-times' href='#' title='<?php print __esc('Output Fields can not be removed when Data Sources are present');?>'></a>
					<?php } else { ?>
<?php
        $automation_output_0 = 'data_input.php?action=field_remove_confirm&id='
            . $field['id']
            . '&data_input_id='
            . get_request_var('id');
        $automation_output_0 = htmlspecialchars(
            (string)$automation_output_0,
            ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE,
            ini_get('default_charset') ?: 'UTF-8',
            false
        );
        $automation_output_0 = str_replace('`', '&#96;', $automation_output_0);
        print '<a class=\'delete deleteMarker fa fa-times\' href=\'';
        print $automation_output_0;
        print '\' title=\'';
        print __esc('Delete');
        print '\'></a>';
        ?>
					<?php } ?>
				</td>
				<?php
				form_end_row();
			}
		} else {
			print '<tr><td colspan="4"><em>' . __('No Output Fields') . '</em></td></tr>';
		}

		html_end_box();
	}

	form_save_button('data_input.php', 'return');

	?>
	<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

	$(function() {
		var whiteList=<?php print $whitelist_issues ? 'true':'false';?>;

		$('.cdialog').remove();
		$('#main').append("<div id='cdialog' class='cdialog'></div>");

		$('.delete').on('click', function (event) {
			event.preventDefault();

			request = $(this).attr('href');
			$.get(request)
				.done(function(data) {
					$('#cdialog').html(data);

					applySkin();

					$('#cdialog').dialog({
						title: '<?php print __('Delete Data Input Field');?>',
						close: function () { $('.delete').blur(); $('.selectable').removeClass('selected'); },
						modal: false,
						minHeight: 80,
						minWidth: 500
					});
				})
				.fail(function(data) {
					getPresentHTTPError(data);
				});
		}).css('cursor', 'pointer');

		if (whiteList) {
			$('input[name="action"]').after('<input id="updateme" class="ui-button ui-corner-all ui-widget" type="button" value="<?php print __('Update Whitelist');?>" role="button" data-id="<?php print get_filter_request_var('id');?>">');

			$('#updateme').on('click', function() {
				var data = {
					action: 'whitelist_update',
					header: false,
					id: $(this).data('id'),
					__csrf_magic: csrfMagicToken
				}

				loadPageUsingPost('data_input.php', data);
			});
		}
	});

	</script>
	<?php
}

function data() {
	global $input_types, $di_actions, $item_rows;

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

	validate_store_request_vars($filters, 'sess_data_input');
	/* ================= input validation ================= */

	if (get_request_var('rows') == '-1') {
		$rows = read_config_option('num_rows_table');
	} else {
		$rows = get_request_var('rows');
	}

	html_start_box(__('Data Input Methods'), '100%', '', '3', 'center', 'data_input.php?action=edit');

	?>
	<tr class='even noprint'>
		<td class='noprint'>
		<form id='form_data_input' method='get' action='data_input.php'>
			<table class='filterTable'>
				<tr class='noprint'>
					<td>
						<label for='filter'><?php print __('Search');?></label>
					</td>
					<td>
						<input type='text' class='ui-state-default ui-corner-all' id='filter' name='filter' size='25' value='<?php
							$search_html = (string) get_request_var('filter');
							$search_html = htmlspecialchars($search_html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
							print str_replace('`', '&#96;', $search_html);
						?>'>
					</td>
					<td>
						<?php print __('Input Methods');?>
					</td>
					<td>
						<select id='rows' name='rows'>
							<option value='-1'<?php print (get_request_var('rows') == '-1' ? ' selected>':'>') . __('Default');?></option>
							<?php
							if (cacti_sizeof($item_rows) > 0) {
								foreach ($item_rows as $key => $value) {
									print "<option value='" . $key . "'"; if (get_request_var('rows') == $key) { print ' selected'; } print '>' . html_escape($value) . "</option>\n";
								}
							}
							?>
						</select>
					</td>
					<td>
						<span>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='refresh' value='<?php print __esc('Go');?>' title='<?php __esc('Set/Refresh Filters');?>'>
							<input type='button' class='ui-button ui-corner-all ui-widget' id='clear' value='<?php print __esc('Clear');?>' title='<?php __esc('Clear Filters');?>'>
						</span>
					</td>
				</tr>
			</table>
		</form>
		<script type='text/javascript' <?php print CactiSecureHeaders::getNonceAttribute();?>>

		function applyFilter() {
			strURL  = 'data_input.php?header=false';
			strURL += '&filter='+$('#filter').val();
			strURL += '&rows='+$('#rows').val();
			loadPageNoHeader(strURL);
		}

		function clearFilter() {
			strURL = 'data_input.php?clear=1&header=false';
			loadPageNoHeader(strURL);
		}

		$(function() {
			$('#refresh').on('click', function() {
				applyFilter();
			});

			$('#rows').on('change', function() {
				applyFilter();
			});

			$('#clear').on('click', function() {
				clearFilter();
			});

			$('#form_data_input').on('submit', function(event) {
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
		$sql_where = 'WHERE (di.name LIKE ' . db_qstr('%' . get_request_var('filter') . '%') . ')';
	} else {
		$sql_where = '';
	}

	$sql_where .= ($sql_where != '' ? ' AND' : 'WHERE') . " (di.hash NOT IN ('3eb92bb845b9660a7445cf9740726522', 'bf566c869ac6443b0c75d1c32b5a350e', '80e9e4c4191a5da189ae26d0e237f015', '332111d8b54ac8ce939af87a7eac0c06'))";

	$sql_where  = api_plugin_hook_function('data_input_sql_where', $sql_where);

	$total_rows = db_fetch_cell("SELECT count(*)
		FROM data_input AS di
		$sql_where");

	$sql_order = get_order_string(array('name', 'id', 'data_sources', 'templates', 'type_id'));
	$sql_limit = ' LIMIT ' . ($rows*(get_request_var('page')-1)) . ',' . $rows;

	$data_inputs = db_fetch_assoc("SELECT di.*,
		SUM(CASE WHEN dtd.local_data_id=0 THEN 1 ELSE 0 END) AS templates,
		SUM(CASE WHEN dtd.local_data_id>0 THEN 1 ELSE 0 END) AS data_sources
		FROM data_input AS di
		LEFT JOIN data_template_data AS dtd
		ON di.id=dtd.data_input_id
		$sql_where
		GROUP BY di.id
		$sql_order
		$sql_limit");

	$nav = html_nav_bar('data_input.php?filter=' . get_request_var('filter'), MAX_DISPLAY_PAGES, get_request_var('page'), $rows, $total_rows, 6, __('Input Methods'), 'page', 'main');

	form_start('data_input.php', 'chk');

	print $nav;

	html_start_box('', '100%', '', '3', 'center', '');

	$display_text = array(
		'name'         => array('display' => __('Data Input Name'),    'align' => 'left', 'sort' => 'ASC', 'tip' => __('The name of this Data Input Method.')),
		'id' => array(
			'display' => __('ID'),
			'align'   => 'right',
			'sort'    => 'ASC',
			'tip'     => __('The internal database ID for this Data Input Method.  Useful when performing automation or debugging.')
		),
		'nosort' => array(
			'display' => __('Deletable'),
			'align'   => 'right',
			'tip'     => __('Data Inputs that are in use cannot be Deleted. In use is defined as being referenced either by a Data Source or a Data Template.')
		),
		'data_sources' => array(
			'display' => __('Data Sources Using'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('The number of Data Sources that use this Data Input Method.')
		),
		'templates' => array(
			'display' => __('Templates Using'),
			'align'   => 'right',
			'sort'    => 'DESC',
			'tip'     => __('The number of Data Templates that use this Data Input Method.')
		),
		'type_id' => array(
			'display' => __('Data Input Method'),
			'align'   => 'right',
			'sort'    => 'ASC',
			'tip'     => __('The method used to gather information for this Data Input Method.')
		)
	);

	html_header_sort_checkbox($display_text, get_request_var('sort_column'), get_request_var('sort_direction'), false);

	$i = 0;
	if (cacti_sizeof($data_inputs)) {
		foreach ($data_inputs as $data_input) {
			/* hide system types */
			if ($data_input['templates'] > 0 || $data_input['data_sources'] > 0) {
				$disabled = true;
			} else {
				$disabled = false;
			}

			form_alternate_row('line' . $data_input['id'], true, $disabled);
			form_selectable_cell(filter_value($data_input['name'], get_request_var('filter'), 'data_input.php?action=edit&id=' . $data_input['id']), $data_input['id']);
			form_selectable_cell($data_input['id'], $data_input['id'], '', 'right');
			form_selectable_cell($disabled ? __('No'):__('Yes'), $data_input['id'], '', 'right');
			form_selectable_cell(number_format_i18n($data_input['data_sources'], '-1'), $data_input['id'],'', 'right');
			form_selectable_cell(number_format_i18n($data_input['templates'], '-1'), $data_input['id'],'', 'right');
			form_selectable_cell($input_types[$data_input['type_id']] ?? __('Unknown'), $data_input['id'], '', 'right');
			form_checkbox_cell($data_input['name'], $data_input['id'], $disabled);
			form_end_row();
		}
	} else {
		print '<tr class="tableRow"><td colspan="' . (cacti_sizeof($display_text)+1) . '"><em>' . __('No Data Input Methods Found') . '</em></td></tr>';
	}

	html_end_box(false);

	if (cacti_sizeof($data_inputs)) {
		print $nav;
	}

	/* draw the dropdown containing a list of available actions for this form */
	draw_actions_dropdown($di_actions);

	form_end();
}
