<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\IdentityAccess\Infrastructure\Symfony\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class RequestAuthenticationReset implements EventSubscriberInterface
{
    public function __construct(private TokenStorageInterface $tokens, private NativeSessionRoutes $routes) {}

    public static function getSubscribedEvents(): array
    {
        // A reused kernel must not carry identity into another request, including
        // unmatched routes and failures before the stateless firewall executes.
        return [KernelEvents::REQUEST => [['onRequest', 256], ['onRoutedRequest', 9]]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->tokens->setToken(null);
        }
    }

    public function onRoutedRequest(RequestEvent $event): void
    {
        // Symfony firewalls authenticate main requests only. Do not let an About
        // identity inherit the console-only /session admission through a forward.
        if (!$event->isMainRequest() && $this->routes->matches($event->getRequest())) {
            $event->setResponse(new Response('', 403, ['Cache-Control' => 'private, no-store']));
        }
    }
}
