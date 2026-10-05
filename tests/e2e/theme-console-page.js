/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2026 The Kadupul project and contributors                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
*/

const themes = ['classic', 'dark', 'modern', 'paper-plane', 'paw', 'sunrise', 'midwinter'];

// Same order as html_common_header() in lib/html.php.
const themeSheets = ['jquery.zoom.css', 'jquery-ui.css', 'default/style.css', 'jquery.multiselect.css',
  'jquery.multiselect.filter.css', 'jquery.timepicker.css', 'jquery.colorpicker.css', 'billboard.css',
  'pace.css', 'Diff.css'];

function sheets(theme) {
  return themeSheets.map(file => `/include/themes/${theme}/${file}`)
    .concat(['/include/fa/css/all.css', '/include/vendor/flag-icons/css/flag-icons.css',
      `/include/themes/${theme}/main.css`])
    .map(href => `<link href='${href}' type='text/css' rel='stylesheet'>`)
    .join('\n');
}

function rows() {
  let html = '';
  for (let i = 1; i <= 200; i++) {
    html += `<tr class='selectable tableRow ${i % 2 ? 'odd' : 'even'}${i === 2 ? ' selected' : ''}' id='line${i}'>` +
      `<td><a class='linkEditMain' href='#'>Device ${i}</a></td><td>192.0.2.${i % 250}</td>` +
      `<td class='right'>${i}</td><td class='checkbox'><input type='checkbox' id='chk_${i}'` +
      `${i === 2 ? ' checked' : ''}><label for='chk_${i}'></label></td></tr>`;
  }
  return html;
}

/*
 * A console page as include/top_header.php and html_start_box() render it,
 * as it stands once layout.js has shown the navigation and main areas.
 */
function consolePage(theme, skipLink) {
  return `<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='utf-8'>
<title>Devices</title>
<link rel='icon' href='data:,'>
${sheets(theme)}
</head>
<body>
	${skipLink}
	<div id='cactiPageHead' class='cactiPageHead' role='banner'>
		<div id='tabs'><div class='maintabs'><nav><ul role='tablist'>
			<li><a id='tab-console' role='tab' class='lefttab selected' aria-selected='true' href='#'><span class='fa glyph_tab-console'></span><span class='text_tab-console'>Console</span></a><a id='menu-tab-console' class='maintabs-submenu' href='#'><i class='fa fa-angle-down'></i></a></li>
			<li><a id='tab-graphs' role='tab' class='lefttab' aria-selected='false' href='#'><span class='fa glyph_tab-graphs'></span><span class='text_tab-graphs'>Graphs</span></a><a id='menu-tab-graphs' class='maintabs-submenu' href='#'><i class='fa fa-angle-down'></i></a></li>
		</ul></nav></div></div>
		<div class='cactiGraphHeaderBackground' style='display:none'><div id='gtabs'></div></div>
		<div class='cactiConsolePageHeadBackdrop'></div>
	</div>
	<div id='breadCrumbBar' class='breadCrumbBar'>
		<div id='navBar' class='navBar'><ul id='breadcrumbs'><li><a id='nav_0' href='#'>Console</a>&rsaquo;</li><li><a id='nav_1' href='#'>Devices</a></li></ul></div>
		<div class='scrollBar'></div>
		<div class='infoBar'><div class='user usermenuup'>Logged in as <span><a href='#' class='usermenu'>admin</a></span></div></div>
	</div>
	<div class='cactiShadow'></div>
	<div id='cactiContent' class='cactiContent'>
		<div class='cactiConsoleNavigationArea' id='navigation'>
			<table role='presentation' style='width:100%;'>
				<tr><td><ul id='nav' role='menu'><li class='menuitem' role='menuitem' aria-haspopup='true' id='management'><a class='menu_parent active' href='#'><i class='menu_glyph fa fa-home'></i><span>Management</span></a>
					<ul role='menu' id='management_div' style='display:block;'><li><a role='menuitem' class='pic selected' href='#'>Devices</a></li><li><a role='menuitem' class='pic' href='#'>Sites</a></li></ul></li></ul></td></tr>
				<tr><td style='text-align:center;'><a class='cactiLogo pic' href='#'></a></td></tr>
			</table>
		</div>
		<div id='navigation_right' class='cactiConsoleContentArea'>
			<main style='position:relative;display:block;' id='main'>
				<div class='cactiTable' style='width:100%;text-align:left;'>
					<div><div class='cactiTableTitle'><span>Devices</span></div><div class='cactiTableButton'></div></div>
					<table class='filterTable'><tr><td>Search</td><td><input type='text' class='ui-state-default ui-corner-all' id='rfilter' size='30' value=''></td>
					<td><span><label class='checkboxSwitch' title='Enabled'><input type='checkbox' id='on' checked><span class='checkboxSlider checkboxRound'></span></label>
					<label class='checkboxSwitch' title='Disabled'><input type='checkbox' id='off'><span class='checkboxSlider checkboxRound'></span></label></span></td>
					<td><label class='radioSwitch'><input value='1' type='radio' id='r1' name='r' checked><span class='radioSlider radioRound'></span></label>
					<label class='radioSwitch'><input value='2' type='radio' id='r2' name='r'><span class='radioSlider radioRound'></span></label></td>
					<td><span><input type='button' class='ui-button ui-corner-all ui-widget' id='go' value='Go'></span></td></tr></table>
				</div>
				<div class='cactiTable' style='width:100%;text-align:left;'>
					<table id='host2_child' class='cactiTable'>
						<tr class='tableHeader'><th class='tableSubHeaderColumn'>Device Description</th><th class='tableSubHeaderColumn'>Hostname</th><th class='tableSubHeaderColumn right'>ID</th><th class='tableSubHeaderCheckAll'><input type='checkbox' id='selectall'><label for='selectall'></label></th></tr>
						${rows()}
					</table>
				</div>
			</main>
		</div>
	</div>
	<div class='pace pace-active'><div class='pace-progress' data-progress-text='50%' data-progress='50' style='transform: translate3d(50%, 0px, 0px);'><div class='pace-progress-inner'></div></div><div class='pace-activity'></div></div>
</body>
</html>`;
}

module.exports = { themes, consolePage };
