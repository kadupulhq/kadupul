<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/** HTTP boundary and presentation handoff; native logout retains its lifecycle. */
final class LegacyLogoutPage
{
    /** Validate before logout hooks or credential mutations; POST uses body fields. */
    public static function action(Request $request): string
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD', 'POST'], true)) {
            throw new \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException(['GET', 'HEAD', 'POST']);
        }
        $parameters = $request->isMethod('POST') ? $request->request->all() : $request->query->all();
        $action = $parameters['action'] ?? '';
        if (!is_string($action)) {
            throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid logout action.');
        }
        return $action;
    }

    /** Build the same-origin native endpoint from configured application routing. */
    public static function confirmationTarget(string $applicationPath): string
    {
        return $applicationPath . 'logout.php';
    }

    /** Finish a capture without disturbing the native outer output buffers. */
    public static function finishCapture(int $outerLevel): string
    {
        if (ob_get_level() <= $outerLevel) {
            throw new \RuntimeException('Logout extension closed its output boundary.');
        }
        while (ob_get_level() > $outerLevel + 1) {
            if (!ob_end_flush()) {
                throw new \RuntimeException('Logout extension output could not be collected.');
            }
        }
        $output = ob_get_clean();
        if (!is_string($output)) {
            throw new \RuntimeException('Logout presentation could not be captured.');
        }
        return $output;
    }

    public static function send(HttpKernelInterface&TerminableInterface $kernel, NativeLogoutPage $page): void
    {
        $root = dirname(__DIR__, 4);
        $request = self::request(Request::createFromGlobals(), $root, $page);
        $response = $kernel->handle($request);
        // Keep the native output buffers (including csrf-magic) and shutdown
        // hooks in charge of final flushing, just as on the legacy page.
        $response->send(false);
        $kernel->terminate($request, $response);
    }

    public static function request(Request $original, string $root, NativeLogoutPage $page): Request
    {
        $request = LegacyPageForwarder::request($original, $root, '/logout/message');
        // A native POST has already passed its own request handling. Rendering
        // the result must not replay the mutation or accept an HTTP capability.
        $request->setMethod($original->isMethod('HEAD') ? 'HEAD' : 'GET');
        $request->attributes->set(NativeLogoutPage::class, $page);
        return $request;
    }
}
