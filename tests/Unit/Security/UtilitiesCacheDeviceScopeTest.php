<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace UtilitiesCacheDeviceScopeTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

// utilities.php runs its page on include, so the helper runs from source.
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source(file_get_contents(dirname(__DIR__, 3) . '/utilities.php'), 'utilities_allowed_host_sql'));

function get_allowed_devices($sql_where, $sql_order, $sql_limit, &$total_rows)
{
    return $GLOBALS['allowed_devices'];
}

test('cache views are limited to the devices the user may see', function ($devices, $expected) {
    $GLOBALS['allowed_devices'] = $devices;

    expect(utilities_allowed_host_sql('h.id'))->toBe($expected);
})->with(array(
    'some devices' => array(array(array('id' => '3'), array('id' => '5')), 'h.id IN (3, 5)'),
    'no devices' => array(array(), '1=0'),
    'non-numeric ids are cast' => array(array(array('id' => '7) OR (1')), 'h.id IN (7)'),
));
