<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace UtilitiesCacheDeviceScopeTest;

require_once dirname(__DIR__, 2) . '/Helpers/PhpSource.php';

// utilities.php runs its page on include, so the helper runs from source.
$source = file_get_contents(dirname(__DIR__, 3) . '/utilities.php');
if ($source === false) throw new \RuntimeException('Cannot read the actual utility predicate helper');
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'utilities_allowed_host_sql'));

// Explicit SQL producer port. Full persisted policy and view handoff execute in
// UtilityViewNativeCoverageTest; this check only verifies lossless delegation.
function get_allowed_management_device_ids_sql()
{
    $ids = array_map(static fn(array $device): int => (int) $device['id'], $GLOBALS['allowed_devices']);
    return $ids === [] ? 'SELECT NULL AS id WHERE 1=0' : 'SELECT id FROM host WHERE id IN (' . implode(', ', $ids) . ')';
}

test('cache helper retains the qualified column and complete management SQL predicate', function ($devices, $expected) {
    $GLOBALS['allowed_devices'] = $devices;

    expect(utilities_allowed_host_sql('h.id'))->toBe($expected);
})->with(array(
    'some devices' => array(array(array('id' => '3'), array('id' => '5')), 'h.id IN (SELECT id FROM host WHERE id IN (3, 5))'),
    'no devices' => array(array(), 'h.id IN (SELECT NULL AS id WHERE 1=0)'),
    'legacy row port emits its typed identity' => array(array(array('id' => '7) OR (1')), 'h.id IN (SELECT id FROM host WHERE id IN (7))'),
));
