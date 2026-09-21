<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Keep this preflight parseable by older installations so upgrades fail clearly
// before configuration, database access or Composer's generated autoloader.
if (PHP_VERSION_ID < 80400) {
    $message = 'Kadupul main requires PHP 8.4 or later.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit(1);
}
