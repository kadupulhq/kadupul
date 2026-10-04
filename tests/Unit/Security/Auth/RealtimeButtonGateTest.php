<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

/*
 * The graph page offered the Real-time button when real-time was enabled or
 * the user held the Real-time realm (25), so a user without the realm saw it
 * whenever real-time was on, and a realm holder saw it with real-time off.
 * graph_realtime.php refuses both, so the button now needs the setting and
 * the realm, as lib/html.php already requires for the graph list.
 *
 * The condition guarding the button is read from graph.php and evaluated in
 * this namespace with the setting and the realm stubbed.
 */

namespace RealtimeButtonGateTest;

function read_config_option($name, $force = false)
{
    return $name === 'realtime_enabled' ? $GLOBALS['realtime_button']['enabled'] : '';
}

function is_realm_allowed($realm, $check_user = false)
{
    return $realm === 25 && $GLOBALS['realtime_button']['realm'];
}

function realtime_button_shown(string $enabled, bool $realm): bool
{
    static $condition = null;

    if ($condition === null) {
        $source = file_get_contents(dirname(__DIR__, 4) . '/graph.php');

        if (!preg_match('/if \(([^{}]+)\) \{\s*print "<a class=\'iconLink\' href=\'#\' onclick=\\\\"window\.open\(\'" \. \$config\[\'url_path\'\] \. \'graph_realtime\.php/', $source, $match)) {
            throw new \RuntimeException('The Real-time button condition was not found in graph.php');
        }

        $condition = $match[1];
    }

    $GLOBALS['realtime_button'] = array('enabled' => $enabled, 'realm' => $realm);

    // test-only eval of an expression read from this repository, not external input
    return (bool) eval('namespace ' . __NAMESPACE__ . '; return ' . $condition . ';');
}

test('the Real-time button needs real-time enabled and the Real-time realm', function ($enabled, $realm, $shown) {
    expect(realtime_button_shown($enabled, $realm))->toBe($shown);
})->with(array(
    'enabled with the realm'       => array('on', true, true),
    'enabled without the realm'    => array('on', false, false),
    'disabled with the realm'      => array('', true, false),
    'disabled without the realm'   => array('', false, false),
));
