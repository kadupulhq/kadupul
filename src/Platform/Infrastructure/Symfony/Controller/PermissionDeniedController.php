<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\Platform\Application\Query\ViewPermissionDenied;
use Kadupul\Platform\Infrastructure\Symfony\ConsoleHomeResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class PermissionDeniedController
{
    #[Route('/permission-denied', name: 'platform_permission_denied', methods: ['GET', 'HEAD'])]
    public function __invoke(ViewPermissionDenied $view, Environment $twig, TranslatorInterface $translator): Response
    {
        // ConsoleHomeExceptionSubscriber maps the anonymous refusal, the
        // worker's installer redirect and its failures.
        $denied = $view();
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($denied->replacement !== null) {
            // A custom_denied extension printed the whole page, as it did under
            // the native page; it keeps the native CSP its nonces were made for.
            return new ConsoleHomeResponse($denied->replacement, $denied->policies);
        }
        if ($denied->ajax) {
            // Same status, reason phrase and text as raise_ajax_permission_denied().
            $response = new Response($translator->trans('You are not permitted to access this section of Kadupul.', [], 'console')
                . '  ' . $translator->trans('If you feel that this is an error. Please contact your Kadupul Administrator.', [], 'console'), 401, $headers);

            return $response->setStatusCode(401, $translator->trans('Permission Denied', [], 'console'));
        }

        return new Response($twig->render('platform/permission_denied.html.twig', ['denied' => $denied]), 200, $headers);
    }
}
