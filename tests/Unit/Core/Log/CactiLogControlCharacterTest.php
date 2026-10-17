<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

/*
 * cacti_log() writes to the log file, standard output and syslog. A line
 * feed in $environ must not start a second record, and control characters
 * in either argument must reach none of the three sinks as raw bytes. The
 * production functions run in a child process with syslog() captured.
 */

require_once dirname(__DIR__, 3) . '/Helpers/ClogProductionFunctions.php';

function cactiLogControlProgram(): string
{
    $functions = clogProductionFunction('lib/functions.php', 'cacti_log')
        . "\n" . clogProductionFunction('lib/functions.php', 'clean_up_lines');

    // An unfixed tree has no helper; cacti_log() then simply does not call it.
    try {
        $functions .= "\n" . clogProductionFunction('lib/functions.php', 'cacti_log_neutralize');
    } catch (RuntimeException $e) {
    }

    $program = <<<'CODE'
$GLOBALS['warnings'] = array();
set_error_handler(function ($level, $message) { $GLOBALS['warnings'][] = $message; return true; });
define('POLLER_VERBOSITY_NONE', 1);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEVDBG', 6);
define('CACTI_DATE_TIME_FORMAT', '\D\A\T\E');
$config = array('poller_id' => 1, 'cacti_server_os' => 'unix');
$database_log = false;
$_SERVER['argv'] = array('cacti_log_test');
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
$results = array();
foreach ($input['cases'] as $case) {
	if (isset($case['backtrack_limit'])) {
		ini_set('pcre.jit', '0');
		ini_set('pcre.backtrack_limit', $case['backtrack_limit']);
	}
	file_put_contents($GLOBALS['logfile'], '');
	$GLOBALS['syslog'] = array();
	$GLOBALS['warnings'] = array();
	$environ = $case['environ'] === null ? null : ($case['environ'] === 'array' ? array('x') : ($case['environ'] === 'object' ? new stdClass() : base64_decode($case['environ'])));
	ob_start();
	$return = \CactiLogTest\cacti_log(base64_decode($case['message']), true, $environ, $case['level'] ?? '');
	$stdout = ob_get_clean();
	$results[] = array(
		'return'   => $return,
		'file'     => base64_encode(file_get_contents($GLOBALS['logfile'])),
		'stdout'   => base64_encode($stdout),
		'syslog'   => array_map('base64_encode', $GLOBALS['syslog']),
		'warnings' => $GLOBALS['warnings'],
	);
	ini_restore('pcre.backtrack_limit');
	ini_restore('pcre.jit');
}
unlink($GLOBALS['logfile']);
echo json_encode($results);
CODE;

    return $program;
}

function cactiLogControlRun(string $message, $environ = 'CMDPHP', array $options = array()): array
{
    $case = array('message' => base64_encode($message), 'environ' => in_array($environ, array(null, 'array', 'object'), true) ? $environ : base64_encode($environ)) + $options;
    $result = clogRunProduction(cactiLogControlProgram(), array('cases' => array($case)))[0];

    return array(
        'return'   => $result['return'],
        'file'     => base64_decode($result['file']),
        'stdout'   => base64_decode($result['stdout']),
        'syslog'   => array_map('base64_decode', $result['syslog']),
        'warnings' => $result['warnings'],
    );
}

function expectCactiLogSinks(array $result, string $environ, string $message): void
{
    expect($result['file'])->toBe('DATE - ' . $environ . ' ' . $message . PHP_EOL)
        ->and($result['stdout'])->toBe('DATE - ' . $environ . ' ' . $message . PHP_EOL)
        ->and($result['syslog'])->toBe(array($environ . ': ' . $message));
}

test('a line feed in environ cannot start a second log record', function () {
    $result = cactiLogControlRun('ERROR: msg', "X\n2026-01-01 00:00:00 - FORGED");

    expectCactiLogSinks($result, 'X 2026-01-01 00:00:00 - FORGED', 'ERROR: msg');
    expect(substr_count($result['file'], "\n"))->toBe(1);
});

test('carriage returns in environ become spaces', function (string $environ, string $expected) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: msg', $environ), $expected, 'ERROR: msg');
})->with(array(
    'CR'   => array("X\rY", 'X Y'),
    'CRLF' => array("X\r\nY", 'X  Y'),
));

test('C0 controls and DEL are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with(array(
    'NUL'          => array("\x00", '\x00'),
    'ESC sequence' => array("\x1b[31mred\x1b[0m", '\x1b[31mred\x1b[0m'),
    'bell'         => array("\x07", '\x07'),
    'vertical tab' => array("\x0b", '\x0b'),
    'form feed'    => array("\x0c", '\x0c'),
    'unit sep'     => array("\x1f", '\x1f'),
    'DEL'          => array("\x7f", '\x7f'),
));

test('C1 controls are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with(array(
    'U+0080' => array("\u{0080}", '\u0080'),
    'U+0085' => array("\u{0085}", '\u0085'),
    'U+009B' => array("\u{009B}", '\u009b'),
    'U+009F' => array("\u{009F}", '\u009f'),
));

test('bidirectional controls are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with(array(
    'U+200E' => array("\u{200E}", '\u200e'),
    'U+200F' => array("\u{200F}", '\u200f'),
    'U+061C' => array("\u{061C}", '\u061c'),
    'U+202A' => array("\u{202A}", '\u202a'),
    'U+202B' => array("\u{202B}", '\u202b'),
    'U+202C' => array("\u{202C}", '\u202c'),
    'U+202D' => array("\u{202D}", '\u202d'),
    'U+202E' => array("\u{202E}", '\u202e'),
    'U+2066' => array("\u{2066}", '\u2066'),
    'U+2067' => array("\u{2067}", '\u2067'),
    'U+2068' => array("\u{2068}", '\u2068'),
    'U+2069' => array("\u{2069}", '\u2069'),
));

test('line and paragraph separators are written as visible escapes', function (string $raw, string $escaped) {
    expectCactiLogSinks(cactiLogControlRun('ERROR: a' . $raw . 'b', 'ENV' . $raw), 'ENV' . $escaped, 'ERROR: a' . $escaped . 'b');
})->with(array(
    'U+2028' => array("\u{2028}", '\u2028'),
    'U+2029' => array("\u{2029}", '\u2029'),
));

test('a tab is kept in the message and in environ', function () {
    expectCactiLogSinks(cactiLogControlRun("ERROR: a\tb", "E\tV"), "E\tV", "ERROR: a\tb");
});

test('ordinary multibyte text is written byte for byte', function () {
    // Includes the neighbours of every escaped sequence: ZWJ U+200D, U+2010,
    // U+2027, U+202F, U+2065, U+206A, NBSP U+00A0, U+061B and U+061D.
    $text = "ERROR: café naïve Ångström Ελληνικά Русский 日本語 中文 한국어 العربية עברית "
        . "😀 👍🏽 👨\u{200D}👩\u{200D}👧 \u{2010}\u{2027}\u{202F}\u{2065}\u{206A}\u{00A0}\u{061B}\u{061D}";

    expectCactiLogSinks(cactiLogControlRun($text, 'ÉNV😀'), 'ÉNV😀', $text);
});

test('invalid UTF-8 is written unchanged and does not drop the line', function () {
    $text = "ERROR: a\xff\xfeb\xc2 \xe2\x80";

    expectCactiLogSinks(cactiLogControlRun($text, "E\xffV"), "E\xffV", $text);
});

// pcre.backtrack_limit=1 with JIT off makes every match fail. The raw ESC in
// the result shows the failure path ran; the LF must still become a space.
test('a regex failure still turns CR and LF in environ into spaces', function () {
    $result = cactiLogControlRun('ERROR: msg', "CMD\x1b\r\nPHP", array('backtrack_limit' => '1'));

    expect($result['file'])->toBe("DATE - CMD\x1b  PHP " . PHP_EOL);
});

// clean_up_lines() returns null under the same limit.
test('a null from clean_up_lines raises no diagnostic', function () {
    $result = cactiLogControlRun('ERROR: msg', 'CMDPHP', array('backtrack_limit' => '1'));

    expect($result['warnings'])->toBe(array())
        ->and($result['file'])->toBe('DATE - CMDPHP ' . PHP_EOL);
});

test('an array or null environ is converted as before', function () {
    $array = cactiLogControlRun('ERROR: msg', 'array');
    $null  = cactiLogControlRun('ERROR: msg', null);

    expectCactiLogSinks($array, 'Array', 'ERROR: msg');
    // The unfixed code warned once per sink that concatenated environ.
    expect(array_values(array_unique($array['warnings'])))->toBe(array('Array to string conversion'));
    expectCactiLogSinks($null, '', 'ERROR: msg');
    expect($null['warnings'])->toBe(array());
});

test('a message that is empty after trimming still returns false', function () {
    $result = cactiLogControlRun(" \n\t ", "X\n");

    expect($result['return'])->toBeFalse()
        ->and($result['file'])->toBe('')
        ->and($result['stdout'])->toBe('')
        ->and($result['syslog'])->toBe(array());
});

// These return before environ is used, as the unfixed code did.
test('a gated call with an array environ raises no warning', function () {
    $result = cactiLogControlRun('ERROR: msg', 'array', array('level' => 5));

    expect($result['return'])->toBeNull()
        ->and($result['file'])->toBe('')
        ->and($result['warnings'])->toBe(array());
});

test('an empty message with an object environ returns false without an error', function () {
    $result = cactiLogControlRun(' ', 'object');

    expect($result['return'])->toBeFalse()
        ->and($result['file'])->toBe('')
        ->and($result['warnings'])->toBe(array());
});

test('the POLLER prefix is kept for a clean POLLER environ', function () {
    $result = cactiLogControlRun('ERROR: msg', 'POLLER');

    expect($result['file'])->toMatch('/^DATE - POLLER: Poller\[1\] PID\[\d+\] ERROR: msg\n$/');
});
