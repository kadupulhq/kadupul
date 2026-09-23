<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests\RemovalCommandInput;

use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../Helpers/PhpSource.php';

// A fixed first-party function body; the worker itself needs a Cacti bootstrap.
eval('namespace ' . __NAMESPACE__ . '; use RuntimeException; use Kadupul\Inventory\Domain\DeviceRemovalPolicy;' . \test_php_function_source(file_get_contents(__DIR__ . '/../../bin/legacy-device-remove.php'), 'device_removal_command')); // nosemgrep: php.lang.security.eval-use.eval-use

final class DeviceRemovalCommandInputTest extends TestCase
{
    /** @return array{actor: int, selection: array<string, mixed>, policy: string} */
    private static function command(array $selection = ['7' => ['revision' => 'a']]): array
    {
        return ['actor' => 3, 'selection' => $selection, 'policy' => 'retain'];
    }

    public function testAPayloadAtTheLimitIsAccepted(): void
    {
        $padding = self::command(['7' => ['revision' => '']]);
        $json = json_encode($padding, JSON_THROW_ON_ERROR);
        $padding['selection']['7']['revision'] = str_repeat('a', 16000 - strlen($json));
        $json = json_encode($padding, JSON_THROW_ON_ERROR);

        self::assertSame(16000, strlen($json));
        self::assertSame(3, device_removal_command($json)['actor']);
    }

    public function testAPayloadOverTheLimitIsRejected(): void
    {
        $over = self::command(['7' => ['revision' => str_repeat('a', 16001)]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Payload too large');
        device_removal_command(json_encode($over, JSON_THROW_ON_ERROR));
    }

    public function testNestingDeeperThanTheDecoderAllowsIsRejected(): void
    {
        $deep = self::command(['7' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 1]]]]]]]]);

        $this->expectException(\JsonException::class);
        device_removal_command(json_encode($deep, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('malformed')]
    public function testAMalformedCommandIsRejected(array $command): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid command');
        device_removal_command(json_encode($command, JSON_THROW_ON_ERROR));
    }

    public static function malformed(): iterable
    {
        yield 'unknown key' => [self::command() + ['extra' => 1]];
        yield 'actor is not an id' => [array_merge(self::command(), ['actor' => 0])];
        yield 'actor is a string' => [array_merge(self::command(), ['actor' => '3'])];
        yield 'selection is not an array' => [array_merge(self::command(), ['selection' => 'all'])];
        yield 'policy is unknown' => [array_merge(self::command(), ['policy' => 'erase'])];
    }

    public function testTheAcceptedPoliciesAreTheDeclaredOnes(): void
    {
        foreach (DeviceRemovalPolicy::cases() as $policy) {
            self::assertSame($policy->value, device_removal_command(json_encode(array_merge(self::command(), ['policy' => $policy->value]), JSON_THROW_ON_ERROR))['policy']);
        }
    }
}
