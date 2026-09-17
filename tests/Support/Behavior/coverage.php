<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require '/harness/errors.php';

\pcov\start();
// Register after the application's shutdown callbacks, which can perform writes.
register_shutdown_function(function () {
    register_shutdown_function(function () {
        \pcov\stop();
        $files = [];
        foreach (\pcov\collect() as $path => $lines) {
            if (!str_starts_with($path, '/var/www/html/') || $path === '/var/www/html/include/config.php') {
                continue;
            }
            $hash = hash_file('sha256', $path);
            if ($hash === false) {
                throw new RuntimeException('Cannot hash covered source');
            }
            $files[$path] = ['sha256' => $hash, 'lines' => $lines];
        }
        $json = json_encode(['php' => PHP_VERSION, 'files' => $files], JSON_THROW_ON_ERROR);
        $path = '/coverage/coverage-' . getmypid() . '-' . bin2hex(random_bytes(8)) . '.json';
        if (file_put_contents($path, $json) !== strlen($json)) {
            throw new RuntimeException('Cannot persist integration coverage');
        }
    });
});
