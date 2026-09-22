<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceFormFailure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceFormFailureTest extends TestCase
{
    public function testKnownErrorsArePresentedAndUnknownDiagnosticsStayPrivate(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($message) => $message);
        foreach ([[new DeviceEditConflict('Stale device'), 409, 'Stale device'], [new \InvalidArgumentException('Invalid template'), 422, 'Invalid template'], [new \RuntimeException('private database diagnostic'), 502, 'Save outcome is uncertain. Reload the device before retrying.']] as [$error, $status, $message]) {
            $form = $this->createMock(FormInterface::class);
            $form->expects(self::once())->method('addError')->with(self::callback(fn($error) => $error->getMessage() === $message))->willReturnSelf();
            self::assertSame($status, (new DeviceFormFailure($translator))->apply($form, $error));
        }
    }

    public function testDeniedWriteReturnsPrivateForbiddenResponse(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Access denied.');
        $form = $this->createMock(FormInterface::class);
        $form->expects(self::never())->method('addError');
        $response = (new DeviceFormFailure($translator))->apply($form, new InventoryAccessDenied(false));
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
