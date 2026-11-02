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
    #[Route('/management/graph-input-items/{id}', name: 'management_graph_input_items', defaults: ['screen' => 'graph-input-items'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/query-data-sources/{id}', name: 'management_query_data_sources', defaults: ['screen' => 'query-data-sources'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/query-graph-suggestions/{id}', name: 'management_query_graph_suggestions', defaults: ['screen' => 'query-graph-suggestions'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/query-data-suggestions/{id}', name: 'management_query_data_suggestions', defaults: ['screen' => 'query-data-suggestions'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/query-device-templates/{id}', name: 'management_query_device_templates', defaults: ['screen' => 'query-device-templates'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/graph-device-templates/{id}', name: 'management_graph_device_templates', defaults: ['screen' => 'graph-device-templates'], requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/snmp-mibs', name: 'management_snmp_mibs', defaults: ['screen' => 'snmp-mibs', 'id' => '0'], methods: ['GET', 'HEAD'])]
    #[Route('/management/mib-objects/{id}', name: 'management_mib_objects', defaults: ['screen' => 'mib-objects'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/mib-conventions/{id}', name: 'management_mib_conventions', defaults: ['screen' => 'mib-conventions'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/mib-notification-attributes/{id}', name: 'management_mib_notification_attributes', defaults: ['screen' => 'mib-notification-attributes'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/graph-items/{id}', name: 'management_graph_items', defaults: ['screen' => 'graph-items'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/graph-data-sources/{id}', name: 'management_graph_data_sources', defaults: ['screen' => 'graph-data-sources'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/aggregate-members/{id}', name: 'management_aggregate_members', defaults: ['screen' => 'aggregate-members'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/data-source-definitions/{id}', name: 'management_data_source_definitions', defaults: ['screen' => 'data-source-definitions'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/data-source-graphs/{id}', name: 'management_data_source_graphs', defaults: ['screen' => 'data-source-graphs'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/report-items/{id}', name: 'management_report_items', defaults: ['screen' => 'report-items'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/tree-items/{id}', name: 'management_tree_items', defaults: ['screen' => 'tree-items'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/collector-devices/{id}', name: 'management_collector_devices', defaults: ['screen' => 'collector-devices'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/management/network-devices/{id}', name: 'management_network_devices', defaults: ['screen' => 'network-devices'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
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
