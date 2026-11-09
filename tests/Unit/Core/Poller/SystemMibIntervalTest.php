<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

test('system MIB scheduling uses elapsed time for its own collector', function ($lastMibs, $now, $expected) {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $schedule = test_php_block_source($source, '// retrieve the last time the poller ran');
    $settings = ['poller_lastrun_2' => $now - 60, 'poller_mibsrun_2' => $lastMibs,
        'poller_mibsrun_1' => $now - 20000];
    $program = 'namespace MibIntervalProbe;'
        . '$settings=' . var_export($settings, true) . ';$writes=[];$poller_id=2;$mibs=false;$current_time=' . $now . ';'
        . 'function read_config_option($key){return $GLOBALS["settings"][$key]??"";}'
        . 'function set_config_option($key,$value){$GLOBALS["writes"][]=[$key,$value];$GLOBALS["settings"][$key]=$value;}'
        . $schedule . 'echo json_encode([$mibs,$settings,$writes]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('');
    [$mibs, $stored, $writes] = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
    expect($mibs)->toBe($expected)
        ->and($stored['poller_lastrun_2'])->toBe($now - 60)
        ->and($stored['poller_mibsrun_1'])->toBe($now - 20000)
        ->and($stored['poller_mibsrun_2'])->toBe($lastMibs)
        ->and($writes)->toBe([]);
})->with([
    'missing first run' => ['', 36000, true],
    'false first run' => [false, 36000, true],
    'zero first run' => ['0', 36000, true],
    'just collected' => [35999, 36000, false],
    'one-minute interval' => [35940, 36000, false],
    'five-minute interval' => [35700, 36000, false],
    'before four hours' => [21601, 36000, false],
    'exact four-hour boundary retains LTS policy' => [21600, 36000, false],
    'past four hours' => [21599, 36000, true],
    'overdue across old modulo wrap' => [10000, 28880, true],
    'recent across old modulo wrap' => [28820, 28880, false],
    'stored timestamp string' => ['21599', 36000, true],
    'future timestamp retains LTS policy' => [36001, 36000, false],
]);

test('an admitted MIB collection does not repeat on the next poll or leak between collectors', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $schedule = test_php_block_source($source, '// retrieve the last time the poller ran');
    $record = test_php_block_source($source, '/* record the system MIB run');
    $program = 'namespace MibSequenceProbe;$settings=[];$writes=[];$observed=[];'
        . 'function read_config_option($key){return $GLOBALS["settings"][$key]??"";}'
        . 'function set_config_option($key,$value){$GLOBALS["writes"][]=[$key,$value];$GLOBALS["settings"][$key]=$value;}'
        . 'foreach([[2,36000],[2,36060],[3,36060],[2,36300],[2,50400],[2,50401]] as [$poller_id,$current_time]) {'
        . '$mibs=false;$mibs_recorded=false;$started_processes=1;$settings["poller_lastrun_".$poller_id]=$current_time-60;'
        . $schedule . $record . $record . '$observed[]=$mibs;}echo json_encode([$observed,$writes]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('');
    expect(json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR))->toBe([
        [true, false, true, false, false, true],
        [['poller_mibsrun_2', 36000], ['poller_mibsrun_3', 36060], ['poller_mibsrun_2', 50401]],
    ]);
});

test('a poll that exits before starting a collector keeps the MIB window open', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $schedule = test_php_block_source($source, '// retrieve the last time the poller ran');
    $record = test_php_block_source($source, '/* record the system MIB run');
    // Each run: [time, whether it reached the launch, collectors started there].
    $program = 'namespace MibEarlyExitProbe;$settings=["poller_mibsrun_2"=>20000];$writes=[];$observed=[];'
        . 'function read_config_option($key){return $GLOBALS["settings"][$key]??"";}'
        . 'function set_config_option($key,$value){$GLOBALS["writes"][]=[$key,$value];$GLOBALS["settings"][$key]=$value;}'
        . 'foreach([[36000,false,0],[36060,true,0],[36120,true,2],[36180,true,2]] as [$current_time,$launch,$started_processes]) {'
        . '$poller_id=2;$mibs=false;$mibs_recorded=false;'
        . $schedule . 'if($launch){' . $record . '}$observed[]=$mibs;}echo json_encode([$observed,$writes]);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('');
    expect(json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR))->toBe([
        [true, true, true, false],
        [['poller_mibsrun_2', 36120]],
    ]);
});

test('the bad index check follows a launched MIB run, not an open window', function () {
    $source = file_get_contents(dirname(__DIR__, 4) . '/poller.php');
    expect($source)->not->toBeFalse();
    $start = strpos($source, '    bad_index_check($');
    expect($start)->not->toBeFalse();
    $call = substr($source, $start, strpos($source, ';', $start) - $start + 1);
    // The first run has the window open but started no collector, as when polling is disabled.
    $program = 'namespace MibBadIndexProbe;$checks=[];'
        . 'function bad_index_check($mibs){$GLOBALS["checks"][]=$mibs;}'
        . 'foreach([[true,false],[true,true],[false,false]] as [$mibs,$mibs_recorded]) {' . $call . '}'
        . 'echo json_encode($checks);';
    $result = test_php_run($program);
    expect($result['status'])->toBe(0, $result['err'])->and($result['err'])->toBe('')
        ->and(json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR))->toBe([false, true, false]);
});
