<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeaders implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        if ($event->getRequest()->attributes->get('_route') !== 'platform_installer' || !$headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', "default-src 'self'; object-src 'none'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }
        if ($event->getResponse() instanceof ConsoleHomeResponse && $event->getResponse()->compatibilityPolicies !== []) {
            // The native extension adapter provides the same operator-selected
            // policy as the old console. Other Symfony pages remain strict.
            $headers->remove('Content-Security-Policy');
            $headers->set('X-Frame-Options', 'SAMEORIGIN');
            $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
            $headers->set('Cross-Origin-Resource-Policy', 'same-origin');
            foreach ($event->getResponse()->compatibilityPolicies as $name => $value) {
                $headers->set($name, $value);
            }
        }
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000');
        }
    }
}
