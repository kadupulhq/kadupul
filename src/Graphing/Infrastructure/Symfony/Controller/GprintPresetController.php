<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Port\GprintPresetPreferences;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Application\Query\ListGprintPresets;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class GprintPresetController
{
    #[Route('/graphing/gprint-presets', name: 'gprint_preset_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, GprintPresetAccess $access, GprintPresetStore $store, GprintPresetPreferences $preferences, ListGprintPresets $list, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'gprint'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $saved = $query['saved'] ?? null;
            $deleted = $query['deleted'] ?? null;
            $reset = $query['reset'] ?? null;
            unset($query['saved'], $query['deleted']);
            unset($query['reset']);
            if (($saved !== null && $saved !== '1') || ($deleted !== null && $deleted !== '1') || ($reset !== null && $reset !== '1')) {
                throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
            }
            if ($query === [] && $reset !== '1') {
                $query = $preferences->load() ?? [];
            }
            $defaultRows = $store->defaultRows();
            $filters = GprintPresetFilters::fromQuery($query, $defaultRows, $store->defaultHasGraphs());
            if ($request->query->has('reset') || array_intersect(array_keys($request->query->all()), ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs']) !== []) {
                $preferences->save($filters->query());
            }
            $page = $list($filters);
        } catch (GprintPresetAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'gprint'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid GPRINT preset filters.', [], 'gprint'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load GPRINT presets. Reload before retrying.', [], 'gprint'), 502, $headers);
        }
        $parameters = $filters->query();
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/gprint_presets.html.twig', [
            'page' => $page,
            'filters' => $parameters,
            'defaultRows' => $defaultRows,
            'saved' => $saved === '1',
            'deleted' => $deleted === '1',
        ]), 200, $headers);
    }
}
