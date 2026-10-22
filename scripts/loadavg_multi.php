<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/loadavg_multi.pl.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/script_command.php';

/**
 * Formats the first line of `uptime` as loadavg_multi.pl did, leaving every
 * value empty when the line does not end in three two-decimal averages.
 * sys_getloadavg() is not used: it does not round the way uptime prints.
 */
function loadavg_multi_format(string|false $uptime): string
{
    $line = strstr((string) $uptime, "\n", true);
    $line = $line === false ? (string) $uptime : $line . "\n";
    $match = [];
    preg_match('/^.*:\s(\d+\.\d{2}),?\s(\d+\.\d{2}),?\s(\d+\.\d{2})$/', $line, $match);

    return '1min:' . ($match[1] ?? '') . ' 5min:' . ($match[2] ?? '') . ' 10min:' . ($match[3] ?? '');
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    print loadavg_multi_format(script_command_output(['uptime'], ['LC_ALL' => 'C']));
}
