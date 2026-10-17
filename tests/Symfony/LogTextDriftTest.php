<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Helpers/PhpSource.php';

/**
 * lib/functions.php is frozen, so cacti_log() keeps its own copy of the
 * LogText rule. The production cacti_log() and clean_up_lines() run in a
 * child process, the way tests/Unit/Core/Log/CactiLogControlCharacterTest.php
 * runs them, and every line they write must equal what LogText gives for the
 * same input under the same PCRE settings.
 */
final class LogTextDriftTest extends TestCase
{
    #[DataProvider('pcreSettings')]
    public function testCactiLogAndLogTextNeutralizeAlike(array $ini): void
    {
        $corpus = self::corpus();
        $results = $this->logAll($corpus, $ini);
        self::assertCount(count($corpus), $results);

        foreach ($corpus as $index => $text) {
            $result = $results[$index];
            $environ = base64_decode($result['environ'], true);
            $message = base64_decode($result['message'], true);
            $label = sprintf('case %d: %s', $index, bin2hex(substr($text, 0, 64)));

            // cacti_log() prints "DATE - <environ> <clean_up_lines(message)>".
            self::assertSame('DATE - ' . $environ . ' ' . base64_decode($result['cleaned'], true) . PHP_EOL, base64_decode($result['file'], true), $label);
            self::assertSame([$environ . ': ' . $message], array_map(static fn(string $line): string => (string) base64_decode($line, true), $result['syslog']), $label);
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function pcreSettings(): iterable
    {
        yield 'default' => [[]];
        yield 'PCRE failure' => [['pcre.jit' => '0', 'pcre.backtrack_limit' => '1']];
    }

    /** @return list<string> */
    private static function corpus(): array
    {
        $corpus = [''];
        foreach ([LogTextTest::lineBreaks(), LogTextTest::escapes(), LogTextTest::unchanged()] as $cases) {
            foreach ($cases as $case) {
                $corpus[] = $case[0];
            }
        }
        $pieces = ["\r", "\n", "\r\n", "\t", "\x00", "\x1b[31m", "\x7f", "\x0b", "\x0c", "\xc2\x80", "\xc2\x9b", "\xc2\x9f", "\xc2\xa0",
            "\xd8\x9c", "\xd8\x9b", "\xe2\x80\x8d", "\xe2\x80\x8e", "\xe2\x80\x8f", "\xe2\x80\xa8", "\xe2\x80\xa9", "\xe2\x80\xae", "\xe2\x80\xaf",
            "\xe2\x81\xa6", "\xe2\x81\xa9", "\xe2\x81\xaa", "\xff", "\xc3", "\xe2\x80", "\x9b", 'é', '日本', "\u{1F600}", ' ', '  ', 'ERROR: ', 'x'];
        mt_srand(899);
        for ($i = 0; $i < 300; $i++) {
            $text = '';
            for ($n = mt_rand(1, 24); $n > 0; $n--) {
                $text .= $pieces[mt_rand(0, count($pieces) - 1)];
            }
            $corpus[] = $text;
        }
        for ($i = 0; $i < 50; $i++) {
            $text = '';
            for ($n = mt_rand(1, 48); $n > 0; $n--) {
                $text .= chr(mt_rand(0, 255));
            }
            $corpus[] = $text;
        }
        $corpus[] = str_repeat("a\r\n\x00\xe2\x80\xae\xd8\x9c ", 20000);
        $corpus[] = str_repeat('a', 65536) . "\n\x1b";

        return $corpus;
    }

    /**
     * @param list<string> $corpus
     * @param array<string, string> $ini
     * @return list<array{environ: string, message: string, cleaned: string, file: string, syslog: list<string>}>
     */
    private function logAll(array $corpus, array $ini): array
    {
        $root = dirname(__DIR__, 2);
        $source = file_get_contents($root . '/lib/functions.php');
        self::assertIsString($source);
        $functions = test_php_function_source($source, 'cacti_log') . "\n" . test_php_function_source($source, 'clean_up_lines');

        $input = tempnam(sys_get_temp_dir(), 'kdrift');
        self::assertIsString($input);
        file_put_contents($input, json_encode(array_map('base64_encode', $corpus), JSON_THROW_ON_ERROR));

        $program = '$corpus = array_map("base64_decode", json_decode(file_get_contents(' . var_export($input, true) . '), true));'
            . 'require ' . var_export($root . '/src/Platform/Infrastructure/Legacy/LogText.php', true) . ';'
            . <<<'CODE'
define('POLLER_VERBOSITY_NONE', 1);
define('POLLER_VERBOSITY_LOW', 2);
define('POLLER_VERBOSITY_DEVDBG', 6);
define('CACTI_DATE_TIME_FORMAT', '\D\A\T\E');
$config = ['poller_id' => 1, 'cacti_server_os' => 'unix'];
$database_log = false;
$GLOBALS['logfile'] = tempnam(sys_get_temp_dir(), 'clog');
function get_selective_log_level() { return ''; }
function read_config_option($name) { return $name == 'log_destination' ? 2 : ($name == 'log_verbosity' ? 2 : 'on'); }
function cacti_system_zone_set() {}
function cacti_browser_zone_set() {}
function cacti_log_file() { return $GLOBALS['logfile']; }
CODE;
        // syslog() is built in, so the production source runs in a namespace
        // whose syslog() records the payload. Only repository source is evaluated.
        $program .= 'eval(' . var_export("namespace CactiLogDrift;\n"
            . 'function openlog(...$args) { return true; }' . "\n"
            . 'function closelog() { return true; }' . "\n"
            . 'function syslog($priority, $message) { $GLOBALS["syslog"][] = $message; return true; }' . "\n"
            . $functions, true) . ');';
        $program .= <<<'CODE'
$neutralize = [\Kadupul\Platform\Infrastructure\Legacy\LogText::class, 'neutralize'];
$out = [];
foreach ($corpus as $text) {
    $environ = $text;
    $message = 'ERROR: ' . $text;
    file_put_contents($GLOBALS['logfile'], '');
    $GLOBALS['syslog'] = [];
    \CactiLogDrift\cacti_log($message, false, $environ);
    $out[] = [
        'environ' => base64_encode($neutralize($environ)),
        'message' => base64_encode($neutralize($message)),
        'cleaned' => base64_encode($neutralize((string) \CactiLogDrift\clean_up_lines($message))),
        'file'    => base64_encode(file_get_contents($GLOBALS['logfile'])),
        'syslog'  => array_map('base64_encode', $GLOBALS['syslog']),
    ];
}
unlink($GLOBALS['logfile']);
echo json_encode($out);
CODE;

        $command = [PHP_BINARY, '-d', 'error_reporting=-1'];
        foreach ($ini as $name => $value) {
            $command[] = '-d';
            $command[] = $name . '=' . $value;
        }
        $command[] = '-r';
        $command[] = $program;
        try {
            $process = test_php_run($command);
        } finally {
            unlink($input);
        }
        self::assertSame(0, $process['status'], $process['err'] . substr($process['out'], 0, 2000));

        return json_decode($process['out'], true, 512, JSON_THROW_ON_ERROR);
    }
}
