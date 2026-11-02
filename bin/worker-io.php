<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

// Shared input and output steps of the bin/legacy-*.php workers. Plain PHP with
// no autoloader, so a worker may load it before its bootstrap. The name is not
// legacy-*.php, so the worker runner refuses to start it as a worker.

namespace Kadupul\LegacyWorker;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Reads one JSON command of at most $maxBytes bytes from $stream.
 *
 * Each worker passes its own cap, depth and oversize exception, since its catch
 * blocks map exception classes to result statuses. A read failure still ends in
 * the TypeError from strlen(), as the inline code did.
 *
 * @param resource $stream
 * @param int<0, max> $maxBytes
 * @param int<1, max> $depth
 * @param class-string<\Throwable> $oversize
 * @throws \JsonException when the input is not JSON or nests deeper than $depth
 */
function read_json_command($stream, int $maxBytes, int $depth, string $oversize = \RuntimeException::class, string $message = 'Payload too large'): mixed
{
    $input = stream_get_contents($stream, $maxBytes + 1);
    if (strlen($input) > $maxBytes) {
        throw new $oversize($message);
    }

    return json_decode($input, true, $depth, JSON_THROW_ON_ERROR);
}

/**
 * Lets legacy output through unchanged and returns the function that prints the
 * result marker, on a line of its own, after that output.
 *
 * @return \Closure(string, array<mixed>, int): void
 */
function pass_through_output(): \Closure
{
    $endsWithNewline = true;
    ob_start(static function (string $chunk) use (&$endsWithNewline): string {
        if ($chunk !== '') {
            $endsWithNewline = str_ends_with($chunk, "\n");
        }

        return $chunk;
    }, 1);

    return static function (string $marker, array $result, int $flags) use (&$endsWithNewline): void {
        ob_end_flush();
        if (!$endsWithNewline) {
            echo PHP_EOL;
        }
        echo $marker . '=' . json_encode($result, $flags) . PHP_EOL;
    };
}
