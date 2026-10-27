<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\ManagementCatalogAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Query\ViewManagementCatalog;
use Kadupul\Navigation\Contract\Management\CatalogParentNotFound;
use Kadupul\Navigation\Contract\Management\CatalogScreen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class ManagementCatalogController
{
    #[Route('/management/graphs', name: 'management_graphs', defaults: ['screen' => 'graphs', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/data-sources', name: 'management_data_sources', defaults: ['screen' => 'data-sources', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/aggregate-graphs', name: 'management_aggregate_graphs', defaults: ['screen' => 'aggregate-graphs', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/reports', name: 'management_reports', defaults: ['screen' => 'reports', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/plugins', name: 'management_plugins', defaults: ['screen' => 'plugins', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/graph-template-items/{id}', name: 'management_graph_template_items', defaults: ['screen' => 'graph-template-items'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/graph-template-inputs/{id}', name: 'management_graph_template_inputs', defaults: ['screen' => 'graph-template-inputs'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/device-template-graphs/{id}', name: 'management_device_template_graphs', defaults: ['screen' => 'device-template-graphs'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/data-query-graphs/{id}', name: 'management_data_query_graphs', defaults: ['screen' => 'data-query-graphs'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    public function __invoke(CatalogScreen $screen, string $id, Request $request, ConsoleAccess $console, ManagementCatalogAccess $access, ViewManagementCatalog $view, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'management'), 401, $headers);
        }
        try {
            $access->authorize($screen);
            $result = $view($screen, $request->query->all(), $id);
        } catch (AdministrationListDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'management'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (CatalogParentNotFound) {
            return new Response($translator->trans('Parent record not found.', [], 'management'), 404, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid list filters.', [], 'management'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load this list. Reload before retrying.', [], 'management'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('management/catalog.html.twig', $result + [
            'screen' => $screen, 'parentId' => (int) $id,
        ]), 200, $headers);
    }
}
