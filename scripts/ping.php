<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Ported from scripts/ping.pl; prints the same output for the same ping output.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/script_command.php';

/** Seconds each of ping and ping6 may run; the Perl script waited indefinitely. */
const PING_TIMEOUT = 5.0;

const PING_UNRESOLVED = ['unknown host', 'Unknown host', 'not supported', 'Name or service not known', 'No address associated with name'];

/**
 * Applies ping.pl's host clean-up and allow-list, or returns null when the
 * Perl script would have pinged nothing. ping.pl only stripped a :port from
 * hosts without a colon, which can never match, so host:port is kept and
 * reports U. A leading dash is refused so the host cannot become a ping option.
 */
function ping_host(string $argument): ?string
{
    $host = preg_replace('/(?:tcp|udp)6?:/i', '', $argument);
    if (preg_match('/^([\w.:-]+)$/', $host, $match) !== 1 || str_starts_with($match[1], '-')) {
        return null;
    }

    return $match[1];
}

/** @return list<string> */
function ping_command(string $host, string $os = PHP_OS_FAMILY): array
{
    return $os === 'Solaris' ? ['ping', '-s', $host, '64', '1'] : ['ping', '-c', '1', $host];
}

/** The first line matching $pattern, as `grep | head -1` and chomp() left it. */
function ping_first_line(string|false $output, string $pattern): string
{
    foreach (explode("\n", (string) $output) as $line) {
        if (preg_match($pattern, $line) === 1) {
            return $line;
        }
    }

    return '';
}

/** Perl's string-to-number conversion: the leading numeric part, else 0. */
function ping_perl_number(string $text): float
{
    if (preg_match('/^\s*([+-]?)(inf(?:inity)?|nan)/i', $text, $special) === 1) {
        $value = strtolower($special[2]) === 'nan' ? NAN : INF;

        return $special[1] === '-' ? -$value : $value;
    }
    if (preg_match('/^\s*([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $text, $number) === 1) {
        return (float) $number[1];
    }

    return 0.0;
}

/** Formats a number the way Perl prints one, which is C's %.15g. */
function ping_format_number(float $value): string
{
    if (is_nan($value)) {
        return 'NaN';
    }
    if (is_infinite($value)) {
        return $value > 0 ? 'Inf' : '-Inf';
    }
    if ($value == 0.0) {
        return '0';
    }

    [$mantissa, $exponent] = explode('e', sprintf('%.14e', $value));
    $exponent = (int) $exponent;
    if ($exponent < -4 || $exponent >= 15) {
        $mantissa = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

        return $mantissa . 'e' . ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
    }

    $fixed = sprintf('%.' . (14 - $exponent) . 'f', $value);

    return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
}

/** Turns the selected ping line into ping.pl's output: milliseconds, or U. */
function ping_value(string $line): string
{
    // ping.pl compared the time numerically with '', so a zero time also reports U.
    if (preg_match('/(.*time=)(.*) (ms|usec)/', $line, $match) !== 1 || ping_perl_number($match[2]) == 0) {
        return 'U';
    }

    return $match[3] === 'usec' ? ping_format_number(ping_perl_number($match[2]) / 1000) : $match[2];
}

/**
 * @param callable(list<string>): (string|false) $run runs a command with standard error merged
 */
function ping_measure(string $argument, callable $run, bool $hasPing6, string $os = PHP_OS_FAMILY): string
{
    $host = ping_host($argument);
    if ($host === null) {
        return 'U';
    }

    $line = ping_first_line($run(ping_command($host, $os)), '/(icmp_[s|r]eq.*time|' . implode('|', PING_UNRESOLVED) . ')/');
    $unresolved = array_filter(PING_UNRESOLVED, static fn(string $message): bool => str_contains($line, $message));
    if ($unresolved !== [] && $hasPing6) {
        $line = ping_first_line($run(['ping6', '-c', '1', $host]), '/icmp_[s|r]eq.*time/');
    }

    return ping_value($line);
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    print ping_measure(
        (string) ($_SERVER['argv'][1] ?? ''),
        static fn(array $command): string|false => script_command_output($command, ['LANG' => 'en_US.UTF-8'], PING_TIMEOUT, true),
        is_file('/bin/ping6') || is_file('/sbin/ping6'),
    );
}
