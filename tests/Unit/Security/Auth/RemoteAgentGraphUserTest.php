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

/*
 * rrdtool_function_graph() checks graph permissions only for a user id
 * above zero. graph_json.php already forwards the logged in user to the
 * remote_agent graph_json action; graph_image.php must do the same so a
 * remote graph image gets the check its local path applies. Both keep the
 * isset() guard, so a request without a session user is sent as before.
 */

dataset('remote graph callers', array('graph_json.php', 'graph_image.php'));

test('graph endpoints forward the session user to remote_agent', function ($file) {
	$source = file_get_contents(dirname(__DIR__, 4) . '/' . $file);

	$remote  = strpos($source, "remote_agent.php?action=graph_json");
	$guard   = strrpos(substr($source, 0, $remote), "if (isset(\$_SESSION['sess_user_id'])) {");
	$forward = strpos($source, "\$graph_data_array['effective_user'] = \$_SESSION['sess_user_id'];", (int) $guard);

	expect($remote)->not->toBeFalse()
		->and($guard)->not->toBeFalse()
		->and($forward)->not->toBeFalse()
		->and($forward)->toBeLessThan($remote);
})->with('remote graph callers');

test('remote_agent still renders a request without an effective user', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/remote_agent.php');

	preg_match('/^function get_graph_data\(.*?^}\n/ms', $source, $match);

	expect($match[0])->not->toContain('GRAPH ACCESS DENIED')
		->and($match[0])->toContain('print @rrdtool_function_graph($local_graph_id, $rra_id, $graph_data_array, null, $xport_options, $user);');
});
