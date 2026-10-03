<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/RrdCharacterization.php';

/** Draw one classic tab on $server_os and return the result, failing on any warning. */
function classic_tab_image_run($test, string $server_os, string $text): array
{
    $scenario = array(
        'server_os' => $server_os,
        'options' => rrd_characterization_options(),
        'calls' => array(
            array('fn' => 'get_classic_tabimage', 'args' => array($text), 'catch' => true),
        ),
    );

    return rrd_characterization_run($test, $scenario)['results'];
}

test('classic tab images find the bundled font on Windows', function () {
    $results = classic_tab_image_run($this, 'win32', 'Wiki Link');

    expect($results[0]['diagnostics'])->toBe(array())
        ->and($results[0]['returned'])->toBeString()->toStartWith('data:image/gif;base64,');
});

test('classic tab images still use the bundled font on Unix', function () {
    $results = classic_tab_image_run($this, 'unix', 'A much longer external link title');

    expect($results[0]['diagnostics'])->toBe(array())
        ->and($results[0]['returned'])->toBeString()->toStartWith('data:image/gif;base64,');
});

test('an empty tab title draws nothing', function () {
    $results = classic_tab_image_run($this, 'win32', '');

    expect($results[0]['returned'])->toBeFalse();
});
