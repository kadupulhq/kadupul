<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Kernel;
use Kadupul\Navigation\Application\Port\LinkAccess;
use Kadupul\Navigation\Application\Port\LinkStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class LinkEditorPresentationTest extends TestCase
{
    public function testCreateFormRendersThroughKernel(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $actor = new Actor(1, 'admin');
            $console = $this->createMock(ConsoleAccess::class);
            $console->method('consoleActor')->willReturn($actor);
            $container->set(ConsoleAccess::class, $console);
            $access = $this->createMock(LinkAccess::class);
            $access->method('authorize')->willReturn($actor);
            $container->set(LinkAccess::class, $access);
            $store = $this->createMock(LinkStore::class);
            $store->method('snapshot')->willReturn(['links' => [], 'revision' => str_repeat('a', 64)]);
            $store->method('files')->willReturn([]);
            $container->set(LinkStore::class, $store);
            $request = Request::create('/links/new');
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), $response->getContent());
            self::assertStringContainsString('link[_token]', $response->getContent());
            self::assertStringContainsString('link[revision]', $response->getContent());
            $posted = Request::create('/links/new', 'POST', ['link' => ['title' => 'X', 'style' => 'TAB', 'filename' => '0', 'fileurl' => 'https://example.org', 'consolesection' => 'External Links', 'consolenewsection' => '', 'enabled' => '1', 'refresh' => '0', 'revision' => str_repeat('a', 64), '_token' => 'csrf-token', 'unknown' => 'injected']], server: ['HTTP_ORIGIN' => 'http://localhost']);
            $response = $kernel->handle($posted);
            self::assertSame(422, $response->getStatusCode());
            self::assertStringContainsString('Unexpected fields were submitted.', $response->getContent());
        } finally {
            $kernel->shutdown();
        }
    }
}
