<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PrepareMultiDelimiterTest;

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';
$source = file_get_contents(dirname(__DIR__, 2) . '/lib/functions.php');
if (!is_string($source)) {
    throw new \RuntimeException('Unable to read production result helpers');
}
if (!defined('POLLER_VERBOSITY_MEDIUM')) {
    define('POLLER_VERBOSITY_MEDIUM', 2);
}
function dsv_log(...$arguments) {}
foreach (['cacti_sizeof', 'is_hexadecimal', 'strip_alpha', 'normalize_poller_multi_value_result', 'prepare_validate_result'] as $function) {
    eval('namespace ' . __NAMESPACE__ . ';' . test_php_function_source($source, $function));
}

$cases = [
    ['users!14 load!0.42', 'users:14 load:0.42', true],
    ['users:14 load!0.42', 'users:14 load:0.42', true],
    ["users!14\tload!-0.42", 'users:14 load:-0.42', true],
    ['users!U load!1e-3', 'users:U load:1e-3', true],
    ['cd!12 ab!34', 'cd!12 ab!34', true],
    ['cd:12 ab!34', 'cd:12 ab!34', true],
    ['users!14 load', 'users!14 load', false],
    ['a!b:c 1', 'a!b:c 1', false],
    ['Hello!', 'Hello!', true],
    ['0a!1b', '0a!1b', true],
    ['00!00', '00!00', true],
    ['42', '42', true],
    ['U', 'U', true],
    ['00:00', '00:00', 0],
    ['cd:12 ab:34', 'cd:12 ab:34', 3440552756],
    ['unknown', 'U', false],
];

test('real validator normalizes complete field lists and preserves scalar and hex contracts', function ($input, $expected, $valid) {
    $result = $input;
    expect(prepare_validate_result($result))->toBe($valid)->and($result)->toBe($expected);
})->with($cases);


test('the installed result helpers execute the field-list regression with validated native coverage', function () use ($cases) {
    $root = dirname(__DIR__, 2);
    $directory = sys_get_temp_dir() . '/poller-result-' . bin2hex(random_bytes(8));
    expect(mkdir($directory, 0700))->toBeTrue();
    $coverage = $this->getTestResultObject()->getCodeCoverage();
    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'pcov.directory=' . $root,
                '-d', 'pcov.exclude=~/(include/vendor|tests)/~',
                $root . '/tests/Fixtures/poller-result-helpers.php', $root,
                json_encode(array_column($cases, 0), JSON_THROW_ON_ERROR), $coverage === null ? '' : $directory],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) throw new \RuntimeException('Unable to launch production result helpers.');
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || $error !== '') throw new \RuntimeException($error . $output);
        expect(json_decode($output, true, 512, JSON_THROW_ON_ERROR))->toBe(array_map(static fn($case) => [$case[1], $case[2]], $cases));
        if ($coverage !== null) {
            require_once $root . '/tests/Helpers/NativeChildCoverageEvidence.php';
            $reports = glob($directory . '/*.coverage');
            expect($reports)->toHaveCount(1);
            $sources = ['lib/functions.php', 'lib/path_helpers.php', 'include/global_constants.php',
                'lib/rrd.php', 'src/Graphing/Infrastructure/Rrd/ProxyCipher.php', 'lib/dsdebug.php', 'lib/rrd_maintenance.php', 'lib/poller.php', 'lib/boost.php', 'lib/api_data_source.php', 'lib/rrdcheck.php', 'lib/dsstats.php',
                'tests/Helpers/NativeChildCoverageEvidence.php', 'tests/Fixtures/rrd-process-coverage.php'];
            $arguments = [$reports[0], $root, 'tests/Fixtures/poller-result-helpers.php', 'field-lists-v1', $sources, ['field-lists-completed'], ['lib/functions.php']];
            $measured = \NativeChildCoverageEvidence::load(...$arguments);
            expect(\NativeChildCoverageEvidence::verifyRejections(...[...$arguments, 'lib/boost.php']))->toBe(count($sources) + 11);
            $lines = $measured->getData()->lineCoverage()[$root . '/lib/functions.php'];
            $production = file($root . '/lib/functions.php');
            foreach (['$fields = preg_split', '$field_result = normalize_poller_multi_value_result'] as $statement) {
                $matches = array_keys(array_filter($production, static fn($line) => str_contains($line, $statement)));
                expect($matches)->toHaveCount(1);
                expect($lines[$matches[0] + 1] ?? [])->not->toBeEmpty();
            }
            $coverage->merge($measured);
        }
    } finally {
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
});
