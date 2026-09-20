<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

it('keeps dependency resolution at the existing application and test runtime floors', function (): void {
    $root = dirname(__DIR__, 2);
    $application = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $tests = json_decode(file_get_contents($root . '/tests/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    expect($application['require']['php'])->toBe('>=8.1')
        ->and($application['config']['platform']['php'])->toBe('8.1.0')
        ->and($tests['config']['platform']['php'])->toBe('8.1.0');
});

it('bundles the locked HTML Purifier and sanitizes markup without filesystem cache writes', function (): void {
    $root = dirname(__DIR__, 2);
    require_once $root . '/include/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
    $lock = json_decode(file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
    $packages = array_column($lock['packages'], null, 'name');
    $version = ltrim($packages['ezyang/htmlpurifier']['version'], 'v');

    expect(HTMLPurifier::VERSION)->toBe($version);

    $config = HTMLPurifier_Config::createDefault();
    $config->set('Cache.DefinitionImpl', null);
    $purifier = new HTMLPurifier($config);
    $html = $purifier->purify('<b>safe</b><img src="x" onerror="alert(1)"><script>alert(1)</script>');

    expect($html)->toContain('<b>safe</b>')
        ->not->toContain('onerror')
        ->not->toContain('<script');
});
