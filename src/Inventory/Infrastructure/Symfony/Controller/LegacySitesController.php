<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Domain\SiteSelection;
use Kadupul\Inventory\Infrastructure\Symfony\SiteListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacySitesController
{
    #[Route('/inventory/sites/legacy', name: 'inventory_sites_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $access, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $actor === null ? 401 : 403, $headers);
        }
        // Never replay a legacy mutation or interpret serialized selections.
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open Sites and submit a new form.', [], 'inventory'), 409, $headers);
        }
        $query = $request->query->all();
        try {
            $action = $query['action'] ?? '';
            if (!is_string($action)) {
                throw new \InvalidArgumentException();
            }
            if ($action === 'ajax_tz') {
                $term = $query['term'] ?? '';
                if (!is_string($term) || strlen($term) > 200 || !mb_check_encoding($term, 'UTF-8') || str_contains($term, "\0")) {
                    throw new \InvalidArgumentException();
                }
                $zones = array_slice(array_values(array_filter(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), static fn(string $zone): bool => stripos($zone, $term) !== false)), 0, 100);
                return new JsonResponse(array_map(static fn(string $zone): array => ['label' => $zone, 'value' => $zone], $zones), 200, $headers);
            }
            if ($action === 'edit') {
                $id = $query['id'] ?? '0';
                if ($id === '' || $id === '0') {
                    return new RedirectResponse($urls->generate('inventory_site_create'), 302, $headers);
                }
                $ids = SiteSelection::validateIds([$id]);
                return new RedirectResponse($urls->generate('inventory_site_edit', ['id' => $ids[0]]), 302, $headers);
            }
            if ($action !== '') {
                return new Response($translator->trans('Open Sites and use its current forms.', [], 'inventory'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            $criteria = SiteListParameters::parse(['q' => $query['filter'] ?? '', 'page' => $query['page'] ?? '1', 'size' => ($query['rows'] ?? '-1') === '-1' ? '25' : $query['rows'], 'sort' => ($query['sort_column'] ?? '') === 'hosts' ? 'devices' : ($query['sort_column'] ?? 'name'), 'direction' => strtolower(is_string($query['sort_direction'] ?? '') ? ($query['sort_direction'] ?? 'asc') : '')]);
            return new RedirectResponse($urls->generate('inventory_sites', SiteListParameters::encode($criteria)), 302, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid site list filters.', [], 'inventory'), 400, $headers);
        }
    }
}
