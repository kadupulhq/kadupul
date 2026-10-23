<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\DataSourceProfileAccess;
use Kadupul\Graphing\Application\Port\DataSourceProfilePreferences;
use Kadupul\Graphing\Application\Port\DataSourceProfileStore;
use Kadupul\Graphing\Application\Query\DataSourceProfileAccessDenied;
use Kadupul\Graphing\Domain\DataSourceProfileFilters;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DataSourceProfileController
{
    #[Route('/graphing/data-source-profiles', name: 'data_source_profile_list', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, DataSourceProfileAccess $access, DataSourceProfileStore $store, DataSourceProfilePreferences $preferences, LegacyConfiguration $configuration, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $flags = [];
            foreach (['clear', 'deleted', 'duplicated'] as $flag) {
                $flags[$flag] = $query[$flag] ?? null;
                unset($query[$flag]);
                if ($flags[$flag] !== null && $flags[$flag] !== '1') {
                    throw new \InvalidArgumentException('Invalid profile filters.');
                }
            }
            if ($query === [] && $flags['clear'] === null) {
                $query = $preferences->load() ?? [];
            }
            $defaultRows = $store->defaultRows();
            $filters = DataSourceProfileFilters::fromQuery($query, $defaultRows, $store->defaultHasData());
            if (($configuration->values()['collector_id'] ?? null) === 1
                && ($flags['clear'] !== null || array_intersect(array_keys($request->query->all()), DataSourceProfileFilters::KEYS) !== [])) {
                $preferences->save($filters->query());
            }
            $page = $store->list($filters);
        } catch (DataSourceProfileAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'data_source_profile'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid profile filters.', [], 'data_source_profile'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Data Source Profiles. Reload before retrying.', [], 'data_source_profile'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('graphing/data_source_profiles.html.twig', [
            'page' => $page,
            'filters' => $filters->query(),
            'defaultRows' => $defaultRows,
            'deleted' => $flags['deleted'] === '1',
            'duplicated' => $flags['duplicated'] === '1',
        ]), 200, $headers);
    }
}
