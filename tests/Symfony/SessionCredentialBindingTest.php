<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SessionCredentialBindingTest extends TestCase
{
    #[DataProvider('cases')]
    public function testRealSharedSessionsRequireCurrentCredentialBindings(string $scenario, string $storage, bool $accepted): void
    {
        $directory = sys_get_temp_dir() . '/session-binding-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', dirname(__DIR__) . '/Fixtures/symfony-credential-session-native.php', $scenario, $directory, $storage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $errors . $output);
            self::assertSame('', $errors);
            $state = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($accepted, $state['accepted']);
            self::assertSame(!$accepted, $state['unauthenticated']);
            self::assertSame(!$accepted, $state['revoked']);
            if (str_ends_with($scenario, '-write')) {
                self::assertSame(9, $state['initial']);
            }
        } finally {
            foreach (glob($directory . '/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    public static function cases(): iterable
    {
        foreach (['file', 'database'] as $storage) {
            foreach (['current' => true, 'unbound' => false, 'reset' => false, 'reset-write' => false, 'rehash' => true, 'rehash-write' => true] as $scenario => $accepted) {
                yield $storage . '-' . $scenario => [$scenario, $storage, $accepted];
            }
        }
    }
}
