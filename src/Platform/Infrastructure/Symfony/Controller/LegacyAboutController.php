<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyAboutController
{
    #[Route('/about/legacy', name: 'platform_about_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, AuthenticatedAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $access->authenticatedActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'about'), 401, ['Cache-Control' => 'private, no-store']);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('Open About Kadupul to view project information.', [], 'about'), 405, ['Cache-Control' => 'private, no-store', 'Allow' => 'GET, HEAD']);
        }
        return new RedirectResponse($urls->generate('platform_about'), 302, ['Cache-Control' => 'private, no-store']);
    }
}
