<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace CactiValidateThemeBehavior;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read theme helper.');
}
eval('namespace ' . __NAMESPACE__ . ';' . \test_php_function_source($source, 'cacti_validate_theme')); // nosemgrep: php.lang.security.eval-use.eval-use

final class ThemeFixture
{
    public static string $default = 'modern';
}

function read_config_option(string $name): string
{
    if ($name !== 'selected_theme') {
        throw new \RuntimeException('Unexpected theme setting.');
    }

    return ThemeFixture::$default;
}

beforeEach(function () {
    $this->previousThemeConfig = [array_key_exists('config', $GLOBALS), $GLOBALS['config'] ?? null];
    $GLOBALS['config'] = ['base_path' => dirname(__DIR__, 2)];
    ThemeFixture::$default = 'modern';
});

afterEach(function () {
    if ($this->previousThemeConfig[0]) {
        $GLOBALS['config'] = $this->previousThemeConfig[1];
    } else {
        unset($GLOBALS['config']);
    }
});

test('production theme validation admits shipped themes with real RRD theme files', function () {
    $themes = glob(dirname(__DIR__, 2) . '/include/themes/*/rrdtheme.php');
    expect($themes)->not->toBeEmpty();
    foreach ($themes as $file) {
        $name = basename(dirname($file));
        expect(cacti_validate_theme($name))->toBe($name);
    }
});

test('production theme validation refuses unknown names and traversal targets', function (mixed $requested) {
    expect(cacti_validate_theme($requested))->toBe('modern');
})->with(['unknown' => 'not-a-shipped-theme', 'relative path' => '../../etc/passwd', 'absolute path' => '/etc/passwd', 'nested traversal' => 'modern/../../etc/passwd', 'empty' => '', 'dot' => '.', 'parent' => '..', 'uppercase' => 'MODERN', 'mixed case' => 'Modern', 'null' => null, 'integer' => 0, 'boolean' => false]);

test('configured fallback must also be a shipped theme', function (string $configured, string $expected) {
    ThemeFixture::$default = $configured;
    expect(cacti_validate_theme('not-a-shipped-theme'))->toBe($expected);
})->with([['classic', 'classic'], ['', 'modern'], ['../../etc/passwd', 'modern'], ['attacker_uploaded_theme', 'modern']]);

test('graph entry points retain production theme validation', function (string $file) {
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    expect($source)->toBeString()->toContain("cacti_validate_theme(get_request_var('graph_theme'))");
})->with(['graph_image.php', 'graph_json.php', 'remote_agent.php']);
