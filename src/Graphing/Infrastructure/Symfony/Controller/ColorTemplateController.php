<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Application\Port\ColorTemplatePreferences;
use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ColorTemplateController
{
    #[Route('/graphing/color-templates', name: 'color_template_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, ColorTemplatePreferences $preferences, LegacyConfiguration $configuration, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $flags = [];
            foreach (['deleted', 'duplicated', 'reset'] as $flag) {
                $flags[$flag] = $query[$flag] ?? null;
                unset($query[$flag]);
                if ($flags[$flag] !== null && $flags[$flag] !== '1') {
                    throw new \InvalidArgumentException('Invalid color template filters.');
                }
            }
            $reset = $flags['reset'];
            if ($query === [] && $reset !== '1') {
                $query = $preferences->load() ?? [];
            }
            $filters = ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs());
            if (($configuration->values()['collector_id'] ?? null) === 1
                && ($request->query->has('reset') || array_intersect(array_keys($request->query->all()), ColorTemplateFilters::KEYS) !== [])) {
                $preferences->save($filters->query());
            }
            $page = $store->list($filters);
        } catch (ColorTemplateAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template filters.', [], 'color_template'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Templates. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
        $parameters = $filters->query();
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/color_templates.html.twig', [
            'page' => $page,
            'filters' => $parameters,
            'defaultRows' => $store->defaultRows(),
            'deleted' => $flags['deleted'] === '1',
            'duplicated' => $flags['duplicated'] === '1',
        ]), 200, $headers);
    }
}
