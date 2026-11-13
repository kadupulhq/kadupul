<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
// SPDX-License-Identifier: GPL-3.0-or-later

namespace Kadupul\AssetManagement\Infrastructure\Symfony;

use Kadupul\AssetManagement\Domain\AssetError;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Refuses requests to the asset register route early, before the page runs, unless the visitor may use it.
 * The response status follows the reason: 404 disabled, 401 unauthenticated, 403 denied, 503 otherwise.
 */
final readonly class AssetRequestAccess implements EventSubscriberInterface
{
    /** @param \Closure(): WebAssetAccess $access */
    public function __construct(#[AutowireServiceClosure(WebAssetAccess::class)] private \Closure $access, private TranslatorInterface $translator) {}

    /**
     * Subscribes to the request event.
     */
    public static function getSubscribedEvents(): array
    {
        // After routing, before locale selection can read installation settings.
        return [KernelEvents::REQUEST => ['onRequest', 24]];
    }

    /**
     * Sets an error response for the asset register route when access is refused; other routes are untouched.
     */
    public function onRequest(RequestEvent $event): void
    {
        if ($event->getRequest()->attributes->get('_route') !== 'asset_register') {
            return;
        }
        try {
            ($this->access)()->requireActor();
        } catch (\Throwable $error) {
            $reason = $error instanceof AssetError ? $error->reason : 'unavailable';
            $status = match ($reason) {
                'disabled' => 404, 'unauthenticated' => 401, 'denied' => 403, default => 503,
            };
            $event->setResponse(new Response($this->translator->trans($reason, [], 'assets'), $status, ['Cache-Control' => 'private, no-store']));
        }
    }
}
