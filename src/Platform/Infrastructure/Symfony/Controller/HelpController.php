<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\Platform\Application\Query\ViewHelp;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class HelpController
{
    #[Route('/help', name: 'platform_help', methods: ['GET', 'HEAD'])]
    public function __invoke(ViewHelp $view, Environment $twig, TranslatorInterface $translator): Response
    {
        $topics = $view();
        if ($topics === null) {
            return new Response($translator->trans('Access denied.', [], 'help'), 401, ['Cache-Control' => 'private, no-store']);
        }

        return new Response($twig->render('platform/help.html.twig', ['topics' => $topics]), 200, ['Cache-Control' => 'private, no-store']);
    }
}
