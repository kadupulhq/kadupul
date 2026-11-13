<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Tests;

use Kadupul\IdentityAccess\Application\Port\AuthenticatedSession;
use Kadupul\IdentityAccess\Contract\Actor;
use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\NativeSessionRoutes;
use Kadupul\IdentityAccess\Infrastructure\Symfony\Security\SessionIdentity;
use Kadupul\Kernel;
use Kadupul\Platform\Application\Port\ProductVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class NativeSessionFirewallTest extends TestCase
{
    public function testSessionAuthenticatesThroughTheFirewallAndDoesNotRetainIdentity(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $access = $this->createMock(AuthenticatedSession::class);
            $access->expects($this->exactly(3))->method('consoleActor')->willReturnOnConsecutiveCalls(new Actor(9, 'first'), null, new Actor(10, 'second'));
            $container->set(AuthenticatedSession::class, $access);
            $about = $this->createMock(AuthenticatedAccess::class);
            $about->expects($this->never())->method('authenticatedActor');
            $container->set(AuthenticatedAccess::class, $about);
            $tokens = $container->get(TokenStorageInterface::class);

            $request = Request::create('/session');
            $response = $kernel->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['id' => 9, 'username' => 'first'], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertInstanceOf(SessionIdentity::class, $tokens->getToken()?->getUser());
            $this->assertSame('9', $tokens->getToken()->getUserIdentifier());
            $this->assertSame([], $tokens->getToken()->getRoleNames());
            $this->assertFalse($request->hasSession());
            $this->assertSame([], $response->headers->getCookies());

            $denied = $kernel->handle(Request::create('/session'));
            $this->assertSame(401, $denied->getStatusCode());
            $this->assertSame(['error' => 'authentication_required'], json_decode($denied->getContent(), true, flags: JSON_THROW_ON_ERROR));
            $this->assertNull($tokens->getToken());

            $this->assertSame(200, $kernel->handle(Request::create('/session'))->getStatusCode());
            $this->assertSame('10', $tokens->getToken()->getUserIdentifier());
            $this->assertSame(200, $kernel->handle(Request::create('/healthz'))->getStatusCode());
            $this->assertNull($tokens->getToken());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAboutIdentityCannotAuthorizeASessionSubrequest(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $about = $this->createMock(AuthenticatedAccess::class);
            $about->expects($this->once())->method('authenticatedActor')->willReturn(new Actor(9, 'no-console-realm'));
            $container->set(AuthenticatedAccess::class, $about);
            $console = $this->createMock(AuthenticatedSession::class);
            $console->expects($this->never())->method('consoleActor');
            $container->set(AuthenticatedSession::class, $console);
            $tokens = $container->get(TokenStorageInterface::class);
            $forwarded = false;
            $container->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, function (ControllerEvent $event) use ($kernel, $tokens, &$forwarded): void {
                if (!$event->isMainRequest()) {
                    return;
                }
                $forwarded = true;
                $token = $tokens->getToken();
                $this->assertNotNull($token);
                $this->assertSame(403, $kernel->handle(Request::create('/session'), HttpKernelInterface::SUB_REQUEST)->getStatusCode());
                $this->assertSame($token, $tokens->getToken());
            });
            $this->assertSame(302, $kernel->handle(Request::create('/about/legacy'))->getStatusCode());
            $this->assertTrue($forwarded);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testBackendFailureDoesNotReuseThePreviousIdentityOrReadProtectedContent(): void
    {
        $kernel = new Kernel('test', true);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $calls = 0;
            $access = $this->createMock(AuthenticatedAccess::class);
            $access->method('authenticatedActor')->willReturnCallback(static function () use (&$calls): Actor {
                if (++$calls > 1) {
                    throw new \RuntimeException('Fixture database unavailable.');
                }
                return new Actor(9, 'operator');
            });
            $container->set(AuthenticatedAccess::class, $access);
            $version = $this->createMock(ProductVersion::class);
            $version->expects($this->never())->method('release');
            $container->set(ProductVersion::class, $version);
            $this->assertSame(302, $kernel->handle(Request::create('/about/legacy'))->getStatusCode());
            $response = $kernel->handle(Request::create('/about'));
            $this->assertSame(500, $response->getStatusCode());
            $this->assertNull($container->get(TokenStorageInterface::class)->getToken());
        } finally {
            $kernel->shutdown();
        }
    }

    public static function routeScopes(): iterable
    {
        yield ['session', true];
        yield ['platform_about', true];
        yield ['platform_about_legacy', true];
        yield ['inventory_devices', false];
        yield ['platform_installer', false];
        yield ['platform_about_extra', false];
        yield [null, false];
    }

    #[DataProvider('routeScopes')]
    public function testFirewallScopeUsesOnlyReviewedRouteNames(?string $route, bool $matches): void
    {
        $request = Request::create('/monitoring/app.php/about');
        $request->attributes->set('_route', $route);
        $this->assertSame($matches, (new NativeSessionRoutes())->matches($request));
    }
}
