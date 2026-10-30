<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LegacyRaiseMessageTest extends TestCase
{
    #[DataProvider('messages')]
    public function testMessageFallbacks(mixed $catalog, array $session, string $message, int $level, array $expected): void
    {
        $program = <<<'PHP'
            require 'include/global_constants.php';
            require 'lib/functions.php';
            function __($text) { return $text; }
            [$messages, $_SESSION, $message, $level] = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
            $no_http_headers = true;
            raise_message('probe', $message, $level);
            echo json_encode($_SESSION['sess_messages'], JSON_THROW_ON_ERROR);
            PHP;
        $process = new Process([PHP_BINARY, '-d', 'auto_prepend_file=', '-r', $program, json_encode([$catalog, $session, $message, $level], JSON_THROW_ON_ERROR)], dirname(__DIR__, 2));
        $process->setTimeout(10);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('', $process->getErrorOutput());
        $this->assertSame(['probe' => $expected], json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{mixed, array, string, int, array{message: string, level: int}}> */
    public static function messages(): iterable
    {
        yield 'null catalog uses missing message' => [null, [], '', 0, ['message' => 'Message Not Found.', 'level' => 3]];
        yield 'null catalog uses session message' => [null, ['probe' => 'Session error'], '', 0, ['message' => 'Session error', 'level' => 3]];
        yield 'empty catalog uses session message' => [[], ['probe' => 'Session error'], '', 0, ['message' => 'Session error', 'level' => 3]];
        yield 'predefined message wins over session' => [['probe' => ['message' => 'Saved', 'level' => 1]], ['probe' => 'Session error'], '', 0, ['message' => 'Saved', 'level' => 1]];
        yield 'legacy string message' => [['probe' => 'Saved'], [], '', 0, ['message' => 'Saved', 'level' => 0]];
        yield 'explicit severity wins' => [['probe' => ['message' => 'Saved', 'level' => 1]], [], '', 2, ['message' => 'Saved', 'level' => 2]];
        yield 'explicit message bypasses catalog' => [null, [], 'Direct message', 1, ['message' => 'Direct message', 'level' => 1]];
        yield 'unknown id in populated catalog' => [['other' => 'Other'], [], '', 0, ['message' => 'Message Not Found.', 'level' => 3]];
    }
}
