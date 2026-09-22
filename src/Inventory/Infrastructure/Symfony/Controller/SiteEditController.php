<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\EditSite;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Query\FindEditableSite;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\SiteEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\SiteEditType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SiteEditController
{
    #[Route('/inventory/sites/{id}/edit', name: 'inventory_site_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, FindEditableSite $find, EditSite $edit, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $site = $find($id);
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($site === null) {
            return new Response($translator->trans('Site not found.', [], 'inventory'), 404, $headers);
        }
        $query = $request->query->all();
        try {
            $filters = SiteListParameters::context($query);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid site list filters.', [], 'inventory'), 400, $headers);
        }
        $parameters = ['id' => $id, 'list' => $filters];
        $form = $forms->create(SiteEditType::class, $site->fields() + ['revision' => $site->revision()], ['action' => $urls->generate('inventory_site_edit', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                $data['timezone'] ??= '';
                try {
                    $revision = (string) $data['revision'];
                    unset($data['revision']);
                    $edit($id, (string) $data['name'], (string) $data['notes'], $revision, $data);
                    return new RedirectResponse($urls->generate('inventory_site_edit', $parameters + ['saved' => 1]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (SiteNotFound) {
                    return new Response($translator->trans('Site not found.', [], 'inventory'), 404, $headers);
                } catch (SiteEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload the site before retrying.', [], 'inventory')));
                }
            }
        }
        return new Response($twig->render('inventory/site_edit.html.twig', ['site' => $site, 'form' => $form->createView(), 'saved' => ($query['saved'] ?? null) === '1', 'filters' => $filters]), $status, $headers);
    }
}
