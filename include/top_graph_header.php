<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

global $menu, $config;
$using_guest_account = false;

$page_title = api_plugin_hook_function('page_title', draw_navigation_text('title'));

if (!isset_request_var('headercontent')) {?>
<!DOCTYPE html>
<html lang='<?php print CACTI_LOCALE;?>'>
<head>
	<?php html_common_header($page_title);?>
</head>
<body>
	<a class='skip-link' href='#main' style='display:none'>Skip to main</a>
	<div id='cactiPageHead' class='cactiPageHead' role='banner'>
		<div id='tabs'><?php html_show_tabs_left();?></div>
		<div class='cactiGraphHeaderBackground'><div id='gtabs'><?php print html_graph_tabs_right();?></div></div>
		<div class='cactiConsolePageHeadBackdrop' style='display:none;'></div>
	</div>
	<div id='breadCrumbBar' class='breadCrumbBar'>
		<div id='navBar' class='navBar'><?php echo draw_navigation_text();?></div>
		<div class='scrollBar'></div>
		<?php if (read_config_option('auth_method') != 0) {?><div class='infoBar'><?php echo draw_login_status($using_guest_account);?></div><?php }?>
	</div>
	<div class='cactiShadow'></div>
	<?php } else { ?>
	<div id='navBar' class='navBar'><?php echo draw_navigation_text();?></div>
	<title><?php print $page_title;?></title>
	<?php } ?>
	<div id='cactiContent' class='cactiContent'>
		<?php if (get_current_page() == 'graph_view.php' && (get_nfilter_request_var('action') == 'tree' || (isset_request_var('view_type') && get_nfilter_request_var('view_type') == 'tree'))) { ?>
		<div style='display:none;' id='navigation' class='cactiTreeNavigationArea'><?php grow_dhtml_trees();?></div>
		<?php } ?>
		<div id='navigation_right' class='cactiGraphContentArea'>
			<main style='position:relative;display:none;' id='main'>
