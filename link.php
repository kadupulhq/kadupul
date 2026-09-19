<?php

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include_once('./include/global.php');

$page = db_fetch_row_prepared(
    'SELECT
	id, title, style, contentfile, enabled, refresh
	FROM external_links AS el
	WHERE id = ?',
    array(get_filter_request_var('id'))
);

if (!cacti_sizeof($page)) {
    raise_message('page_not_defined');
    header('Location: index.php');
    exit;
} else {
    global $link_nav;

    if (is_realm_allowed($page['id'] + 10000)) {
        unset($refresh);

        if (!empty($page['refresh'])) {
            $refresh['seconds'] = $page['refresh'];
            $refresh['page']    = $config['url_path'] . 'link.php?id=' . get_request_var('id');
        }

        if ($page['style'] == 'TAB') {
            $link_nav['link.php:']['title']   = $page['title'];
            $link_nav['link.php:']['mapping'] = '';
            general_header();
        } else {
            $link_nav['link.php:']['title']   = $page['title'];
            $link_nav['link.php:']['mapping'] = 'index.php:';
            top_header();
        }

        if (preg_match('/^((((ht|f)tp(s?))\:\/\/){1}\S+)/i', $page['contentfile'])) {
            if (filter_var($page['contentfile'], FILTER_VALIDATE_URL)) {
                print '<iframe id="content" src="' . html_escape($page['contentfile']) . '" sandbox="allow-scripts allow-popups allow-forms" frameborder="0"></iframe>';
            } else {
                $message = __esc("External Link ID '%s' with Title '%s' attempted to inject an invalid URL and was blocked!", $page['id'], $page['title']);
                cacti_log($message, false, 'SECURITY');
                raise_message('invalid_url', $message, MESSAGE_LEVEL_ERROR);
            }
        } else {
            print '<div id="content">';

            $basepath = $config['base_path'] . '/include/content';
            $file     = realpath($basepath . '/' . $page['contentfile']);

            if ($file !== false && substr($file, 0, strlen($basepath)) == $basepath) {
                include_once($file);
            } else {
                print '<h1>The file \'' . html_escape($page['contentfile']) . '\' does not exist!!</h1>';
            }

            print '</div>';
        }

        bottom_footer();
    } else {
        raise_message('permission_denied');
        header('Location: index.php');
        exit;
    }
}
