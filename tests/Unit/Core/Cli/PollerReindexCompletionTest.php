<?php
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

namespace PollerReindexCompletionTest;

function set_config_option($name, $value) {
	$GLOBALS['completion_setting'] = $GLOBALS['completion_write_succeeds'] ? $value : null;
}

function db_fetch_cell_prepared($sql, $params = array()) {
	return $GLOBALS['completion_setting'];
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/poller_reindex_hosts.php');

if ($source === false || preg_match('/^function poller_reindex_record_completion_time\(.*?^}\R/ms', $source, $matches) !== 1) {
	throw new \RuntimeException('Unable to extract poller_reindex_record_completion_time() from cli/poller_reindex_hosts.php');
}

eval('namespace PollerReindexCompletionTest;' . $matches[0]); // nosemgrep: php.lang.security.eval-use.eval-use

test('reindex completion succeeds only after the timestamp is stored', function () {
	$GLOBALS['completion_write_succeeds'] = false;
	$GLOBALS['completion_setting'] = null;
	expect(poller_reindex_record_completion_time(123))->toBeFalse();

	$GLOBALS['completion_write_succeeds'] = true;
	expect(poller_reindex_record_completion_time(123))->toBeTrue();

	$GLOBALS['completion_write_succeeds'] = false;
	$GLOBALS['completion_setting'] = 122;
	expect(poller_reindex_record_completion_time(123))->toBeFalse();
});
