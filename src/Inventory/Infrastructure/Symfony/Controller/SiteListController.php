<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\ListSites;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class SiteListController
{
    #[Route('/inventory/sites', name: 'inventory_sites', methods: ['GET', 'HEAD'])]
    #[Route('/inventory/sites.json', name: 'inventory_sites_json', defaults: ['_format' => 'json'], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ListSites $list, Environment $twig): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $criteria = SiteListParameters::parse($request->query->all());
            $result = $list($criteria);
        } catch (InventoryAccessDenied $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid site list filters.'], 400, $headers);
        }
        if ($request->getRequestFormat() === 'json') {
            return new JsonResponse(['sites' => $result->sites, 'page' => $criteria->page,
                'pageSize' => $criteria->pageSize, 'hasNext' => $result->hasNext], 200, $headers);
        }
        return new Response($twig->render('inventory/sites.html.twig', ['result' => $result, 'criteria' => $criteria, 'filters' => SiteListParameters::encode($criteria)]), 200, $headers);
    }
}
