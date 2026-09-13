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
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * 1.2.31 let getopt() drop unknown remove_graphs.php options. They are
 * reported and skipped again, but a name that looks like a mistyped filter
 * still aborts, because dropping a filter widens what the command removes.
 */

require_once __DIR__ . '/../../../../lib/maintenance_cli.php';

$unknownLongopts = array(
	'host-id:',
	'graph-template-id:',
	'host-template-id:',
	'graph-regex:',
	'all',
	'preserve',
	'quiet',
	'list',
	'list-hosts',
	'list-host-templates',
	'list-graph-templates',
	'force',
	'version',
	'help',
);

dataset('remove_graphs unknown arguments', array(
	'retired graph type'           => array('--graph-type', 'ignore'),
	'retired graph type value'     => array('--graph-type=cg', 'ignore'),
	'unrelated flag'               => array('--bogus', 'warn'),
	'unrelated flag with value'    => array('--dry-run=1', 'warn'),
	'unrelated long name'          => array('--list-everything', 'warn'),
	'unknown short option'         => array('-x', 'warn'),
	'filter one edit away'         => array('--host-ids=5', 'abort'),
	'filter separator typo'        => array('--host_id=5', 'abort'),
	'filter missing letter'        => array('--graph-regx=edge', 'abort'),
	'filter missing dash'          => array('--graphregex=edge', 'abort'),
	'filter two edits away'        => array('--host-ib=5', 'abort'),
	'filter name truncated'        => array('--graph-template=5', 'abort'),
	'filter name prefix'           => array('--host=5', 'abort'),
	'filter name extended'         => array('--graph-template-ids=5', 'abort'),
	'declared option bad shape'    => array('--all=1', 'abort'),
	'declared filter without value' => array('--host-id', 'abort'),
	'option terminator'            => array('--', 'abort'),
	'bare word stops getopt'       => array('graph', 'abort'),
	'short cluster with help'      => array('-Hfoo', 'abort'),
));

test('remove_graphs sorts unknown arguments into ignore, warn and abort', function (string $parameter, string $action) use ($unknownLongopts) {
	expect(cacti_remove_graphs_parameter_is_valid($parameter, 'VvHh', $unknownLongopts))->toBeFalse()
		->and(cacti_remove_graphs_unknown_parameter_action($parameter, 'VvHh', $unknownLongopts))->toBe($action);
})->with('remove_graphs unknown arguments');

test('remove_graphs skips warned arguments and aborts on the rest', function () {
	$source = file_get_contents(dirname(__DIR__, 4) . '/cli/remove_graphs.php');

	expect($source)->toContain('$action = cacti_remove_graphs_unknown_parameter_action($parameter, $shortopts, $longopts);')
		->and($source)->toContain('print "WARNING: Ignoring unknown argument: ($parameter)" . PHP_EOL;')
		->and($source)->not->toContain("'graph-type:");
});
