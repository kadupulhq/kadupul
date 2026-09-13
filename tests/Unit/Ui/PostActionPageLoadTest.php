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
 * Links that moved from GET to POST must still load the way 1.2.31 loaded
 * them: in place for console links, as a full page where 1.2.31 navigated.
 */

test('plugin enable and disable links still reload the whole page', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/plugins.php');

	expect($src)->not->toBeFalse();

	/* Enabling or disabling a plugin changes the console menu, which only a
	   full page load redraws. 1.2.31 followed these anchors directly. */
	expect($src)->not->toMatch("/class='(?:pienable|pidisable)[^']*cactiPostAction/")
		->and($src)->toMatch("/\\$\\('\\.pienable, \\.pidisable'\\)\\.on\\('click', function\\(event\\) \\{\\s+event\\.preventDefault\\(\\);\\s+submitPageUsingPost\\(\\$\\(this\\)\\.attr\\('href'\\)\\);/");
});

test('plugin ordering and install links keep the in-page load', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/plugins.php');

	expect($src)->toContain("class='pic fa fa-caret-up moveArrow cactiPostAction'")
		->and($src)->toContain("class='pic fa fa-caret-down moveArrow cactiPostAction'")
		->and($src)->toContain("class='piinstall linkEditMain cactiPostAction'");
});

test('the data template New item link posts rrd_add in the page', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/data_templates.php');

	expect($src)->not->toBeFalse();

	/* A plain GET link to rrd_add now gets 405 from the action guard. */
	expect($src)->not->toContain("'data_templates.php?action=rrd_add&id=' . get_request_var('id'):''")
		->and($src)->toContain("array(array('id' => 'rrd_add', 'href' => '#', 'callback' => true))")
		->and($src)->toMatch("/\\$\\('#rrd_add'\\)\\.on\\('click', function\\(event\\) \\{\\s+event\\.preventDefault\\(\\);\\s+loadPage\\('data_templates\\.php\\?action=rrd_add&id=<\\?php print \\(int\\) get_request_var\\('id'\\);\\?>', false, true\\);/");
});

test('the New item icon renders as 1.2.31 drew it apart from the link target', function () {
	require_once dirname(__DIR__, 2) . '/Helpers/AuthEntryProbe.php';

	$html = file_get_contents(dirname(__DIR__, 3) . '/lib/html.php');

	$source = '<?php
		$config = array("poller_id" => 1);
		function __($text) { return $text; }
		function __esc($text) { return htmlspecialchars($text, ENT_QUOTES); }
		function html_escape($text) { return htmlspecialchars($text, ENT_QUOTES); }
		function get_current_page() { return "data_templates.php"; }
		function isempty_request_var($name) { return true; }
		function isset_request_var($name) { return false; }
		function get_nfilter_request_var($name) { return ""; }
		function clean_up_name($name) { return $name; }
		function html_help_page($page) { return false; }
		function is_realm_allowed($realm) { return false; }
		function cacti_sizeof($value) { return is_array($value) ? count($value) : 0; }
		' . cacti_test_function_source($html, 'html_start_box') . '
		$scenario = json_decode(stream_get_contents(STDIN), true);
		$out = array();
		foreach (array("string", "array") as $form) {
			ob_start();
			html_start_box("Data Source Item [ds]", "100%", true, "0", "center", $scenario[$form], "New");
			$out[$form] = ob_get_clean();
		}
		print json_encode($out);';

	$rendered = cacti_test_run_php_source($source, array(
		'string' => 'data_templates.php?action=rrd_add&id=3',
		'array'  => array(array('id' => 'rrd_add', 'href' => '#', 'callback' => true)),
	));

	$legacy = "<a class='linkOverDark' href='data_templates.php?action=rrd_add&amp;id=3'>";

	/* The static table suffix numbers each box, so compare without it. */
	$unnumbered = function ($markup) {
		return preg_replace("/id='data_templates\\d+/", "id='data_templates", $markup);
	};

	expect($rendered['string'])->toContain($legacy)
		->and($unnumbered($rendered['array']))
		->toBe($unnumbered(str_replace($legacy, "<a id='rrd_add' class='linkOverDark' href='#'>", $rendered['string'])));
});

test('the graph item list does not load a post action link by GET as well', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/graphs.php');

	expect($src)->not->toBeFalse();

	/* draw_graph_items_list() tags these anchors cactiPostAction with href='#'.
	   The page handler would abort the POST and fetch '#' instead. */
	expect($src)->toContain("$('.deleteMarker, .moveArrow').not('.cactiPostAction').on('click', function(event) {");
});
