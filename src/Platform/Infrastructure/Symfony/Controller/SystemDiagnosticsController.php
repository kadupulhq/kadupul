<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Platform\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Application\Port\RrdCheckAccess;
use Kadupul\Platform\Application\Port\SystemDiagnostics;
use Kadupul\Platform\Application\Query\RrdCheckAccessDenied;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticFilters;
use Kadupul\Platform\Domain\Diagnostics\DiagnosticScreen;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SystemDiagnosticsController
{
    #[Route('/utilities/system', name: 'platform_diagnostics_menu', defaults: ['screen' => 'menu'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/summary', name: 'platform_diagnostics_summary', defaults: ['screen' => 'summary'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/database', name: 'platform_diagnostics_database', defaults: ['screen' => 'database'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/database-settings', name: 'platform_diagnostics_database_settings', defaults: ['screen' => 'database-settings'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/database-status', name: 'platform_diagnostics_database_status', defaults: ['screen' => 'database-status'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/php', name: 'platform_diagnostics_php', defaults: ['screen' => 'php'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/changelog', name: 'platform_diagnostics_changelog', defaults: ['screen' => 'changelog'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/boost', name: 'platform_diagnostics_boost', defaults: ['screen' => 'boost'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/poller-cache', name: 'platform_diagnostics_poller_cache', defaults: ['screen' => 'poller-cache'], methods: ['GET', 'HEAD'])]
    #[Route('/utilities/system/data-query-cache', name: 'platform_diagnostics_data_query_cache', defaults: ['screen' => 'data-query-cache'], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $screen, ConsoleAccess $console, RrdCheckAccess $access, SystemDiagnostics $diagnostics, Environment $twig, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'diagnostics'), 401, $headers);
        }
        try {
            // RRD checks and System Utilities share the current-account and
            // realm 8/15 policy; reuse its authorizer before any input or reads.
            $actor = $access->authorize();
            $selected = DiagnosticScreen::from($screen);
            $filters = DiagnosticFilters::fromQuery($request->query->all(), $selected);
            $page = $diagnostics->read($selected, $filters, $actor->id);
        } catch (RrdCheckAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'diagnostics'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid diagnostic filters.', [], 'diagnostics'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load diagnostics. Reload before retrying.', [], 'diagnostics'), 502, $headers);
        }
        return new Response($request->isMethod('HEAD') ? '' : $twig->render('platform/system_diagnostics.html.twig', [
            'screen' => $selected, 'screens' => DiagnosticScreen::cases(), 'filters' => $filters, 'page' => $page,
        ]), 200, $headers);
    }
}
