<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include('./include/auth.php');
include_once($config['library_path'] . '/reports.php');
include_once($config['library_path'] . '/html_reports.php');
include_once($config['library_path'] . '/timespan_settings.php');

get_filter_request_var('id');
get_filter_request_var('tree_id');
get_filter_request_var('site_id');
get_filter_request_var('host_id');
get_filter_request_var('host_template_id');
get_filter_request_var('graph_template_id');
get_filter_request_var('tab', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));

/* set a longer execution time for large reports */
ini_set('max_execution_time', '300');

/* set default action */
set_default_action();

switch (get_request_var('action')) {
	case 'save':
		reports_require_post('save');
		reports_form_save();

		break;
	case 'send':
		reports_require_post('send');
		get_filter_request_var('id');

		reports_send(get_request_var('id'));

		header('Location: ' . get_reports_page() . '?action=edit&tab=' . get_request_var('tab') . '&id=' . get_request_var('id') . '&header=false');
		break;
	case 'ajax_dnd':
		reports_require_post('ajax_dnd');
		reports_item_dnd();

		header('Location: ' . get_reports_page() . '?action=edit&tab=items&id=' . get_request_var('id') . '&header=false');
		break;
	case 'setvar':
		$changed = reports_item_validate();

		print $changed;

		break;
	case 'ajax_get_branches':
		print reports_get_branch_select(get_filter_request_var('tree_id'));

		break;
	case 'ajax_hosts':
		reports_item_validate();

		$sql_where = '';
		if (get_request_var('site_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.site_id = ' . get_request_var('site_id');
		}

		if (get_request_var('host_template_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.host_template_id = ' . get_request_var('host_template_id');
		}

		get_allowed_ajax_hosts(true, 'applyFilter', $sql_where);

        break;
	case 'ajax_graphs':
		reports_item_validate();

		$sql_where = '';
		if (get_request_var('site_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.site_id = ' . get_request_var('site_id');
		}

		if (get_request_var('host_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'gl.host_id = ' . get_request_var('host_id');
		}

		if (get_request_var('graph_template_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'gl.graph_template_id = ' . get_request_var('graph_template_id');
		}

		if (get_request_var('host_template_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.host_template_id = ' . get_request_var('host_template_id');
		}

		get_allowed_ajax_graphs($sql_where);

        break;
	case 'ajax_graph_template':
		reports_item_validate();

		$sql_where = '';
		if (get_request_var('site_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.site_id = ' . get_request_var('site_id');
		}

		if (get_request_var('host_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.id = ' . get_request_var('host_id');
		}

		if (get_request_var('host_template_id') > 0) {
			$sql_where .= ($sql_where != '' ? ' AND ':'') . 'h.host_template_id = ' . get_request_var('host_template_id');
		}

		get_allowed_ajax_graph_templates(true, true, $sql_where);

        break;
	case 'actions':
		reports_require_post('actions');
		reports_form_actions();

		break;
	case 'item_movedown':
		reports_require_post('item_movedown');
		get_filter_request_var('id');

		reports_item_movedown();

		header('Location: ' . get_reports_page() . '?action=edit&tab=items&id=' . get_request_var('id') . '&header=false');
		break;
	case 'item_moveup':
		reports_require_post('item_moveup');
		get_filter_request_var('id');

		reports_item_moveup();

		header('Location: ' . get_reports_page() . '?action=edit&tab=items&id=' . get_request_var('id') . '&header=false');
		break;
	case 'item_remove':
		reports_require_post('item_remove');
		get_filter_request_var('id');

		reports_item_remove();

		header('Location: ' . get_reports_page() . '?action=edit&tab=items&id=' . get_request_var('id') . '&header=false');
		break;
	case 'item_edit':
		general_header();
		reports_item_edit();
		bottom_footer();
		break;
	case 'edit':
		general_header();
		reports_edit();
		bottom_footer();
		break;
	default:
		general_header();
		reports();
		bottom_footer();
		break;
}
