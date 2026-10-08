<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\RrdCheckStore;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Infrastructure\Symfony\Form\RrdCheckPurgeType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class RrdCheckPurgeController
{
    #[Route('/utilities/rrd-check/purge', name: 'platform_rrd_check_purge', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, RrdCheckAccess $access, RrdCheckStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $form = $forms->create(RrdCheckPurgeType::class, null, ['action' => $urls->generate('platform_rrd_check_purge')]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'rrd_check')));
                }
                if ($form->isValid()) {
                    try {
                        $store->purge($actor->id);
                        return new RedirectResponse($urls->generate('platform_rrd_checks', ['purged' => '1']), 303, $headers);
                    } catch (RrdCheckAccessDenied $error) {
                        throw $error;
                    } catch (\Throwable) {
                        $status = 502;
                        $form->addError(new FormError($translator->trans('Purge outcome is uncertain. Check the RRD check list before retrying.', [], 'rrd_check')));
                    }
                }
            }
            $count = $store->count();
        } catch (RrdCheckAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load RRD check problems. Reload before retrying.', [], 'rrd_check'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('platform/rrd_check_purge.html.twig', [
            'form' => $form->createView(),
            'count' => $count,
        ]), $status, $headers);
    }
}
