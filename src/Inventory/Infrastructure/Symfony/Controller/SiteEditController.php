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

final class SiteEditController
{
    #[Route('/inventory/sites/{id}/edit', name: 'inventory_site_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, FindEditableSite $find, EditSite $edit, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $site = $find($id);
        } catch (InventoryAccessDenied $error) {
            return new Response('Access denied.', $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($site === null) {
            return new Response('Site not found.', 404, $headers);
        }
        $query = $request->query->all();
        try {
            $filters = SiteListParameters::context($query);
        } catch (\InvalidArgumentException) {
            return new Response('Invalid site list filters.', 400, $headers);
        }
        $parameters = ['id' => $id, 'list' => $filters];
        $form = $forms->create(SiteEditType::class, ['name' => $site->name(), 'notes' => $site->notes(), 'revision' => $site->revision()], ['action' => $urls->generate('inventory_site_edit', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError('Unexpected fields were submitted.'));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $edit($id, (string) $data['name'], (string) $data['notes'], (string) $data['revision']);
                    return new RedirectResponse($urls->generate('inventory_site_edit', $parameters + ['saved' => 1]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response('Access denied.', $error->unauthenticated ? 401 : 403, $headers);
                } catch (SiteNotFound) {
                    return new Response('Site not found.', 404, $headers);
                } catch (SiteEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($error->getMessage()));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($error->getMessage()));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError('Save outcome is uncertain. Reload the site before retrying.'));
                }
            }
        }
        return new Response($twig->render('inventory/site_edit.html.twig', ['site' => $site, 'form' => $form->createView(), 'saved' => ($query['saved'] ?? null) === '1', 'filters' => $filters]), $status, $headers);
    }
}
