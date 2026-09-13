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
 * data_queries.php refuses item_remove_confirm unless it arrives by POST, so
 * the page must fetch that dialog by POST as well.
 */

test('the graph template association delete dialog is fetched by POST', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/data_queries.php');

	expect($src)->not->toBeFalse();

	$start = strpos($src, "$('.delete').on('click', function (event) {");

	expect($start)->not->toBeFalse();

	$handler = substr($src, $start, strpos($src, '.done(function(data) {', $start) - $start);

	expect($handler)->not->toContain('$.get(')
		->and($handler)->toContain('cactiPreparePostRequestFromUrl($(this).attr(\'href\'))')
		->and($handler)->toMatch('/\$\.post\((\w+)\.url, \1\.data\)/');
});

test('the dialog step itself still requires POST', function () {
	$src = file_get_contents(dirname(__DIR__, 3) . '/data_queries.php');

	expect($src)->toMatch("/case 'item_remove_confirm':\\s+csrf_require_post\\(\\);\\s+data_query_item_remove_confirm\\(\\);/");
});
