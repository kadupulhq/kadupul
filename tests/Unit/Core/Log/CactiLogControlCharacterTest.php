<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace CactiLogControlCharacterTest;

/*
 * cacti_log() writes to the log file, standard output and syslog. A line
 * feed in $environ must not start a second record, and control characters
 * in either argument must reach none of the three sinks as raw bytes. The
 * production function runs in a child process with syslog() captured.
 */

require_once dirname(__DIR__, 3) . '/Helpers/PhpSource.php';

function cactiLogControlProgram(array $case): string
{
    $source = file_get_contents(dirname(__DIR__, 4) . '/lib/functions.php');
    expect($source)->not->toBeFalse();

    $functions = test_php_function_source($source, 'cacti_log') . "\n" . test_php_function_source($source, 'clean_up_lines');

    $program = '$case = ' . var_export($case, true) . ';' . <<<'CODE'
$GLOBALS['warnings'] = [];
set_error_handler(function ($level, $message) { $GLOBALS['warnings'][] = $message; return true; });
define('POLLER_VERBOSITY_NONE', 1);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEVDBG', 6);
define('CACTI_DATE_TIME_FORMAT', '\D\A\T\E');
$config = ['poller_id' => 1, 'cacti_server_os' => 'unix'];
$database_log = false;
$_SERVER['argv'] = ['cacti_log_test'];
$GLOBALS['logfile'] = tempnam(sys_get_temp_dir(), 'clog');
function get_selective_log_level() { return ''; }
function read_config_option($name) { return $name == 'log_destination' ? 2 : ($name == 'log_verbosity' ? 2 : 'on'); }
function cacti_system_zone_set() {}
function cacti_browser_zone_set() {}
function cacti_log_file() { return $GLOBALS['logfile']; }
CODE;

    // syslog() is built in, so the production source runs in a namespace whose
    // syslog() records the payload. Only repository source is evaluated.
    $program .= 'eval(' . var_export("namespace CactiLogTest;\n"
        . 'function openlog(...$args) { return true; }' . "\n"
        . 'function closelog() { return true; }' . "\n"
        . 'function syslog($priority, $message) { $GLOBALS["syslog"][] = $message; return true; }' . "\n"
        . $functions, true) . ');';

    $program .= <<<'CODE'
if (isset($case['backtrack_limit'])) {
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', $case['backtrack_limit']);
}
$GLOBALS['syslog'] = [];
$environ = $case['environ'] === null ? null : ($case['environ'] === 'array' ? ['x'] : base64_decode($case['environ']));
ob_start();
$return = \CactiLogTest\cacti_log(base64_decode($case['message']), true, $environ);
$stdout = ob_get_clean();
echo json_encode([
    'return'   => $return,
    'file'     => base64_encode(file_get_contents($GLOBALS['logfile'])),
    'stdout'   => base64_encode($stdout),
    'syslog'   => array_map('base64_encode', $GLOBALS['syslog']),
    'warnings' => $GLOBALS['warnings'],
]);
unlink($GLOBALS['logfile']);
CODE;

    return $program;
}

function cactiLogControlRun(string $message, $environ = 'CMDPHP', array $options = []): array
{
    $case = ['message' => base64_encode($message), 'environ' => $environ === null || $environ === 'array' ? $environ : base64_encode($environ)] + $options;
    $process = test_php_run([PHP_BINARY, '-d', 'error_reporting=-1', '-r', cactiLogControlProgram($case)]);
    expect($process['status'])->toBe(0, $process['err'] . $process['out']);
    $result = json_decode($process['out'], true, 512, JSON_THROW_ON_ERROR);

    return [
        'return'   => $result['return'],
        'file'     => base64_decode($result['file']),
        'stdout'   => base64_decode($result['stdout']),
        'syslog'   => array_map('base64_decode', $result['syslog']),
        'warnings' => $result['warnings'],
    ];
}

function expectCactiLogSinks(array $result, string $environ, string $message): void
{
    expect($result['file'])->toBe('DATE - ' . $environ . ' ' . $message . PHP_EOL)
        ->and($result['stdout'])->toBe('DATE - ' . $environ . ' ' . $message . PHP_EOL)
        ->and($result['syslog'])->toBe([$environ . ': ' . $message]);
}

test('a line feed in environ cannot start a second log record', function () {
    $result = cactiLogControlRun('ERROR: msg', "X\n2026-01-01 00:00:00 - FORGED");

    expectCactiLogSinks($result, 'X 2026-01-01 00:00:00 - FORGED', 'ERROR: msg');
    expect(substr_count($result['file'], "\n"))->toBe(1);
});

test('carriage returns in environ become spaces', function (string $environ, string $expected) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: msg', $environ), $expected, 'ERROR: msg');
})->with([
    'CR'   => ["X\rY", 'X Y'],
    'CRLF' => ["X\r\nY", 'X  Y'],
]);

test('C0 controls and DEL are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with([
    'NUL'          => ["\x00", '\x00'],
    'ESC sequence' => ["\x1b[31mred\x1b[0m", '\x1b[31mred\x1b[0m'],
    'bell'         => ["\x07", '\x07'],
    'unit sep'     => ["\x1f", '\x1f'],
    'DEL'          => ["\x7f", '\x7f'],
]);

test('C1 controls are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with([
    'U+0080' => ["\u{0080}", '\u0080'],
    'U+0085' => ["\u{0085}", '\u0085'],
    'U+009B' => ["\u{009B}", '\u009b'],
    'U+009F' => ["\u{009F}", '\u009f'],
]);

test('bidirectional controls are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with([
    'U+200E' => ["\u{200E}", '\u200e'],
    'U+200F' => ["\u{200F}", '\u200f'],
    'U+061C' => ["\u{061C}", '\u061c'],
    'U+202A' => ["\u{202A}", '\u202a'],
    'U+202B' => ["\u{202B}", '\u202b'],
    'U+202C' => ["\u{202C}", '\u202c'],
    'U+202D' => ["\u{202D}", '\u202d'],
    'U+202E' => ["\u{202E}", '\u202e'],
    'U+2066' => ["\u{2066}", '\u2066'],
    'U+2067' => ["\u{2067}", '\u2067'],
    'U+2068' => ["\u{2068}", '\u2068'],
    'U+2069' => ["\u{2069}", '\u2069'],
]);

test('a tab is kept in the message and in environ', function () {
    expectCactiLogSinks(cactiLogControlRun("ERROR: a\tb", "E\tV"), "E\tV", "ERROR: a\tb");
});

test('ordinary multibyte text is written byte for byte', function () {
    // Includes the neighbours of every escaped sequence: ZWJ U+200D, U+2010,
    // U+2028, U+202F, U+2065, U+206A, NBSP U+00A0, U+061B and U+061D.
    $text = "ERROR: café naïve Ångström Ελληνικά Русский 日本語 中文 한국어 العربية עברית "
        . "😀 👍🏽 👨\u{200D}👩\u{200D}👧 \u{2010}\u{2028}\u{202F}\u{2065}\u{206A}\u{00A0}\u{061B}\u{061D}";

    expectCactiLogSinks(cactiLogControlRun($text, 'ÉNV😀'), 'ÉNV😀', $text);
});

test('invalid UTF-8 is written unchanged and does not drop the line', function () {
    $text = "ERROR: a\xff\xfeb\xc2 \xe2\x80";

    expectCactiLogSinks(cactiLogControlRun($text, "E\xffV"), "E\xffV", $text);
});

// clean_up_lines() already loses the message under this limit; the
// environ shows that a failed match keeps the original text.
test('a regex failure keeps environ unneutralized rather than dropping it', function () {
    $result = cactiLogControlRun('ERROR: msg', "CMD\x1bPHP", ['backtrack_limit' => '1']);

    expect($result['file'])->toBe("DATE - CMD\x1bPHP " . PHP_EOL);
});

test('an array or null environ is converted as before', function () {
    $array = cactiLogControlRun('ERROR: msg', 'array');
    $null  = cactiLogControlRun('ERROR: msg', null);

    expectCactiLogSinks($array, 'Array', 'ERROR: msg');
    // The unfixed code warned once per sink that concatenated environ.
    expect(array_values(array_unique($array['warnings'])))->toBe(['Array to string conversion']);
    expectCactiLogSinks($null, '', 'ERROR: msg');
    expect($null['warnings'])->toBe([]);
});

test('a message that is empty after trimming still returns false', function () {
    $result = cactiLogControlRun(" \n\t ", "X\n");

    expect($result['return'])->toBeFalse()
        ->and($result['file'])->toBe('')
        ->and($result['stdout'])->toBe('')
        ->and($result['syslog'])->toBe([]);
});

test('the POLLER prefix is kept for a clean POLLER environ', function () {
    $result = cactiLogControlRun('ERROR: msg', 'POLLER');

    expect($result['file'])->toMatch('/^DATE - POLLER: Poller\[1\] PID\[\d+\] ERROR: msg\n$/');
});
