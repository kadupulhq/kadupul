<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Inventory\Infrastructure\Symfony\DeviceFormPage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class DeviceFormPageTest extends TestCase
{
    public function testNavigationValidationAndPrivateErrors(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(fn($message) => $message);
        $page = new DeviceFormPage(new Environment(new ArrayLoader()), $translator);
        $parameters = $page->parameters(new Request(['list' => ['q' => 'router', 'page' => '2']]), 17);
        self::assertSame(17, $parameters['id']);
        self::assertSame('router', $parameters['list']['q']);
        self::assertSame(2, $parameters['list']['page']);
        $response = $page->parameters(new Request(['list' => 'invalid']), 17);
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testDeniedSubmissionDoesNotRenderAForm(): void
    {
        $page = new DeviceFormPage(new Environment(new ArrayLoader()), $this->createMock(TranslatorInterface::class));
        $form = $this->createMock(FormInterface::class);
        $form->expects(self::never())->method('createView');
        $response = new Response('Access denied.', 403);
        self::assertSame($response, $page->render('missing.html.twig', new \stdClass(), $form, new Request(), [], $response));
    }
}
