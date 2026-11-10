<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

/**
 * Runs lib/alerting.php's launcher in a child with recording stubs for the
 * legacy helpers it calls.
 *
 * @param array<string, string> $settings
 * @return list<array<mixed>> the exec_background() and cacti_log() calls, in order
 */
function alerting_poller_bottom_run(int $pollerId, array $settings): array
{
    $root = dirname(__DIR__, 4);
    $program = '$settings=' . var_export($settings, true) . ';$calls=[];'
        . 'function read_config_option($k){return $GLOBALS["settings"][$k]??"";}'
        . 'function exec_background($f,$a="",$r=""){$GLOBALS["calls"][]=["exec",$f,$a,$r];}'
        . 'function cacti_log($m,$o=false,$e="",$l=0){$GLOBALS["calls"][]=["log",$m,$e];}'
        . 'require ' . var_export($root . '/lib/alerting.php', true) . ';'
        . '$launched=alerting_poller_bottom(' . $pollerId . ', "/srv/kadupul");'
        . 'echo json_encode(["launched"=>$launched,"calls"=>$calls]);';
    $run = test_php_run($program);
    expect($run['err'])->toBe('')->and($run['status'])->toBe(0);
    $result = json_decode($run['out'], true, 8, JSON_THROW_ON_ERROR);
    expect($result['launched'])->toBe(array_filter($result['calls'], static fn(array $call): bool => $call[0] === 'exec') !== []);

    return $result['calls'];
}

test('a remote poller never launches the evaluator', function () {
    expect(alerting_poller_bottom_run(2, ['alerting_enable' => 'on', 'path_php_binary' => PHP_BINARY]))->toBe([]);
});

test('the main poller launches nothing while alerting is off', function (array $settings) {
    expect(alerting_poller_bottom_run(1, $settings + ['path_php_binary' => PHP_BINARY]))->toBe([]);
})->with([
    'missing' => [[]],
    'empty' => [['alerting_enable' => '']],
    'upper case' => [['alerting_enable' => 'ON']],
]);

test('the main poller launches the console command once in the background', function () {
    expect(alerting_poller_bottom_run(1, ['alerting_enable' => 'on', 'path_php_binary' => PHP_BINARY]))->toBe([
        ['exec', PHP_BINARY, ['/srv/kadupul/bin/console', 'kadupul:alerting:evaluate', '--no-interaction'], ''],
    ]);
});

test('a PHP binary that is not an absolute path to a file launches nothing and says why', function (string $php) {
    expect(alerting_poller_bottom_run(1, ['alerting_enable' => 'on', 'path_php_binary' => $php]))->toBe([
        ['log', 'WARNING: Alert evaluation was not started: the PHP Binary Path setting is not an absolute path to a file.', 'ALERTING'],
    ]);
})->with([
    'relative' => ['php'],
    'empty' => [''],
    'missing file' => ['/nonexistent/kadupul/php'],
    'directory' => [sys_get_temp_dir()],
]);

/**
 * Runs one poller.php post-processing block with every helper it calls
 * stubbed, and returns the helper names in call order.
 *
 * @return list<string>
 */
function alerting_poller_block_run(string $needle, int $pollerId): array
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $block = test_php_block_source($source, $needle);
    $stubs = '';
    foreach (['multiple_poller_boost_check', 'poller_replicate_check', 'snmpagent_poller_bottom', 'boost_poller_bottom', 'dsstats_poller_bottom', 'dsdebug_poller_bottom', 'reports_poller_bottom', 'spikekill_poller_bottom', 'automation_poller_bottom', 'poller_maintenance', 'rrdcheck_poller_bottom', 'bad_index_check', 'host_status_cache_check', 'poller_heartbeat_check', 'poller_recovery_flush_boost', 'cacti_log'] as $name) {
        $stubs .= 'function ' . $name . '(...$a){$GLOBALS["calls"][]="' . $name . '";}';
    }
    $program = '$calls=[];$config=["base_path"=>"/srv/kadupul","connection"=>"online"];$poller_id=' . $pollerId . ';$mibs_recorded=false;'
        . $stubs
        . 'function api_plugin_hook($h){$GLOBALS["calls"][]="hook:".$h;}'
        . 'function alerting_poller_bottom(int $p,string $b){$GLOBALS["calls"][]="alerting_poller_bottom:".$p.":".$b;return true;}'
        . $block
        . 'echo json_encode($calls);';
    $run = test_php_run($program);
    expect($run['err'])->toBe('')->and($run['status'])->toBe(0);

    return json_decode($run['out'], true, 4, JSON_THROW_ON_ERROR);
}

// These pin call order only. dsstats_poller_bottom() starts poller_dsstats.php in
// the background, so being called after it does not mean the evaluator reads this
// cycle's rates; it usually reads the previous cycle's (ADR 0011 consequences).
test('the end of a main poller run calls the alerting launcher once, after the dsstats launcher and before the plugin hook', function () {
    $calls = alerting_poller_block_run("/* start post data processing */\nif (\$poller_id == 1)", 1);

    expect(array_values(array_filter($calls, static fn(string $call): bool => str_starts_with($call, 'alerting'))))->toBe(['alerting_poller_bottom:1:/srv/kadupul'])
        ->and(array_search('alerting_poller_bottom:1:/srv/kadupul', $calls, true))->toBeGreaterThan(array_search('dsstats_poller_bottom', $calls, true))
        ->and(array_search('alerting_poller_bottom:1:/srv/kadupul', $calls, true))->toBeLessThan(array_search('hook:poller_bottom', $calls, true));
});

test('the end of a remote poller run does not launch alerting', function () {
    expect(alerting_poller_block_run("/* start post data processing */\nif (\$poller_id == 1)", 3))->not->toContain('alerting_poller_bottom:3:/srv/kadupul');
});

test('each sub-minute cycle of the main poller calls the alerting launcher once, after the dsstats launcher', function () {
    $calls = alerting_poller_block_run("// all plugins moved to core\n            if (\$poller_id == 1)", 1);

    expect($calls)->toBe(['snmpagent_poller_bottom', 'dsstats_poller_bottom', 'alerting_poller_bottom:1:/srv/kadupul', 'dsdebug_poller_bottom', 'boost_poller_bottom']);
    expect(alerting_poller_block_run("// all plugins moved to core\n            if (\$poller_id == 1)", 2))->toBe([]);
});
