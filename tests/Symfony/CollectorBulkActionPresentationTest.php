<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\CollectorAdministration\Application\Port\CollectorBulkOperations;
use Kadupul\CollectorAdministration\Domain\CollectorBulkAction;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CollectorBulkActionPresentationTest extends TestCase
{
    public function testConfirmedBulkActionRequiresCsrfAndPreservesTheSelectedIds(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);

            $operations = $this->createMock(CollectorBulkOperations::class);
            $operations->expects(self::exactly(3))->method('find')->with(new \Kadupul\CollectorAdministration\Domain\CollectorSelection([2, 9]))->willReturn([
                ['id' => 2, 'name' => '<remote two>', 'dbhost' => 'remote-two'],
                ['id' => 9, 'name' => 'Remote nine', 'dbhost' => 'remote-nine'],
            ]);
            $operations->expects(self::once())->method('execute')->with(42, CollectorBulkAction::Disable, new \Kadupul\CollectorAdministration\Domain\CollectorSelection([2, 9]))->willReturn([
                'successful' => [2, 9],
                'failed' => [],
            ]);
            $container->set(CollectorBulkOperations::class, $operations);

            $path = '/collectors/actions/disable?ids%5B0%5D=2&ids%5B1%5D=9';
            $preview = $kernel->handle(Request::create($path, 'GET'));
            self::assertSame(200, $preview->getStatusCode());
            self::assertStringContainsString('&lt;remote two&gt;', $preview->getContent());
            self::assertStringNotContainsString('<remote two>', $preview->getContent());
            $document = new \DOMDocument();
            @$document->loadHTML($preview->getContent());
            $xpath = new \DOMXPath($document);
            $token = $xpath->evaluate('string(//input[@name="collector_bulk_action[_token]"]/@value)');
            $selection = $xpath->evaluate('string(//input[@name="collector_bulk_action[selection]"]/@value)');
            self::assertNotSame('', $token);
            self::assertSame('[2,9]', $selection);

            $invalid = Request::create($path, 'POST', ['collector_bulk_action' => ['selection' => $selection, '_token' => 'invalid']]);
            $invalid->headers->set('Origin', 'http://localhost');
            self::assertSame(422, $kernel->handle($invalid)->getStatusCode());

            $valid = Request::create($path, 'POST', ['collector_bulk_action' => ['selection' => $selection, '_token' => $token]]);
            $valid->headers->set('Origin', 'http://localhost');
            $response = $kernel->handle($valid);
            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/collectors', $response->headers->get('Location'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testPrimaryCollectorCannotBeSubmittedForDeletion(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(ConsoleAccess::class);
            $access->method('consoleActor')->willReturn(new Actor(42, 'operator'));
            $access->method('canManageDevices')->willReturn(true);
            $container->set(ConsoleAccess::class, $access);
            $operations = $this->createMock(CollectorBulkOperations::class);
            $operations->expects(self::never())->method('find');
            $operations->expects(self::never())->method('execute');
            $container->set(CollectorBulkOperations::class, $operations);

            $response = $kernel->handle(Request::create('/collectors/actions/delete?ids%5B0%5D=1'));
            self::assertSame(400, $response->getStatusCode());
        } finally {
            $kernel->shutdown();
        }
    }
}
