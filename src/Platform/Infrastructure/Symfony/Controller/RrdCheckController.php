<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\RrdCheckPreferences;
use Kadupul\Platform\Application\Port\RrdCheckStore;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Platform\Domain\RrdCheck\RrdCheckFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class RrdCheckController
{
    #[Route('/utilities/rrd-check', name: 'platform_rrd_checks', methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ConsoleAccess $console, RrdCheckAccess $access, RrdCheckStore $store, RrdCheckPreferences $preferences, LegacyConfiguration $configuration, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), 401, $headers);
        }
        try {
            $access->authorize();
            $query = $request->query->all();
            $clear = $query['clear'] ?? null;
            $purged = $query['purged'] ?? null;
            unset($query['clear'], $query['purged']);
            if (($clear !== null && $clear !== '1') || ($purged !== null && $purged !== '1')) {
                throw new \InvalidArgumentException('Invalid RRD check filters.');
            }
            if ($query === [] && $clear === null) {
                $query = $preferences->load() ?? [];
            }
            $filters = RrdCheckFilters::fromQuery($query, $store->defaultRows());
            if (($configuration->values()['collector_id'] ?? null) === 1
                && ($clear !== null || array_intersect(array_keys($request->query->all()), RrdCheckFilters::KEYS) !== [])) {
                $preferences->save($filters->query());
            }
            $page = $store->list($filters);
            $enabled = $store->enabled();
            $defaultRows = $store->defaultRows();
        } catch (RrdCheckAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'rrd_check'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid RRD check filters.', [], 'rrd_check'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load RRD check problems. Reload before retrying.', [], 'rrd_check'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('platform/rrd_checks.html.twig', [
            'page' => $page,
            'filters' => $filters->query(),
            'defaultRows' => $defaultRows,
            'enabled' => $enabled,
            'purged' => $purged === '1',
        ]), 200, $headers);
    }
}
