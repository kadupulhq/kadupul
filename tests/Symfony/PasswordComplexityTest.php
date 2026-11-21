<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Domain\PasswordComplexity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordComplexityTest extends TestCase
{
    public static function minimums(): iterable
    {
        yield ['abc', 4, false];
        yield ['abcd', '4', true];
        yield ['', 0, true];
        yield ['', '', true];
        yield ['', null, true];
        yield ['', false, true];
        yield ['', true, false];
        yield ['a', true, true];
        yield ['abc', '3.5', false];
        yield ['abcd', '3.5', true];
        yield ['', -1, true];
        yield ['éé', 4, true];
        yield ["a\0b", 3, true];
        yield ['abc', 'invalid', false];
    }

    #[DataProvider('minimums')]
    public function testMinimumRetainsNativeByteAndSettingSemantics(string $password, string|int|float|bool|null $minimum, bool $expected): void
    {
        self::assertSame($expected, (new PasswordComplexity())->meetsMinimumLength($password, $minimum));
    }

    public static function characters(): iterable
    {
        yield ['', false, false, false];
        yield ['abc', false, false, false];
        yield ['ABC', false, true, false];
        yield ['Abc0!', true, true, true];
        yield ['0', true, false, false];
        yield ['０', false, false, false];
        yield ['Äé', false, false, false];
        yield ["Abc\0", false, true, false];
        yield ['Abc ', false, true, false];
        yield ['Abc"', false, true, false];
        yield ["Abc'", false, true, false];
        yield ['Abc\\', false, true, true];
        yield ['1e+2', true, false, false];
        yield ['0E0', true, false, false];
        yield ['-12', true, false, true];
        yield ['+12', true, false, false];
    }

    #[DataProvider('characters')]
    public function testNativeCharacterRules(string $password, bool $number, bool $case, bool $special): void
    {
        $rules = new PasswordComplexity();
        self::assertSame($number, $rules->meetsNumberRequirement($password));
        self::assertSame($case, $rules->meetsCaseRequirement($password));
        self::assertSame($special, $rules->meetsSpecialRequirement($password));
    }

    public function testNativeFacadeRetainsFirstErrorSettingsOrderAndTranslation(): void
    {
        $settings = ['secpass_minlen' => '8', 'secpass_reqnum' => 'on', 'secpass_reqmixcase' => 'on', 'secpass_reqspec' => 'on'];
        $messages = [
            'Password must be at least %d characters!',
            'Your password must contain at least 1 numerical character!',
            'Your password must contain a mix of lower case and upper case characters!',
            'Your password must contain at least 1 special character!',
        ];
        $passwords = ['short', 'abcdefgh', 'abcdefg1', 'Abcdefg1', 'Abcdefg1!'];
        $scenarios = array_map(static fn(string $password): array => ['password' => $password, 'settings' => $settings, 'translation_prefix' => 'fixture: '], $passwords);
        $scenarios[] = ['password' => '', 'settings' => []];
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__) . '/Fixtures/password-complexity-native.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $input = json_encode($scenarios, JSON_THROW_ON_ERROR);
        self::assertSame(strlen($input), fwrite($pipes[0], $input));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        self::assertSame('', $stderr);
        $results = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(6, $results);
        foreach ($messages as $index => $message) {
            $arguments = $index === 0 ? ['8'] : [];
            self::assertSame('fixture: ' . vsprintf($message, $arguments), $results[$index]['result']);
            self::assertSame([[$message, $arguments]], $results[$index]['messages']);
            self::assertSame(array_slice(array_keys($settings), 0, $index + 1), $results[$index]['reads']);
        }
        foreach ([4, 5] as $index) {
            self::assertSame('ok', $results[$index]['result']);
            self::assertSame([], $results[$index]['messages']);
            self::assertSame(array_keys($settings), $results[$index]['reads']);
        }
    }
}
