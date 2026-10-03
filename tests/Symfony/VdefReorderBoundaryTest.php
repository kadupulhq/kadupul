<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Port\VdefRealmAccess;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class VdefReorderBoundaryTest extends TestCase
{
    public static function malformedOrders(): iterable
    {
        yield 'numeric-key JSON object' => [['items' => '{"0":1,"1":2}']];
        yield 'fractional movement' => [['moveDown' => '1.5']];
        yield 'exponent movement' => [['moveDown' => '1e0']];
        yield 'leading-zero movement' => [['moveDown' => '01']];
        yield 'two movement buttons' => [['moveUp' => '2', 'moveDown' => '2']];
        yield 'missing revision' => [['revision' => null]];
        yield 'missing items' => [['items' => null]];
        yield 'unexpected field' => [['unexpected' => 'value']];
        yield 'oversized selection' => [['items' => json_encode(range(1, 501), JSON_THROW_ON_ERROR)]];
        yield 'nested item' => [['items' => '[[1]]']];
        yield 'string identity' => [['items' => '["1"]']];
        yield 'fractional identity' => [['items' => '[1.5]']];
        yield 'zero identity' => [['items' => '[0]']];
        yield 'overflow identity' => [['items' => '[2147483648]']];
        yield 'duplicate identities' => [['items' => '[1,1]']];
        yield 'array movement' => [['moveDown' => ['1']]];
    }

    #[DataProvider('malformedOrders')]
    public function testMalformedOrderNeverReachesEditor(array $overrides): void
    {
        [$response, $calls] = $this->submitOrder($overrides);
        self::assertContains($response->getStatusCode(), [400, 422]);
        self::assertSame([], $calls);
    }

    public static function admittedOrders(): iterable
    {
        yield 'unchanged order' => [[], [1, 2, 3]];
        yield 'up button' => [['moveUp' => '2'], [2, 1, 3]];
        yield 'down button' => [['moveDown' => '2'], [1, 3, 2]];
        yield 'empty order' => [['items' => '[]'], []];
        yield 'maximum selection' => [['items' => json_encode(range(1, 500), JSON_THROW_ON_ERROR)], range(1, 500)];
    }

    #[DataProvider('admittedOrders')]
    public function testRenderedFormOrdersReachEditorExactlyOnce(array $overrides, array $expected): void
    {
        [$response, $calls] = $this->submitOrder($overrides);
        self::assertSame(303, $response->getStatusCode());
        self::assertCount(1, $calls);
        self::assertSame(42, $calls[0][0]);
        self::assertSame(1, $calls[0][1]);
        self::assertSame($expected, $calls[0][2]);
        self::assertSame(str_repeat('a', 64), $calls[0][3]);
    }

    private function submitOrder(array $overrides): array
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $container->set(ConsoleAccess::class, $access);
            $realm = $this->createMock(VdefRealmAccess::class);
            $realm->method('canManageDefinitions')->willReturn(true);
            $container->set(VdefRealmAccess::class, $realm);
            $editor = $this->createMock(VdefEditor::class);
            $calls = [];
            $editor->method('reorder')->willReturnCallback(static function (...$arguments) use (&$calls): void {
                $calls[] = $arguments;
            });
            $container->set(VdefEditor::class, $editor);
            $order = array_replace(['items' => '[1,2,3]', 'revision' => str_repeat('a', 64), '_token' => 'csrf-token'], $overrides);
            foreach ($order as $key => $value) {
                if ($value === null) {
                    unset($order[$key]);
                }
            }
            $request = Request::create('/graph-definitions/vdefs/1/items/reorder', 'POST', ['order' => $order], server: ['HTTP_ORIGIN' => 'http://localhost']);
            $response = $kernel->handle($request);
            return [$response, $calls];
        } finally {
            $kernel->shutdown();
        }
    }
}
