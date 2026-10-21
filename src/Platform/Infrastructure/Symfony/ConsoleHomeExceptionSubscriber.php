<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Kadupul\Platform\Application\Query\ConsoleHomeAccessDenied;
use Kadupul\Platform\Infrastructure\Legacy\ConsoleUpgradeRequired;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Map the home use case's refusals without exposing worker diagnostics. */
final readonly class ConsoleHomeExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(#[AutowireServiceClosure(ConsoleLoginLocation::class)] private \Closure $login, private LoggerInterface $logger) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        // The permission denied page starts the same browser worker.
        if (!in_array($event->getRequest()->attributes->get('_route'), ['platform_console', 'platform_permission_denied'], true)) {
            return;
        }
        $error = $event->getThrowable();
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($error instanceof HttpExceptionInterface) {
            $event->setResponse(new Response('The console request was refused.', $error->getStatusCode(), $headers + $error->getHeaders()));

            return;
        }
        if ($error instanceof ConsoleUpgradeRequired) {
            $event->setResponse(new RedirectResponse($error->location, 302, $headers));
        } elseif ($error instanceof ConsoleHomeAccessDenied) {
            $event->setResponse($error->anonymous
                ? new RedirectResponse(($this->login)()->path(), 302, $headers)
                : new Response('Access denied.', 403, $headers));
        } elseif ($error instanceof \RuntimeException) {
            $this->logger->error('Console request failed. class={class} source={file}:{line}', [
                'class' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
            ]);
            $event->setResponse(new Response('The console is temporarily unavailable.', 503, $headers));
        }
    }
}
