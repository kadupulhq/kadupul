<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-2.0-or-later

// Run exact production log viewer functions in a fresh PHP process so page
// bootstrap and the database stay out of the test.
function clogProductionFunction(string $file, string $name): string
{
    $source = file_get_contents(dirname(__DIR__, 2) . '/' . $file);
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();

    foreach ($parser->parse($source) as $node) {
        if ($node instanceof PhpParser\Node\Stmt\Function_ && $node->name->toString() === $name) {
            return substr($source, $node->getStartFilePos(), $node->getEndFilePos() - $node->getStartFilePos() + 1);
        }
    }

    throw new RuntimeException('Missing production function: ' . $name);
}

function clogRunProduction(string $program, array $input): array
{
    $program = 'set_error_handler(function ($level, $message) { throw new RuntimeException($message); });'
        . '$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);' . $program;
    $process = proc_open([PHP_BINARY, '-d', 'error_reporting=-1', '-r', $program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0, $error . $output);

    return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
}
