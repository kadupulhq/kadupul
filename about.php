<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

include('./include/auth.php');

top_header();

html_start_box(__('About Kadupul'), '100%', '', '3', 'center', '');

?>

<tr class='tableHeader'>
	<td class='tableSubHeaderColumn' colspan='2'>
		<font class='textSubHeaderDark'><?php print get_cacti_version_text(); ?></font>
	</td>
</tr>
<tr>
	<td valign='top' class='odd' class='textArea'>
		<p><?php print __('Kadupul is an independent open-source project for network monitoring and time-series graphing.'); ?></p>

		<p><?php print __('Development, contributions, and release decisions are managed by the %sKadupul organization%s.', '<a href="https://github.com/kadupulhq">', '</a>'); ?></p>

		<p><?php print __('For documentation and support, visit the %sKadupul project%s.', '<a href="https://github.com/kadupulhq/kadupul">', '</a>'); ?></p>

		<strong><?php print __('License'); ?></strong><br>

		<p><?php print __('Kadupul is licensed under the GNU GPL:'); ?></p>

		<p><tt><?php print __('This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.');?></tt></p>

		<p><tt><?php print __('This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.'); ?></tt></p>
	</td>
</tr>

<?php
html_end_box();

bottom_footer();


