<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\Platform\Infrastructure\Symfony\ConsoleHomeResponse;
use Kadupul\Platform\Infrastructure\Symfony\NativeLogoutPage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class LogoutMessageController
{
    #[Route('/logout/message', name: 'platform_logout_message', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, Environment $twig): Response
    {
        $page = $request->attributes->get(NativeLogoutPage::class);
        if (!$page instanceof NativeLogoutPage) {
            return new Response('', 404, ['Cache-Control' => 'private, no-store']);
        }
        // Captured native head/footer scripts keep the policy that issued their
        // nonces. No authentication, session restoration or mutation occurs here.
        return new ConsoleHomeResponse($request->isMethod('HEAD') ? '' : $twig->render('platform/logout_message.html.twig', ['page' => $page]), $page->policies);
    }
}
