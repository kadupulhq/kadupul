<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// Stands in for include/auth.php behind a copied console worker. It resumes the
// handed-off session through the native session API and supplies the globals
// and functions the legacy bootstrap leaves for the workers.
$consoleHandoffFixture = json_decode((string) file_get_contents(dirname(__DIR__) . '/scenario.json'), true, 8, JSON_THROW_ON_ERROR);
require $consoleHandoffFixture['autoload'];
session_start();
file_put_contents(dirname(__DIR__) . '/state.json', json_encode([$_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_FILENAME'], $_SERVER['REQUEST_METHOD'], session_id(), session_save_path(), getcwd()], JSON_THROW_ON_ERROR));
$_SESSION = $consoleHandoffFixture['session'];
if ($consoleHandoffFixture['hook_closes_session']) {
    // A config_arrays hook that releases the session lock for long work, as
    // plugins do, leaves later session changes with nowhere to be written.
    session_write_close();
}
$menu = ['Gestion' => ['host.php' => 'Devices', 'plugins/fixture/fixture.php' => 'Fixture']];
$user_auth_realm_filenames = ['host.php' => 3, 'fixture.php' => 150];
// Output before the result proves the worker discards bootstrap noise.
print 'bootstrap output';

function is_realm_allowed(int $realm): bool
{
    return in_array($realm, $GLOBALS['consoleHandoffFixture']['realms'], true);
}

function __(string $text): string
{
    return $text === 'Management' ? 'Gestion' : $text;
}
