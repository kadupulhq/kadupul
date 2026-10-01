<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\Platform\Infrastructure\Symfony\LegacyPageForwarder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\KernelInterface;

final class LegacyPageForwarderTest extends TestCase
{
    /** @dataProvider scripts */
    public function testPreservesPostQueryCookieAndSubdirectory(string $script, string $route): void
    {
        $original = Request::create('https://monitor.invalid/console/' . $script . '?action=edit&filter=red', 'POST', ['selected_items' => 'expired'], ['Cacti' => 'opaque'], [], [
            'SCRIPT_FILENAME' => '/srv/kadupul/' . $script, 'SCRIPT_NAME' => '/console/' . $script, 'PHP_SELF' => '/console/' . $script,
        ], 'raw request body');
        $original->query->set('action', 'edit');
        $original->query->set('filter', 'red');
        $file = new UploadedFile(__FILE__, 'forwarder.txt', 'text/plain', null, true);
        $original->files->set('import_file', $file);
        $original->server->set('HTTP_ORIGIN', 'https://monitor.invalid');
        $forwarded = LegacyPageForwarder::request($original, '/srv/kadupul', $route);
        self::assertSame('/console/app.php', $forwarded->getBaseUrl());
        self::assertSame($route, $forwarded->getPathInfo());
        self::assertSame('POST', $forwarded->getMethod());
        self::assertSame('raw request body', $forwarded->getContent());
        self::assertSame(['action' => 'edit', 'filter' => 'red'], $forwarded->query->all());
        self::assertSame('expired', $forwarded->request->get('selected_items'));
        self::assertSame($file, $forwarded->files->get('import_file'));
        self::assertSame('https://monitor.invalid', $forwarded->headers->get('Origin'));
        self::assertSame('opaque', $forwarded->cookies->get('Cacti'));
        self::assertSame('/srv/kadupul/app.php', $forwarded->server->get('SCRIPT_FILENAME'));
        self::assertSame('/console/' . $script, $original->getBaseUrl());
    }
    public static function scripts(): array
    {
        return [['about.php', '/about/legacy']];
    }
    public function testRootDeploymentUsesRootFrontController(): void
    {
        $request = LegacyPageForwarder::request(Request::create('/about.php'), '/srv/kadupul', '/about/legacy');
        self::assertSame('/app.php', $request->getBaseUrl());
        self::assertSame('/about/legacy', $request->getPathInfo());
        self::assertSame('GET', $request->getMethod());
    }
    public function testInvalidForwardUriNeverDispatchesKernel(): void
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->expects(self::never())->method('handle');
        $this->expectException(\InvalidArgumentException::class);
        LegacyPageForwarder::run($kernel, '/srv/kadupul', 'https://external.invalid');
    }
    public function testRejectsExternalOrQueryBearingRoute(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LegacyPageForwarder::request(Request::create('/'), '/srv/kadupul', '//evil.invalid/?x=1');
    }
}
