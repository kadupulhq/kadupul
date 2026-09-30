<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Application\Query\ListPaletteColors;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyPaletteColorsController
{
    #[Route('/graphing/colors/legacy', name: 'palette_color_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, ListPaletteColors $list, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy color form has expired. Open Colors and submit a new form.', [], 'palette'), 409, $headers);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid color filters.');
            }
            if ($action === 'edit') {
                $rawId = $query['id'] ?? '0';
                if (!is_string($rawId) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $rawId)) {
                    throw new \InvalidArgumentException('Invalid color filters.');
                }
                $id = (int) $rawId;
                $context = array_intersect_key($query, array_flip(['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs', 'named']));
                $context = $context === [] ? [] : PaletteColorFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasGraphs())->query();
                return new RedirectResponse($urls->generate($id === 0 ? 'palette_color_create' : 'palette_color_edit', ($id === 0 ? [] : ['id' => $id]) + $context), 302, $headers);
            }
            if (in_array($action, ['import', 'export'], true)) {
                if ($action === 'import') {
                    return new RedirectResponse($urls->generate('palette_color_import'), 302, $headers);
                }
                $context = array_intersect_key($query, array_flip(['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs', 'named']));
                $context = $context === [] ? [] : PaletteColorFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasGraphs())->query();
                return new RedirectResponse($urls->generate('palette_color_export', $context), 302, $headers);
            }
            if (in_array($action, ['actions', 'remove', 'save'], true)) {
                return new Response($translator->trans('Open Colors and use its current forms.', [], 'palette'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid color filters.');
            }
            $rows = $store->defaultRows();
            $hasGraphs = $store->defaultHasGraphs();
            $filters = PaletteColorFilters::fromQuery([
                'filter' => $query['filter'] ?? '',
                'rows' => $query['rows'] ?? '-1',
                'page' => $query['page'] ?? '1',
                'sort_column' => $query['sort_column'] ?? 'name',
                'sort_direction' => $query['sort_direction'] ?? 'ASC',
                'named' => $query['named'] ?? 'true',
                'has_graphs' => $query['has_graphs'] ?? ($hasGraphs ? 'true' : 'false'),
            ], $rows, $hasGraphs);
            $list($filters);
            return new RedirectResponse($urls->generate('palette_color_list', $filters->query()), 302, $headers);
        } catch (PaletteColorAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color filters.', [], 'palette'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load colors. Reload before retrying.', [], 'palette'), 502, $headers);
        }
    }
}
