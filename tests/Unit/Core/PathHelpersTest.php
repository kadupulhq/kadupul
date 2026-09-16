<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

require_once dirname(__DIR__, 3) . '/lib/path_helpers.php';

$slash = chr(92);

test('directory trimming preserves roots and drive-relative paths', function ($path, $separator, $expected) {
    expect(cacti_trim_dir_separator($path, $separator))->toBe($expected);
})->with(array(
    array('', '/', ''), array('/var/data', '/', '/var/data'),
    array('/var/data///', '/', '/var/data'), array('///', '/', '/'),
    array('C:', $slash, 'C:'), array('C:' . $slash, $slash, 'C:' . $slash),
    array('C://', $slash, 'C:/'), array($slash . $slash, $slash, $slash),
    array('C:/data' . $slash, $slash, 'C:/data')
));

test('directory joining preserves absolute and drive-relative semantics', function ($path, $separator, $expected) {
    expect(cacti_join_dir_child($path, 'file.rrd', $separator))->toBe($expected);
})->with(array(
    array('', '/', 'file.rrd'), array('/', '/', '/file.rrd'),
    array('/var/data', '/', '/var/data/file.rrd'), array('/var/data/', '/', '/var/data/file.rrd'),
    array('C:', $slash, 'C:file.rrd'), array('C:' . $slash, $slash, 'C:' . $slash . 'file.rrd'),
    array('C:/', $slash, 'C:/file.rrd'), array('C:/data', $slash, 'C:/data' . $slash . 'file.rrd')
));
