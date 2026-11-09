<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function collector_preflight_sources(): array
{
    $root = dirname(__DIR__, 4);
    $library = file_get_contents($root . '/lib/poller.php');
    $poller = file_get_contents($root . '/poller.php');
    expect($library)->not->toBeFalse()->and($poller)->not->toBeFalse();

    return [test_php_function_source($library, 'poller_collector_preflight'), $poller];
}

/** Run the poller.php startup check with the production helper and recording stubs. */
function collector_preflight_run(array $settings): array
{
    [$function, $poller] = collector_preflight_sources();
    $block = test_php_block_source($poller, '// check the collector binary before this run records anything');
    $program = 'namespace CollectorPreflightProbe;'
        . '$settings=' . var_export($settings, true) . ';$calls=[];$poller_id=3;'
        . 'function read_config_option($k){return $GLOBALS["settings"][$k]??"";}'
        . 'function set_config_option($k,$v){$GLOBALS["calls"][]=["set",$k];}'
        . 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["calls"][]=["log",$m];}'
        . 'function admin_email($s,$m){$GLOBALS["calls"][]=["mail",$m];}'
        . 'function __($f,...$a){return vsprintf($f,$a);}'
        . 'register_shutdown_function(function(){echo json_encode($GLOBALS["calls"]);});'
        . $function . $block . 'echo "continued";';

    return test_php_run($program);
}

function collector_preflight_binaries(): array
{
    $directory = sys_get_temp_dir() . '/kadupul-preflight-' . bin2hex(random_bytes(6));
    expect(mkdir($directory . '/folder', 0700, true))->toBeTrue();
    foreach (['runnable' => 0700, 'plain' => 0600] as $name => $mode) {
        expect(file_put_contents($directory . '/' . $name, "#!/bin/sh\n"))->not->toBeFalse();
        expect(chmod($directory . '/' . $name, $mode))->toBeTrue();
    }

    return [$directory, static function () use ($directory): void {
        unlink($directory . '/runnable');
        unlink($directory . '/plain');
        rmdir($directory . '/folder');
        rmdir($directory);
    }];
}

test('the preflight accepts an executable collector and names the setting that is unusable', function () {
    [$function] = collector_preflight_sources();
    eval('namespace CollectorPreflightUnit; ' . $function); // nosemgrep: php.lang.security.eval-use.eval-use
    [$directory, $cleanup] = collector_preflight_binaries();
    try {
        $run = $directory . '/runnable';
        expect(\CollectorPreflightUnit\poller_collector_preflight('2', $run, ''))->toBeNull()
            ->and(\CollectorPreflightUnit\poller_collector_preflight('1', '', $run))->toBeNull()
            ->and(\CollectorPreflightUnit\poller_collector_preflight(2, $directory . '/missing', $run))->toBe(['path_spine', $directory . '/missing'])
            ->and(\CollectorPreflightUnit\poller_collector_preflight('2', $directory . '/folder', $run))->toBe(['path_spine', $directory . '/folder'])
            ->and(\CollectorPreflightUnit\poller_collector_preflight('1', $run, $directory . '/plain'))->toBe(['path_php_binary', $directory . '/plain'])
            ->and(\CollectorPreflightUnit\poller_collector_preflight('', $run, ''))->toBe(['path_php_binary', '']);
    } finally {
        $cleanup();
    }
});

test('the poller stops before recording anything when the collector binary is unusable', function (string $type, string $setting, string $message) {
    [$directory, $cleanup] = collector_preflight_binaries();
    try {
        $settings = ['poller_enabled' => 'on', 'poller_type' => $type, 'path_spine' => $directory . '/runnable', 'path_php_binary' => $directory . '/runnable'];
        $settings[$setting] = $directory . '/missing';
        $result = collector_preflight_run($settings);
    } finally {
        $cleanup();
    }

    expect($result['status'])->toBe(1, $result['err'])->and($result['out'])->not->toContain('continued');
    $calls = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
    expect(array_column($calls, 0))->toBe(['log', 'mail'])
        ->and($calls[0][1])->toContain($message)
        ->and($calls[1][1])->toBe(sprintf('ERROR: The %s path: %s is invalid for poller id 3.  Poller can not continue!', $message === 'spine path' ? 'spine' : 'PHP binary', $directory . '/missing'));
})->with([
    'spine' => ['2', 'path_spine', 'spine path'],
    'cmd.php' => ['1', 'path_php_binary', 'PHP binary path'],
]);

test('a usable collector or disabled polling continues without logging', function (array $settings) {
    [$directory, $cleanup] = collector_preflight_binaries();
    try {
        $settings = array_map(static fn($value) => str_replace('{dir}', $directory, $value), $settings);
        $result = collector_preflight_run($settings);
    } finally {
        $cleanup();
    }

    expect($result['status'])->toBe(0, $result['err'])->and($result['out'])->toBe('continued[]');
})->with([
    'spine' => [['poller_enabled' => 'on', 'poller_type' => '2', 'path_spine' => '{dir}/runnable', 'path_php_binary' => '{dir}/missing']],
    'cmd.php' => [['poller_enabled' => 'on', 'poller_type' => '1', 'path_spine' => '{dir}/missing', 'path_php_binary' => '{dir}/runnable']],
    'polling disabled' => [['poller_enabled' => '', 'poller_type' => '2', 'path_spine' => '{dir}/missing', 'path_php_binary' => '{dir}/missing']],
]);

test('the startup check runs before the poller records its first run marker', function () {
    [, $poller] = collector_preflight_sources();
    $check = strpos($poller, '// check the collector binary before this run records anything');
    $marker = strpos($poller, "set_config_option('poller_mibsrun_");
    $lastrun = strpos($poller, "set_config_option('poller_lastrun_");

    expect($check)->not->toBeFalse()->and($marker)->not->toBeFalse()->and($lastrun)->not->toBeFalse()
        ->and($check)->toBeLessThan($marker)->and($check)->toBeLessThan($lastrun);
});
