<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2004-2026 The Cacti Group
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\Navigation\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Navigation\Application\Port\RelationshipAccess;
use Kadupul\Navigation\Application\Query\AdministrationListDenied;
use Kadupul\Navigation\Application\Query\ViewRelationships;
use Kadupul\Navigation\Contract\Relationships\RelationshipParentNotFound;
use Kadupul\Navigation\Contract\Relationships\RelationshipScreen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class RelationshipController
{
    #[Route('/relationships/device-template-queries/{id}', name: 'relationships_device_template_queries', defaults: ['screen' => 'device-template-queries'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/data-template-definitions/{id}', name: 'relationships_data_template_definitions', defaults: ['screen' => 'data-template-definitions'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/aggregate-template-items/{id}', name: 'relationships_aggregate_template_items', defaults: ['screen' => 'aggregate-template-items'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/snmp-option-entries/{id}', name: 'relationships_snmp_option_entries', defaults: ['screen' => 'snmp-option-entries'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/graph-rule-device-criteria/{id}', name: 'relationships_graph_rule_device_criteria', defaults: ['screen' => 'graph-rule-device-criteria'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/graph-rule-query-criteria/{id}', name: 'relationships_graph_rule_query_criteria', defaults: ['screen' => 'graph-rule-query-criteria'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/tree-rule-device-criteria/{id}', name: 'relationships_tree_rule_device_criteria', defaults: ['screen' => 'tree-rule-device-criteria'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/tree-rule-steps/{id}', name: 'relationships_tree_rule_steps', defaults: ['screen' => 'tree-rule-steps'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/receiver-subscriptions/{id}', name: 'relationships_receiver_subscriptions', defaults: ['screen' => 'receiver-subscriptions'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    #[Route('/relationships/receiver-history/{id}', name: 'relationships_receiver_history', defaults: ['screen' => 'receiver-history'], requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD'])]
    public function __invoke(RelationshipScreen $screen, string $id, Request $request, ConsoleAccess $console, RelationshipAccess $access, ViewRelationships $view, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'relationships'), 401, $headers);
        }
        try {
            $access->authorize($screen);
            $result = $view($screen, $request->query->all(), $id);
        } catch (AdministrationListDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'relationships'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (RelationshipParentNotFound) {
            return new Response($translator->trans('Parent record not found.', [], 'relationships'), 404, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid list filters.', [], 'relationships'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load these relationships. Reload before retrying.', [], 'relationships'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('administration/relationships.html.twig', $result + ['screen' => $screen]), 200, $headers);
    }
}
