<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace PerlScriptShellArgumentTest;

/*
 * The poller hands a Data Source input value to webhits.pl and diskfree.pl
 * as one shell-quoted argument, and both scripts paste it into a command
 * string that Perl runs through a second shell. The scripts must refuse an
 * argument carrying shell metacharacters, and give the same output as
 * before for every other argument.
 */

function run_perl(string $script, string $argument): string
{
    $process = proc_open(
        ['perl', dirname(__DIR__, 3) . '/scripts/' . $script, $argument],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $output;
}

beforeEach(function () {
    if (trim((string) shell_exec('command -v perl')) === '') {
        $this->markTestSkipped('perl is not installed');
    }

    $this->dir = sys_get_temp_dir() . '/perl-arg-' . bin2hex(random_bytes(4));
    $this->marker = $this->dir . '/second-shell';

    mkdir($this->dir, 0700);
    file_put_contents($this->dir . '/access_log', "a\nb\nc\n");
    file_put_contents($this->dir . '/access log', "a\nb\n");
});

afterEach(function () {
    foreach (['second-shell', 'access_log', 'access log'] as $name) {
        if (file_exists($this->dir . '/' . $name)) {
            unlink($this->dir . '/' . $name);
        }
    }

    rmdir($this->dir);
});

dataset('shell payloads', [
    'semicolon' => ['%s; touch %s'],
    'command substitution' => ['%s$(touch %s)'],
    'backticks' => ['%s`touch %s`'],
    'pipe' => ['%s | touch %s'],
    'and list' => ['%s && touch %s'],
    'redirect' => ['%s > %s'],
    'newline' => ["%s\ntouch %s"],
]);

test('webhits.pl refuses an argument that would run a second command', function (string $payload) {
    $output = run_perl('webhits.pl', sprintf($payload, $this->dir . '/access_log', $this->marker));

    expect(file_exists($this->marker))->toBeFalse()
        ->and($output)->toBe('');
})->with('shell payloads');

test('diskfree.pl refuses an argument that would run a second command', function (string $payload) {
    $output = run_perl('diskfree.pl', sprintf($payload, '/', $this->marker));

    expect(file_exists($this->marker))->toBeFalse()
        ->and($output)->toBe('');
})->with('shell payloads');

test('webhits.pl still counts lines for plain and quoted paths', function () {
    expect(run_perl('webhits.pl', $this->dir . '/access_log'))->toBe('3')
        ->and(run_perl('webhits.pl', '"' . $this->dir . '/access log"'))->toBe('2')
        ->and(run_perl('webhits.pl', "'" . $this->dir . "/access log'"))->toBe('2')
        ->and(run_perl('webhits.pl', $this->dir . '/missing_log'))->toBe('');
});
