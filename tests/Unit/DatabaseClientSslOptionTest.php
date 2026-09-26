<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

$databaseSource = file_get_contents(dirname(__DIR__, 2) . '/lib/database.php');

test('database client TLS flags match the detected client and configured policy', function () use ($databaseSource) {
    require_once dirname(__DIR__, 2) . '/tests/Helpers/PhpSource.php';
    eval(test_php_function_source($databaseSource, 'db_client_ssl_option')); // nosemgrep: php.lang.security.eval-use.eval-use

    expect(db_client_ssl_option(false, 'mysql  Ver 8.4.2 for Linux'))->toBe(' --ssl-mode=DISABLED')
        ->and(db_client_ssl_option(false, 'mariadb  Ver 15.1 Distrib 10.11.8-MariaDB'))->toBe(' --skip-ssl')
        ->and(db_client_ssl_option(false, 'Percona Server version 8.0.39'))->toBe(' --ssl-mode=DISABLED')
        ->and(db_client_ssl_option(true, 'mysql  Ver 8.4.2 for Linux'))->toBe('')
        ->and(db_client_ssl_option(false, 'unknown database client'))->toBeFalse();
});
