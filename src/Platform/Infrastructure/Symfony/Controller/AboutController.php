<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\AuthenticatedAccess;
use Kadupul\Platform\Application\Port\ProductVersion;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AboutController
{
    #[Route('/about', name: 'platform_about', methods: ['GET', 'HEAD'])]
    public function __invoke(AuthenticatedAccess $access, ProductVersion $version, Environment $twig, TranslatorInterface $translator): Response
    {
        $actor = $access->authenticatedActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'about'), 401, ['Cache-Control' => 'private, no-store']);
        }
        return new Response($twig->render('platform/about.html.twig', ['release' => $version->release()]), 200, ['Cache-Control' => 'private, no-store']);
    }
}
