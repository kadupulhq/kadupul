<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\CreateSite;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\NewSite;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\SiteCreateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SiteCreateController
{
    #[Route('/inventory/sites/new', name: 'inventory_site_create', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $access, CreateSite $create, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $actor === null ? 401 : 403, $headers);
        }
        try {
            $filters = SiteListParameters::context($request->query->all());
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid site list filters.', [], 'inventory'), 400, $headers);
        }
        $form = $forms->create(SiteCreateType::class, NewSite::DEFAULTS, ['action' => $urls->generate('inventory_site_create', ['list' => $filters])]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $data['timezone'] ??= '';
                    $id = $create($data);

                    return new RedirectResponse($urls->generate('inventory_site_edit', ['id' => $id, 'list' => $filters, 'saved' => 1]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Creation outcome is uncertain. Check the site list before retrying.', [], 'inventory')));
                }
            }
        }

        return new Response($twig->render('inventory/site_create.html.twig', ['form' => $form->createView(), 'filters' => $filters]), $status, $headers);
    }
}
