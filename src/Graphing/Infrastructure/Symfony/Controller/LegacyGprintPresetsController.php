<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Port\GprintPresetStore;
use Kadupul\Graphing\Application\Port\GprintPresetAccess;
use Kadupul\Graphing\Application\Query\GprintPresetAccessDenied;
use Kadupul\Graphing\Application\Query\ListGprintPresets;
use Kadupul\Graphing\Domain\GprintPresetFilters;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyGprintPresetsController
{
    #[Route('/graphing/gprint-presets/legacy', name: 'gprint_preset_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, GprintPresetAccess $access, GprintPresetStore $store, ListGprintPresets $list, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'gprint'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            if ($request->isMethod('POST')) {
                return new Response($translator->trans('This legacy GPRINT form has expired. Open GPRINT Presets and submit a new form.', [], 'gprint'), 409, $headers);
            }
            $query = $request->query->all();
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
            }
            if ($action === 'edit') {
                $rawId = $query['id'] ?? '0';
                if (!is_string($rawId) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $rawId)) {
                    throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
                }
                $id = (int) $rawId;
                $context = array_intersect_key($query, array_flip(['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs']));
                $context = $context === [] ? [] : GprintPresetFilters::fromQuery($context, $store->defaultRows(), $store->defaultHasGraphs())->query();
                return new RedirectResponse($urls->generate($id === 0 ? 'gprint_preset_create' : 'gprint_preset_edit', ($id === 0 ? [] : ['id' => $id]) + $context), 302, $headers);
            }
            if ($action === 'actions') {
                return new Response($translator->trans('Open GPRINT Presets and use its current forms.', [], 'gprint'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            if ($action !== '') {
                throw new \InvalidArgumentException('Invalid GPRINT preset filters.');
            }
            $rows = $store->defaultRows();
            $hasGraphs = $store->defaultHasGraphs();
            $filters = GprintPresetFilters::fromQuery([
                'filter' => $query['filter'] ?? '',
                'rows' => $query['rows'] ?? '-1',
                'page' => $query['page'] ?? '1',
                'sort_column' => $query['sort_column'] ?? 'name',
                'sort_direction' => $query['sort_direction'] ?? 'ASC',
                'has_graphs' => $query['has_graphs'] ?? ($hasGraphs ? 'true' : 'false'),
            ], $rows, $hasGraphs);
            $list($filters);
            return new RedirectResponse($urls->generate('gprint_preset_list', $filters->query()), 302, $headers);
        } catch (GprintPresetAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'gprint'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid GPRINT preset filters.', [], 'gprint'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load GPRINT presets. Reload before retrying.', [], 'gprint'), 502, $headers);
        }
    }
}
