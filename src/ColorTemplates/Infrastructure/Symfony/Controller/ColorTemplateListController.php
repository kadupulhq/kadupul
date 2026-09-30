<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Controller;

use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplatePreferences;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\ColorTemplates\Application\Query\ListColorTemplates;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ColorTemplateListController
{
    #[Route('/graphing/color-templates', name: 'color_template_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, ColorTemplatePreferences $preferences, ListColorTemplates $list, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $saved = $query['saved'] ?? null;
            $deleted = $query['deleted'] ?? null;
            $duplicated = $query['duplicated'] ?? null;
            $synced = $query['synced'] ?? null;
            $reset = $query['reset'] ?? null;
            unset($query['saved'], $query['deleted'], $query['duplicated'], $query['synced'], $query['reset']);
            foreach ([$saved, $deleted, $duplicated, $synced, $reset] as $flag) {
                if ($flag !== null && $flag !== '1') {
                    throw new \InvalidArgumentException('Invalid color template filters.');
                }
            }
            if ($query === [] && $reset !== '1') {
                $query = $preferences->load() ?? [];
            }
            $filters = ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs());
            if ($request->query->has('reset') || array_intersect(array_keys($request->query->all()), ['filter', 'rows', 'page', 'sort_column', 'sort_direction', 'has_graphs']) !== []) {
                $preferences->save($filters->query());
            }
            $page = $list($filters);
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template filters.', [], 'color_templates'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load color templates.', [], 'color_templates'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('color_templates/list.html.twig', [
            'page' => $page,
            'filters' => $filters->query(),
            'defaultRows' => $store->defaultRows(),
            'saved' => $saved === '1',
            'deleted' => $deleted === '1',
            'duplicated' => $duplicated === '1',
            'synced' => $synced === '1',
        ]), 200, $headers);
    }
}
