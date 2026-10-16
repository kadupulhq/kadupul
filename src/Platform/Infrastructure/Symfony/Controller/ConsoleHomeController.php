<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\Platform\Application\Query\ViewConsoleHome;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleHomeResponse;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleLoginLocation;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;
use Twig\Markup;

final readonly class ConsoleHomeController
{
    #[Route('/console', name: 'platform_console', methods: ['GET', 'HEAD'])]
    public function __invoke(ViewConsoleHome $view, Environment $twig): Response
    {
        $home = $view();
        $compatibility = $home->content->compatibility;
        $fragments = [];
        foreach (['head', 'before', 'after', 'bottom'] as $part) {
            // Installed PHP extensions execute with the installation's privileges.
            // This narrow boundary preserves their HTML; it is not a sanitizer
            // and never accepts template source or HTML from the client.
            $fragments[$part] = new Markup($compatibility?->$part ?? '', 'UTF-8');
        }

        $chrome = [];
        foreach ($compatibility?->chrome ?? [] as $part => $html) {
            $chrome[$part] = new Markup($html, 'UTF-8');
        }

        $template = $compatibility !== null && !$compatibility->fullPage
            ? 'platform/console_home_fragment.html.twig'
            : ($compatibility?->headerContent ? 'platform/console_home_shell.html.twig' : 'platform/console_home.html.twig');

        return new ConsoleHomeResponse($twig->render($template, [
            'home' => $home, 'compatibility' => $fragments, 'chrome' => $chrome,
        ]), $compatibility?->policies ?? []);
    }

    /** Old login forms posted to index.php; retain their body at the login boundary. */
    #[Route('/console', name: 'platform_console_login_post', methods: ['POST'])]
    public function loginPost(ConsoleLoginLocation $login): Response
    {
        return new RedirectResponse($login->path(), 307, ['Cache-Control' => 'private, no-store']);
    }
}
