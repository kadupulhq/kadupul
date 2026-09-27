<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

// bootstrap-unit.php loads only the test runner's autoloader; the application
// autoloader would bring a second PHPUnit into this process.
require_once dirname(__DIR__, 4) . '/src/Graphing/Domain/RrdCommand.php';
require_once dirname(__DIR__, 4) . '/src/Graphing/Infrastructure/Rrd/UnrepresentableArgument.php';
require_once dirname(__DIR__, 4) . '/src/Graphing/Infrastructure/Rrd/PipeEncoder.php';

use Kadupul\Graphing\Domain\RrdCommand;
use Kadupul\Graphing\Infrastructure\Rrd\PipeEncoder;
use Kadupul\Graphing\Infrastructure\Rrd\UnrepresentableArgument;

/** Send each line to one `rrdtool -` process and return one output line per command. */
function pipe_encoder_rrdtool(string $binary, array $lines): array
{
    $directory = sys_get_temp_dir() . '/rrd-pipe-encoder-' . bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    try {
        // English messages and no rrdcached, so every error names its argument the same way.
        $environment = array('PATH' => getenv('PATH'), 'LANG' => 'C', 'LC_ALL' => 'C');
        $process = proc_open(array($binary, '-'), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, $directory, $environment);
        fwrite($pipes[0], implode("\n", $lines) . "\n");
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
    } finally {
        rmdir($directory);
    }
    expect($errors)->toBe('');

    return explode("\n", rtrim($output, "\n"));
}

function pipe_encoder_binary($test): string
{
    $binary = getenv('RRDTOOL_TEST_BINARY');
    if (!$binary || !is_executable($binary)) {
        $test->markTestSkipped('RRDTOOL_TEST_BINARY is required');
    }

    return $binary;
}

dataset('pipe arguments', array(
    'plain' => array('traffic_in.rrd', "'traffic_in.rrd'"),
    'spaces kept as one argument' => array('a  b c', "'a  b c'"),
    'single quote' => array("it's", "'it'\"'\"'s'"),
    'only single quotes' => array("''", "''\"'\"''\"'\"''"),
    'double quote is literal inside single quotes' => array('say "hi"', "'say \"hi\"'"),
    'backslash is literal' => array('C:\\rra\\a.rrd \\', "'C:\\rra\\a.rrd \\'"),
    'backslash before a quote' => array("x\\'y", "'x\\'\"'\"'y'"),
    'tab' => array("a\tb", "'a\tb'"),
    'percent, colon, equals and pango markup' => array('50% a:b=c <b>&amp;</b>', "'50% a:b=c <b>&amp;</b>'"),
    'trailing multibyte text' => array('caf' . "\u{e9}", "'caf\u{e9}'"),
    'shell and option metacharacters' => array("x' --daemon 'unix:/tmp/s' \$(id) `id` ;|&", "'x'\"'\"' --daemon '\"'\"'unix:/tmp/s'\"'\"' \$(id) `id` ;|&'"),
    'empty' => array('', "''"),
));

test('arguments are single-quoted for the rrdtool pipe', function (string $argument, string $quoted) {
    expect((new PipeEncoder())->quote($argument))->toBe($quoted);
})->with('pipe arguments');

test('a command is the bare verb followed by quoted arguments', function () {
    $command = new RrdCommand('graph', array('-', '--title=Tom\'s "graph"', ''));
    expect((new PipeEncoder())->encode($command))->toBe("graph '-' '--title=Tom'\"'\"'s \"graph\"' ''");
    expect((new PipeEncoder())->encode(new RrdCommand('info', array())))->toBe('info');
});

test('arguments the pipe cannot carry are refused, not stripped', function (string $argument) {
    expect(fn() => (new PipeEncoder())->quote($argument))->toThrow(UnrepresentableArgument::class, 'cannot contain NUL, CR or LF');
    expect(fn() => (new PipeEncoder())->encode(new RrdCommand('info', array('ok', $argument))))->toThrow(UnrepresentableArgument::class);
})->with(array('line feed' => "a\nb", 'carriage return' => "a\rb", 'trailing CRLF' => "a\r\n", 'NUL' => "a\0b"));

test('a command holds a bare verb and a list of strings', function (string $verb, array $arguments) {
    expect(fn() => new RrdCommand($verb, $arguments))->toThrow(InvalidArgumentException::class);
})->with(array(
    'empty verb' => array('', array()),
    'verb with a space' => array('info x', array()),
    'quoted verb' => array("'info'", array()),
    'verb with a trailing newline' => array("info\n", array()),
    'uppercase verb' => array('INFO', array()),
    'keyed arguments' => array('info', array('file' => 'a.rrd')),
    'non-string argument' => array('info', array(1)),
));

test('rrdtool reads each quoted argument back unchanged', function (string $argument) {
    $binary = pipe_encoder_binary($this);
    $line = (new PipeEncoder())->encode(new RrdCommand('info', array($argument)));
    // rrdtool info names its file argument in the error, byte for byte.
    expect(pipe_encoder_rrdtool($binary, array($line)))->toBe(array("ERROR: opening '" . $argument . "': No such file or directory"));
})->with(array(
    'plain' => 'traffic_in.rrd',
    'spaces kept as one argument' => 'a  b c',
    'single quote' => "it's",
    'only single quotes' => "''",
    'double quote' => 'say "hi"',
    'backslash' => 'C:\\rra\\a.rrd \\',
    'backslash before a quote' => "x\\'y",
    'tab' => "a\tb",
    'percent, colon, equals and pango markup' => '50% a:b=c <b>&amp;</b>',
    'trailing multibyte text' => 'caf' . "\u{e9}",
    'shell and option metacharacters' => "x' --daemon 'unix:/tmp/s' \$(id) `id` ;|&",
    'empty' => '',
));

test('rrdtool tokenizes the pipe the way the encoder assumes', function () {
    $binary = pipe_encoder_binary($this);
    $missing = fn(string $name) => "ERROR: opening '" . $name . "': No such file or directory";
    expect(pipe_encoder_rrdtool($binary, array(
        'info a b',
        "info 'a b'",
        'info a"b c"d',
        "info 'x\"y'",
        "info a\\'b'",
        "info 'it'\\''s'",
        "info ''",
        "info 'unclosed",
        "info 'a\nb'",
        "info 'a\rb'",
        "   info   'lead'   ",
    )))->toBe(array(
        // Two arguments where info takes one.
        'ERROR: Usage: rrdtool info [--daemon |-d <addr> [--noflush|-F]] <file>',
        $missing('a b'),
        // Quoted runs join the text around them.
        $missing('ab cd'),
        $missing('x"y'),
        // Backslash escapes nothing.
        $missing('a\\b'),
        // The shell form of an embedded quote leaves the line unbalanced.
        'ERROR: creating arguments',
        $missing(''),
        'ERROR: creating arguments',
        // A newline ends the command, leaving two unbalanced lines.
        'ERROR: creating arguments',
        'ERROR: creating arguments',
        // A quoted CR survives; the encoder still refuses it for the proxy.
        $missing("a\rb"),
        $missing('lead'),
    ));

    // After a NUL, rrdtool drops the rest of that line and joins the next
    // line onto the same command.
    expect(pipe_encoder_rrdtool($binary, array("info 'x\0' --daemon 'junk", "b'", "info 'next'")))->toBe(array($missing('xb'), $missing('next')));

    // Line trimming reaches into an unquoted last argument, including bytes
    // above 0x7f where char is signed; quoting keeps them on every platform.
    expect(pipe_encoder_rrdtool($binary, array('info caf' . "\u{e9}"))[0])->toBeIn(array($missing('caf'), $missing('caf' . "\u{e9}")));

    // A tab does not separate arguments, so "info\ta" is an unknown verb and
    // rrdtool prints its usage text instead of opening a file.
    $tab = pipe_encoder_rrdtool($binary, array("info\ta"));
    expect(implode("\n", $tab))->not->toContain('ERROR: opening')->toContain('Usage: rrdtool');
});
