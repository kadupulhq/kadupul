<?php
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

print "\t\t\t</main>\n\t\t</div>\n\t</div>\n";
if (!isset_request_var('pagecontent')) {
	api_plugin_hook('page_bottom');
	print "\t</body>\n</html>\n";
}

