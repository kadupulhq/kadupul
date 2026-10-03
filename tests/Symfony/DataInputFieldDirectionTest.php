<?php

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\Tests;

use Kadupul\DataInput\Application\DataInputMethods;
use Kadupul\DataInput\Application\Port\DataInputAccess;
use Kadupul\DataInput\Application\Port\DataInputGateway;
use Kadupul\DataInput\Infrastructure\Symfony\Controller\DataInputController;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DataInputFieldDirectionTest extends TestCase
{
    #[DataProvider('directions')]
    public function testSubmittedHiddenDirectionCannotChangeTheConfiguredDirection(string $direction, string $forged): void
    {
        $actor = new Actor(9, 'operator');
        $console = $this->createMock(ConsoleAccess::class);
        $console->method('consoleActor')->willReturn($actor);
        $access = $this->createMock(DataInputAccess::class);
        $access->method('authorize')->willReturn($actor);
        $gateway = $this->createMock(DataInputGateway::class);
        $gateway->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (int $actor, string $action, int $id, array $payload) use ($direction): array {
            if ($action === 'find') {
                return ['method' => ['id' => 3, 'type_id' => 1, 'input_string' => '<x>'], 'fields' => [], 'revision' => str_repeat('a', 64)];
            }
            self::assertSame('field_save', $action);
            self::assertSame($direction, $payload['data']['input_output']);
            return ['id' => 3, 'partial' => false];
        });
        $form = $this->createMock(FormInterface::class);
        $form->method('isSubmitted')->willReturn(true);
        $form->method('isValid')->willReturn(true);
        $form->method('getExtraData')->willReturn([]);
        $form->method('getData')->willReturn(['name' => 'Fixture', 'data_name' => 'x', 'input_output' => $forged, 'revision' => str_repeat('a', 64)]);
        $forms = $this->createMock(FormFactoryInterface::class);
        $forms->method('create')->willReturn($form);
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/data-inputs/3/edit');
        $response = (new DataInputController())->field(3, 0, Request::create('/data-inputs/3/fields/0?direction=' . $direction, 'POST'), $console, $access, new DataInputMethods($access, $gateway), $forms, $this->createMock(Environment::class), $urls, $this->createMock(TranslatorInterface::class));
        self::assertSame(303, $response->getStatusCode());
    }

    public static function directions(): iterable
    {
        yield 'input form' => ['in', 'out'];
        yield 'output form' => ['out', 'in'];
    }
}
