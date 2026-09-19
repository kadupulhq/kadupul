<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * PHP_OS cannot be changed at runtime, so this test runs the SunOS branch
 * of Net_Ping::ping_icmp() on its own: the statement is read from
 * lib/ping.php and evaluated against a stand-in ping object, with
 * shell_exec() captured in this namespace.
 */

namespace PingSolarisProbeBoundTest;

function cacti_escapeshellarg($arg)
{
    return escapeshellarg($arg);
}

function shell_exec($command)
{
    $GLOBALS['ping_solaris_commands'][] = $command;

    return '';
}

final class SolarisPing
{
    public array $host = ['hostname' => '192.0.2.10'];
    public int $timeout = 400;
    public int $retries = 3;
}

function solaris_branch_statement(): string
{
    $source = file_get_contents(__DIR__ . '/../../../../lib/ping.php');

    if (!preg_match("/substr_count\\(strtolower\\(PHP_OS\\), 'sun'\\)\\) \\{(.*?)\\} elseif/s", $source, $match)) {
        throw new \RuntimeException('SunOS branch not found in lib/ping.php');
    }

    return $match[1];
}

beforeEach(function () {
    $GLOBALS['ping_solaris_commands'] = [];
});

test('the SunOS ICMP branch bounds the probe count and asks for statistics', function () {
    $statement = solaris_branch_statement();

    /* eval() runs one statement read from lib/ping.php in this repository,
     * never external input. */
    $run = \Closure::bind(function () use ($statement) {
        eval('namespace ' . __NAMESPACE__ . '; ' . $statement); // nosemgrep: php.lang.security.eval-use.eval-use
    }, new SolarisPing(), SolarisPing::class);

    $run();

    expect($GLOBALS['ping_solaris_commands'])->toBe(["ping -s '192.0.2.10' 56 3"]);
});
