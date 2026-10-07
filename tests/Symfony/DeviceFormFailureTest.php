<?php

declare(strict_types=1);

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

    public function testDeniedWritePreservesAuthenticationStatusInPrivateResponse(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Access denied.');
        foreach ([false => 403, true => 401] as $unauthenticated => $status) {
            $form = $this->createMock(FormInterface::class);
            $form->expects(self::never())->method('addError');
            $response = (new DeviceFormFailure($translator))->apply($form, new InventoryAccessDenied((bool) $unauthenticated));
            self::assertInstanceOf(Response::class, $response);
            self::assertSame($status, $response->getStatusCode());
            self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function testOperationSpecificStatusAndUncertainMessageArePreserved(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(4))->method('trans')->willReturnCallback(function (string $message, array $parameters, string $domain): string {
            self::assertSame([], $parameters);
            self::assertSame('inventory', $domain);
            return $message;
        });
        $failures = new DeviceFormFailure($translator);
        foreach ([
            [new \InvalidArgumentException('Refresh validation failed.'), 200, 200, 'Refresh validation failed.'],
            [new \InvalidArgumentException('Validation after earlier failure.'), 502, 502, 'Validation after earlier failure.'],
            [new DeviceEditConflict('Stale revision'), 200, 409, 'Stale revision'],
            [new \RuntimeException('private worker diagnostic'), 200, 502, 'Device operation outcome is uncertain. Check every selected device before retrying.'],
        ] as [$error, $currentStatus, $expectedStatus, $expectedMessage]) {
            $form = $this->createMock(FormInterface::class);
            $form->expects(self::once())->method('addError')->with(self::callback(fn($error) => $error->getMessage() === $expectedMessage))->willReturnSelf();
            self::assertSame($expectedStatus, $failures->apply($form, $error, $currentStatus, 'Device operation outcome is uncertain. Check every selected device before retrying.'));
        }
    }

}
