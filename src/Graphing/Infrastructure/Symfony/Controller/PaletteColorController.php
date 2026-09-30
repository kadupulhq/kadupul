<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Port\PaletteColorPreferences;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Application\Query\ListPaletteColors;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaletteColorController
{
    #[Route('/graphing/colors', name: 'palette_color_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, PaletteColorPreferences $preferences, ListPaletteColors $list, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, ['Cache-Control' => 'private, no-store']);
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
                throw new \InvalidArgumentException('Invalid color filters.');
            }
            if ($query === [] && $reset !== '1') {
                $query = $preferences->load() ?? [];
            }
            $filters = PaletteColorFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs());
            if ($request->query->has('reset') || array_intersect(array_keys($request->query->all()), ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs', 'named']) !== []) {
                $preferences->save($filters->query());
            }
            $page = $list($filters);
        } catch (PaletteColorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color filters.', [], 'palette'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load colors. Reload before retrying.', [], 'palette'), 502, $headers);
        }
        $parameters = $filters->query();
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/palette_colors.html.twig', [
            'page' => $page,
            'filters' => $parameters,
            'defaultRows' => $store->defaultRows(),
            'saved' => $saved === '1',
            'deleted' => $deleted === '1',
        ]), 200, $headers);
    }
}
