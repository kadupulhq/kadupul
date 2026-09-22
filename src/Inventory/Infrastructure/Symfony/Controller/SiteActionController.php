<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\DeleteSites;
use Kadupul\Inventory\Application\Command\DuplicateSites;
use Kadupul\Inventory\Application\Command\SiteNotFound;
use Kadupul\Inventory\Application\Query\PrepareSiteAction;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\SiteSelection;
use Kadupul\Inventory\Domain\SiteEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\SiteActionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SiteActionController
{
    #[Route('/inventory/sites/{operation}', name: 'inventory_site_action', requirements: ['operation' => 'delete|duplicate'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $operation, Request $request, PrepareSiteAction $prepare, DeleteSites $delete, DuplicateSites $duplicate, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->query->all();
            $rawIds = $query['ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new \InvalidArgumentException('Invalid site selection.');
            }
            // The query authorizes before any repository access or selection read.
            $sites = $prepare($rawIds);
            $ids = SiteSelection::validateIds($rawIds);
            $filters = SiteListParameters::context($query);
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (SiteNotFound) {
            return new Response($translator->trans('Site not found.', [], 'inventory'), 404, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'inventory'), 400, $headers);
        }
        $revisions = [];
        foreach ($sites as $site) {
            $revisions[$site->id] = $site->revision();
        }
        $parameters = ['operation' => $operation, 'ids' => $ids, 'list' => $filters];
        $form = $forms->create(SiteActionType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR), 'pattern' => '<site> (1)'], ['duplicate' => $operation === 'duplicate', 'action' => $urls->generate('inventory_site_action', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $raw = json_decode((string) $data['selection'], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($raw)) {
                        throw new \InvalidArgumentException('Invalid site selection.');
                    }
                    $selection = new SiteSelection($raw);
                    if (array_keys($selection->revisions) !== $ids) {
                        throw new \InvalidArgumentException('Invalid site selection.');
                    }
                    if ($operation === 'delete') {
                        $delete($selection);
                    } else {
                        $duplicate($selection, (string) $data['pattern']);
                    }
                    return new RedirectResponse($urls->generate('inventory_sites', $filters + ['completed' => $operation]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (SiteNotFound) {
                    return new Response($translator->trans('Site not found.', [], 'inventory'), 404, $headers);
                } catch (SiteEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\JsonException|\InvalidArgumentException) {
                    $form->addError(new FormError($translator->trans('Invalid site selection or copy name.', [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Site operation outcome is uncertain. Check the site list before retrying.', [], 'inventory')));
                }
            }
        }
        return new Response($twig->render('inventory/site_action.html.twig', ['form' => $form->createView(), 'sites' => $sites, 'operation' => $operation, 'filters' => $filters]), $status, $headers);
    }
}
