<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later
// Stands in for include/cli_check.php under a copy of
// bin/legacy-alerting-deliver.php: the autoloader and a cacti_log() that
// appends to the fixture's log file.
require dirname(__DIR__, 2) . '/include/vendor/autoload.php';

function cacti_log($string, $output = false, $environ = 'CMDPHP', $level = '')
{
    file_put_contents(getenv('ALERTING_FIXTURE') . '/log', $environ . ' ' . $string . "\n", FILE_APPEND);
}
