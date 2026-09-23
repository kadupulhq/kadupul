<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceSelectionForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceSelectionFormTest extends TestCase
{
    #[DataProvider('failures')]
    public function testOperationFailuresPreserveStatusAndPrivacy(\Throwable $error, int $expected, bool $response): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn($message) => $message);
        $form = $this->createMock(FormInterface::class);
        $form->expects($response ? self::never() : self::once())->method('addError');
        $result = (new DeviceSelectionForm($translator))->failure($error, $form, 'Uncertain operation');
        if ($response) {
            self::assertInstanceOf(Response::class, $result);
            self::assertSame($expected, $result->getStatusCode());
            self::assertStringContainsString('no-store', $result->headers->get('Cache-Control'));
        } else {
            self::assertSame($expected, $result);
        }
    }

    public static function failures(): iterable
    {
        yield [new InventoryAccessDenied(true), 401, true];
        yield [new InventoryAccessDenied(false), 403, true];
        yield [new DevicesNotFound(), 404, true];
        yield [new DeviceEditConflict('Changed'), 409, false];
        yield [new \JsonException(), 422, false];
        yield [new \InvalidArgumentException(), 422, false];
        yield [new \RuntimeException(), 502, false];
    }
}
