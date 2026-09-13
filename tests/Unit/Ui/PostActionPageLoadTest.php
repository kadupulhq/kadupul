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

test('the graph item list does not load a post action link by GET as well', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/graphs.php');

	expect($src)->not->toBeFalse();

	/* draw_graph_items_list() tags these anchors cactiPostAction with href='#'.
	   The page handler would abort the POST and fetch '#' instead. */
	expect($src)->toContain("$('.deleteMarker, .moveArrow').not('.cactiPostAction').on('click', function(event) {");
});
