<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

$guest_account = true;

include('./include/auth.php');

if (isset_request_var('error')) {
    /* page[]= is not a page name, and basename() throws on an array */
    if (!is_string(get_nfilter_request_var('page'))) {
        die_html_input_error('page');
    }

    $page  = basename(get_nfilter_request_var('page'));
    $error = get_filter_request_var('error');

    if (isset($_SESSION['sess_user_id'])) {
        $user_id  = $_SESSION['sess_user_id'];
        $username = get_username($_SESSION['sess_user_id']);
    } else {
        $user_id  = 0;
        $username = 'unknown';
    }

    /* the browser reports every failed request, so a session may log one
       report every 10 seconds */
    if (!isset($_SESSION['sess_help_error_time']) || time() - $_SESSION['sess_help_error_time'] >= 10) {
        $_SESSION['sess_help_error_time'] = time();

        $message = sprintf('WARNING: Kadupul Page:%s for User:%s Generated a Fatal Error:%d', $page, $username, $error);

        cacti_log($message, false);

        /* each debounce key is a settings row, so key on a script that exists in
           Kadupul or a plugin rather than on the request value, and on the user so
           one account cannot mail the admin for every page in turn */
        $script = basename(explode('?', get_nfilter_request_var('page'))[0]);

        if (!preg_match('/^[a-z0-9_.-]+\.php$/i', $script)
            || (!file_exists($config['base_path'] . '/' . $script) && !cacti_sizeof(glob($config['base_path'] . '/plugins/*/' . $script)))) {
            $script = 'unknown';
        }

        if (debounce_claim_notification('page_error_user_' . $user_id, 300) && debounce_claim_notification('page_error_' . $script)) {
            /* the notice is HTML mail and page is the request's own value. Only
               angle brackets are escaped, so a page query string reads as before. */
            $tags = array('<' => '&lt;', '>' => '&gt;');

            admin_email(__('Kadupul System Warning'), __('WARNING: Kadupul Page:%s for User:%s Generated a Fatal Error %d!', strtr($page, $tags), strtr((string) $username, $tags), $error));
        }
    }
} elseif (isset_request_var('page')) {
    get_filter_request_var('page', FILTER_CALLBACK, array('options' => 'sanitize_search_string'));

    $page = basename(get_request_var('page'));

    header('Content-Type: application/json');

    if (read_config_option('local_documentation') != 'on') {
        require_once __DIR__ . '/lib/documentation.php';

        print json_encode(array(
            'status' => 'Success',
            'location' => cacti_documentation_url($page)
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
