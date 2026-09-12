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
*/

$guest_account = true;

include('./include/auth.php');

if (isset_request_var('error')) {
	$page  = basename(get_nfilter_request_var('page'));
	$error = get_filter_request_var('error');

	if (isset($_SESSION['sess_user_id'])) {
		$username = get_username($_SESSION['sess_user_id']);
	} else {
		$username = 'unknown';
	}

	$message = sprintf('WARNING: Kadupul Page:%s for User:%s Generated a Fatal Error:%d', $page, $username, $error);

	cacti_log($message, false);

	if (debounce_run_notification('page_error_' . $page)) {
		admin_email(__('Kadupul System Warning'), __('WARNING: Kadupul Page:%s for User:%s Generated a Fatal Error %d!', $page, $username, $error));
	}
} elseif (isset_request_var('page')) {
	get_filter_request_var('page', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));

	$page = basename(str_replace('.html', '.md', get_request_var('page')));

	header('Content-Type: application/json');

	if (read_config_option('local_documentation') != 'on') {
		print json_encode(array(
			'status' => 'Success',
			'location' => 'https://kadupul.org/map/'
		));
	} elseif (file_exists($config['base_path'] . '/docs/' . $page)) {
		print json_encode(array(
			'status' => 'Success',
			'location' => $config['url_path'] . 'docs/' . $page
		));
	} else {
		print json_encode(array(
			'status' => 'Not Reachable',
			'message' => __('The document page \'%s\' could not be reached locally.', $page)
		));
	}
}
