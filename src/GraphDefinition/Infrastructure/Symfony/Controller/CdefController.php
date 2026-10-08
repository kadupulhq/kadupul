<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\CdefAccess;
use Kadupul\GraphDefinition\Application\Port\CdefPreferences;
use Kadupul\GraphDefinition\Application\Port\CdefStore;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Domain\CdefFilters;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CdefController
{
    #[Route('/graph-definitions/cdefs', name: 'graph_cdefs', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, CdefAccess $access, CdefStore $store, CdefPreferences $preferences, LegacyConfiguration $configuration, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $flags = [];
            foreach (['clear', 'deleted', 'duplicated'] as $flag) {
                $flags[$flag] = $query[$flag] ?? null;
                unset($query[$flag]);
                if ($flags[$flag] !== null && $flags[$flag] !== '1') {
                    throw new \InvalidArgumentException('Invalid CDEF filters.');
                }
            }
            if ($query === [] && $flags['clear'] === null) {
                $query = $preferences->load() ?? [];
            }
            $defaultRows = $store->defaultRows();
            $filters = CdefFilters::fromQuery($query, $defaultRows, $store->defaultHasGraphs());
            if (($configuration->values()['collector_id'] ?? null) === 1
                && ($flags['clear'] !== null || array_intersect(array_keys($request->query->all()), CdefFilters::KEYS) !== [])) {
                $preferences->save($filters->query());
            }
            $page = $store->list($filters);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'cdef'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid CDEF filters.', [], 'cdef'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load CDEFs. Reload before retrying.', [], 'cdef'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graph_definition/cdefs.html.twig', [
            'page' => $page,
            'filters' => $filters->query(),
            'defaultRows' => $defaultRows,
            'deleted' => $flags['deleted'] === '1',
            'duplicated' => $flags['duplicated'] === '1',
        ]), 200, $headers);
    }
}
