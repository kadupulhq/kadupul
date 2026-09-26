<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace AddGraphTemplateAssociationLookupTest;

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function db_fetch_cell_prepared($sql, $params) {
	return $GLOBALS['association_count'];
}

$source = file_get_contents(dirname(__DIR__, 4) . '/cli/add_graph_template.php');
if (!is_string($source)) {
	throw new \RuntimeException('Cannot read cli/add_graph_template.php.');
}

eval('namespace AddGraphTemplateAssociationLookupTest; ' . test_php_function_source($source, 'add_graph_template_association_exists'));

test('association lookup distinguishes absent, present, and failed results', function () {
	$GLOBALS['association_count'] = 0;
	expect(add_graph_template_association_exists(1, 2))->toBeFalse();

	$GLOBALS['association_count'] = '1';
	expect(add_graph_template_association_exists(1, 2))->toBeTrue();

	$GLOBALS['association_count'] = false;
	expect(add_graph_template_association_exists(1, 2))->toBeNull();
});
