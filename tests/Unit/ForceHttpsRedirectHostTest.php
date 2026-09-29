<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/lib/html_utility.php';

test('forced HTTPS redirect uses the configured server name instead of the Host header', function () {
    $originalServer = $_SERVER;

    try {
        $_SERVER['SERVER_NAME'] = 'kadupul.example';
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $_SERVER['REQUEST_URI'] = '/cacti/host.php?id=12';

        expect(cacti_force_https_redirect_url())->toBe('https://kadupul.example/cacti/host.php?id=12');
    } finally {
        $_SERVER = $originalServer;
    }
});

test('forced HTTPS redirect rejects an invalid configured authority', function () {
    $originalServer = $_SERVER;

    try {
        $_SERVER['SERVER_NAME'] = "kadupul.example\r\nLocation: https://attacker.example";
        $_SERVER['REQUEST_URI'] = '/cacti/';

        expect(cacti_force_https_redirect_url())->toBeNull();
    } finally {
        $_SERVER = $originalServer;
    }
});

test('forced HTTPS redirect keeps request targets local', function () {
    $originalServer = $_SERVER;

    try {
        $_SERVER['SERVER_NAME'] = 'kadupul.example';
        $_SERVER['HTTP_HOST'] = 'attacker.example';
        $_SERVER['REQUEST_URI'] = '//attacker.example/path';

        expect(cacti_force_https_redirect_url())->toBe('https://kadupul.example/');
    } finally {
        $_SERVER = $originalServer;
    }
});
