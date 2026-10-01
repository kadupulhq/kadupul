<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__) . '/Helpers/PhpSource.php';

// Exercise the production functions while isolating configuration and logging.
eval(<<<'ADAPTERS'
namespace Php80Modernization;
function read_config_option($name) {
    // Real configuration/logging may use PCRE and overwrite its last error.
    \preg_match('/valid/', 'valid');
    return array_key_exists($name, $GLOBALS['php80_state']['options']) ? $GLOBALS['php80_state']['options'][$name] : '';
}
function read_user_setting($name, $default) { return read_config_option($name); }
function cacti_log($message, $output = false) { $GLOBALS['php80_state']['logs'][] = $message; }
function cacti_debug_backtrace($message) { $GLOBALS['php80_state']['traces'][] = $message; }
function raise_message($message) { $GLOBALS['php80_state']['messages'][] = $message; }
function __esc($message) { return $message; }
function cacti_sizeof($value) { return \count($value); }
function api_plugin_is_enabled($name) { return false; }
function rrdtool_clock_now() { return new \DateTimeImmutable('2026-01-02T03:04:05+00:00'); }
ADAPTERS);

foreach ([
    'GD_MO_D_Y' => 0, 'GD_MN_D_Y' => 1, 'GD_D_MO_Y' => 2,
    'GD_D_MN_Y' => 3, 'GD_Y_MO_D' => 4, 'GD_Y_MN_D' => 5,
    'GDC_HYPHEN' => 0, 'GDC_SLASH' => 1, 'GDC_DOT' => 2,
    'MESSAGE_LEVEL_NONE' => 0, 'MESSAGE_LEVEL_INFO' => 1,
    'MESSAGE_LEVEL_WARN' => 2, 'MESSAGE_LEVEL_ERROR' => 3,
    'MESSAGE_LEVEL_CSRF' => 4, 'RRD_NL' => "\n",
] as $name => $value) {
    define('Php80Modernization\\' . $name, $value);
}

$root = dirname(__DIR__, 2);
foreach ([
    'lib/functions.php' => ['form_input_validate', 'get_message_level', 'get_format_message_instance',
        'date_time_format', 'determine_display_log_entry', 'is_hex_string', 'is_ipaddress',
        'cacti_is_sensitive_key', 'cacti_format_ipv6_colon'],
    'lib/rrd.php' => ['rrdtool_function_format_graph_date'],
] as $file => $names) {
    $source = file_get_contents($root . '/' . $file);
    foreach ($names as $name) {
        eval('namespace Php80Modernization; ' . test_php_function_source($source, $name));
    }
}

beforeEach(function () {
    $this->php80Session = $_SESSION ?? null;
    $this->php80HadSession = isset($_SESSION);
    $this->php80Datechar = $GLOBALS['datechar'] ?? null;
    $this->php80HadDatechar = array_key_exists('datechar', $GLOBALS);
    $_SESSION = [];
    $GLOBALS['datechar'] = ['-', '/', '.'];
    $GLOBALS['php80_state'] = [
        'options' => ['log_validation' => 'on', 'default_date_format' => 0, 'default_datechar' => 0],
        'logs' => [], 'traces' => [], 'messages' => [],
    ];
});

afterEach(function () {
    if ($this->php80HadSession) {
        $_SESSION = $this->php80Session;
    } else {
        unset($_SESSION);
    }
    if ($this->php80HadDatechar) {
        $GLOBALS['datechar'] = $this->php80Datechar;
    } else {
        unset($GLOBALS['datechar']);
    }
    unset($GLOBALS['php80_state']);
});

$dateCases = [];
foreach (['m-d-Y', 'M-d-Y', 'd-m-Y', 'd-M-Y', 'Y-m-d', 'Y-M-d'] as $id => $format) {
    $dateCases[] = [$id, $format];
    $dateCases[] = [(string) $id, $format];
}
$dateCases = array_merge($dateCases, [[null, 'm-d-Y'], [false, 'm-d-Y'], [true, 'M-d-Y'],
    ['', 'Y-m-d'], ['unknown', 'Y-m-d'], [99, 'Y-m-d'], [' 2 ', 'd-m-Y']]);

test('date selectors preserve integer string and fallback settings', function ($setting, $expected) {
    $GLOBALS['php80_state']['options']['default_date_format'] = $setting;
    expect(Php80Modernization\date_time_format())->toBe($expected . ' H:i:s');
    $graph = ['graph_start' => 1767323045, 'graph_end' => 1767326645];
    $legend = Php80Modernization\rrdtool_function_format_graph_date($graph);
    $start = str_replace(':', '\\:', gmdate($expected . ' H:i:s', $graph['graph_start']));
    $end = str_replace(':', '\\:', gmdate($expected . ' H:i:s', $graph['graph_end']));
    expect($legend)->toContain('From ' . $start . ' To ' . $end);
})->with($dateCases);

test('date separators retain their fallback behavior', function ($separator, $expected) {
    $GLOBALS['php80_state']['options']['default_datechar'] = $separator;
    expect(Php80Modernization\date_time_format())->toBe('m' . $expected . 'd' . $expected . 'Y H:i:s');
})->with([[0, '-'], ['1', '/'], [2, '.'], [99, '/']]);

test('message formatting preserves numeric string and fallback levels', function ($level, $class) {
    $expected = $class === '' ? '<span>body</span>' : '<span class="' . $class . '">body</span>';
    expect(Php80Modernization\get_format_message_instance(['level' => $level, 'message' => 'body']))->toBe($expected);
})->with([[0, ''], ['0', ''], [1, 'deviceUp'], ['1', 'deviceUp'], [2, 'deviceWarning'],
    ['2', 'deviceWarning'], [3, 'deviceDown'], ['3', 'deviceDown'], [4, 'deviceDown'],
    ['4', 'deviceDown'], [false, ''], [true, 'deviceUp'], [99, 'deviceUnknown'], ['unknown', 'deviceUnknown']]);

test('ordinary regex matches and mismatches retain their validation behavior', function ($value, $error) {
    expect(Php80Modernization\form_input_validate($value, 'field', '^valid$', false))->toBe($value);
    expect(isset($_SESSION['sess_error_fields']['field']))->toBe($error);
    expect($GLOBALS['php80_state']['messages'])->toBe($error ? [3] : []);
    expect($GLOBALS['php80_state']['logs'])->toBe($error
        ? ["Form Validation Failed: Variable 'field' with Value 'invalid' Failed REGEX '^valid$'"] : []);
})->with([['valid', false], ['invalid', true]]);

test('regex diagnostics capture engine failure before configuration overwrites it', function ($pattern, $value, $reason) {
    $oldLimit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '10');
    try {
        // Suppress the existing malformed-pattern warning, not its validation result.
        expect(@Php80Modernization\form_input_validate($value, 'field', $pattern, false))->toBe($value);
        expect($_SESSION['sess_error_fields']['field'])->toBe('field');
        expect($GLOBALS['php80_state']['messages'])->toBe([3]);
        expect($GLOBALS['php80_state']['logs'][0])->toContain('(PCRE: ' . $reason . ')');
        expect(preg_last_error())->toBe(PREG_NO_ERROR);
    } finally {
        ini_set('pcre.backtrack_limit', $oldLimit);
    }
})->with([['[', 'value', 'Internal error'], ['^(a+)+$', str_repeat('a', 40) . '!', 'Backtrack limit exhausted']]);

test('disabled validation logging and allowed empty fields retain their behavior', function () {
    $GLOBALS['php80_state']['options']['log_validation'] = '';
    expect(@Php80Modernization\form_input_validate('value', 'field', '[', false))->toBe('value');
    expect($GLOBALS['php80_state']['logs'])->toBe([])->and($GLOBALS['php80_state']['traces'])->toBe([]);
    expect(Php80Modernization\form_input_validate('', 'empty', '[', true))->toBe('');
    expect(isset($_SESSION['sess_error_fields']['empty']))->toBeFalse();
});

test('log substring checks retain byte positions and case sensitivity', function ($line, $type, $expected) {
    expect(Php80Modernization\determine_display_log_entry($type, $line, ''))->toBe($expected);
})->with([['STATS', 1, true], ['prefix STATS suffix', 1, true], ['stats', 1, false],
    ['', 1, false], ['ERROR:', 4, true], ['DEBUG SQL statement', 6, false], ['DEBUG message', 6, true],
    [' SQL statement', 7, true], ['HOST EVENT', 11, true], ['prefix ] is down!', 11, true]]);

test('hexadecimal prefixes retain their spelling and by-reference output', function ($input, $expected, $output) {
    expect(Php80Modernization\is_hex_string($input))->toBe($expected)->and($input)->toBe($output);
})->with([['Hex- AB CD', true, 'AB CD'], ['Hex-String: AB CD', false, 'Hex-String: AB CD'],
    ['hex-', false, 'hex-'], ['prefix hex- AB CD', false, 'prefix hex- AB CD']]);

test('address and sensitive-key checks preserve embedded and initial markers', function () {
    expect(Php80Modernization\is_ipaddress('fe80::1%eth0'))->toBeTrue();
    expect(Php80Modernization\is_ipaddress('invalid%eth0'))->toBeFalse();
    expect(Php80Modernization\cacti_format_ipv6_colon('::1'))->toBe('[::1]');
    expect(Php80Modernization\cacti_format_ipv6_colon('[::1]'))->toBe('[::1]');
    expect(Php80Modernization\cacti_is_sensitive_key('prefix_PASSWORD_suffix'))->toBeTrue();
    expect(Php80Modernization\cacti_is_sensitive_key('ordinary'))->toBeFalse();
});
