<?php

declare(strict_types=1);
/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * Issue #7121 validation examples: execute the installed production validator
 * with its default unchecked unsafe-metacharacter setting in an isolated PHP
 * child. No shared settings stub or extracted-function copy determines policy.
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
require_once dirname(__DIR__) . '/Helpers/ChildProcessCoverage.php';

function input_string_validation_native(string $input): bool
{
    $root = dirname(__DIR__, 2);
    // Match read_config_option's real CLI cache shape and the shipped unchecked default.
    $program = '$config = ["is_web" => false, "config_options_array" => ["allow_unsafe_metachars" => ""]];'
        . 'require ' . var_export($root . '/lib/functions.php', true) . ';'
        . 'echo json_encode(cacti_input_string_is_safe(' . var_export($input, true) . '), JSON_THROW_ON_ERROR);'
        . '$GLOBALS["nativeChildCoverageMarkers"][] = "input-string-validation-complete";';
    $registration = child_coverage_registration(
        __FILE__,
        'input-string-validation',
        [$input],
        ['input-string-validation-complete'],
        ['lib/functions.php'],
        ['lib/path_helpers.php', 'include/global_settings.php']
    );
    $command = child_coverage_command([PHP_BINARY, '-r', $program], $directory, $registration);
    $result = test_php_run($command);
    expect($result['status'])->toBe(0);
    expect($result['err'])->toBe('');
    child_coverage_collect($directory);
    $value = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
    expect($value)->toBeBool();
    return $value;
}

test('input string validator accepts host placeholders and rejects shell separators', function () {
    $result = input_string_validation_native('<host>');
    expect($result)->toBeTrue();
    expect(input_string_validation_native('cmd ;'))->toBeFalse();
});

test('input string validator preserves script placeholders and quoted arguments', function () {
    $happy = [
        '<path_cacti>/scripts/x.php "<reason>"',
        "<path_cacti>/scripts/x.php '<reason>'",
        '<path_cacti>/scripts/x.php <arg1> <host_id2>',
        '<path_php_binary> -q <path_cacti>/scripts/x.php',
    ];
    foreach ($happy as $tpl) {
        expect(input_string_validation_native($tpl))->toBeTrue("happy-path: $tpl");
    }
});

test('input string validator rejects shell operators and command substitutions', function () {
    $bad = [
        'cmd ; rm -rf /',
        'cmd && bad',
        'cmd `id`',
        'cmd $(id)',
        'cmd > /tmp/out',
    ];
    foreach ($bad as $payload) {
        expect(input_string_validation_native($payload))->toBeFalse("attack: $payload");
    }
});
