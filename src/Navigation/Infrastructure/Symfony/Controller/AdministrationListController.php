<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Port\AdministrationListAccess;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Query\ViewAdministrationList;
use Kadupul\Navigation\Contract\Administration\ListScreen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class AdministrationListController
{
    #[Route('/templates/devices', name: 'admin_device_templates', defaults: ['screen' => 'device-templates'], methods: ['GET', 'HEAD'])]
    #[Route('/templates/graphs', name: 'admin_graph_templates', defaults: ['screen' => 'graph-templates'], methods: ['GET', 'HEAD'])]
    #[Route('/templates/data-sources', name: 'admin_data_templates', defaults: ['screen' => 'data-templates'], methods: ['GET', 'HEAD'])]
    #[Route('/templates/aggregates', name: 'admin_aggregate_templates', defaults: ['screen' => 'aggregate-templates'], methods: ['GET', 'HEAD'])]
    #[Route('/data-collection/queries', name: 'admin_data_queries', defaults: ['screen' => 'data-queries'], methods: ['GET', 'HEAD'])]
    #[Route('/automation/snmp-options', name: 'admin_snmp_options', defaults: ['screen' => 'snmp-options'], methods: ['GET', 'HEAD'])]
    #[Route('/automation/graph-rules', name: 'admin_graph_rules', defaults: ['screen' => 'graph-rules'], methods: ['GET', 'HEAD'])]
    #[Route('/automation/tree-rules', name: 'admin_tree_rules', defaults: ['screen' => 'tree-rules'], methods: ['GET', 'HEAD'])]
    #[Route('/automation/networks', name: 'admin_networks', defaults: ['screen' => 'networks'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/notification-receivers', name: 'admin_notification_receivers', defaults: ['screen' => 'notification-receivers'], methods: ['GET', 'HEAD'])]
    public function __invoke(ListScreen $screen, Request $request, ConsoleAccess $console, AdministrationListAccess $access, ViewAdministrationList $view, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'administration'), 401, $headers);
        }
        try {
            $access->authorize($screen);
            $result = $view($screen, $request->query->all());
        } catch (AdministrationListDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'administration'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid list filters.', [], 'administration'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load this list. Reload before retrying.', [], 'administration'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('administration/list.html.twig', [
            'screen' => $screen, 'filters' => $result['filters']->query(), 'records' => $result['records'],
            'hasNext' => $result['hasNext'], 'route' => $request->attributes->get('_route'),
        ]), 200, $headers);
    }
}
