<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LegacyDevicesController
{
    // Frozen legacy gettext aliases keep saved links valid across locale changes.
    private const UNASSIGNED_LOCATIONS = [
        'Indefinido',
        'Indéfini',
        'Mùi không xác định',
        'Nav noteikts',
        'Niet gedefinieerd',
        'Niezdefiniowane',
        'Non Definito',
        'Não definido',
        'Odefinierad',
        'Tanımlanmamış',
        'Undefined',
        'Undefiniert',
        'Δεν έχει οριστεί',
        'Не визначено',
        'Не определено',
        'Неопределена',
        'לא מוגדר',
        'غير مُحدد',
        'अपरिभाशत',
        '未定义',
        '未定義',
        '정의되지 않음',
    ];

    #[Route('/inventory/devices/legacy', name: 'inventory_devices_legacy', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $access, \Kadupul\Inventory\Application\Query\SuggestDeviceLocations $locations, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $actor === null ? 401 : 403, $headers);
        }
        if ($request->isMethod('POST')) {
            return new Response($translator->trans('This legacy form has expired. Open Devices and submit a new form.', [], 'inventory'), 409, $headers);
        }
        try {
            $query = $request->query->all();
            foreach ($query as $value) {
                if (!is_string($value)) {
                    throw new \InvalidArgumentException();
                }
            }
            $action = $query['action'] ?? '';
            if ($action === 'ajax_locations') {
                return new \Symfony\Component\HttpFoundation\JsonResponse(array_map(static fn(string $location): array => ['label' => $location,'value' => $location], $locations($query['term'] ?? '')), 200, $headers);
            }
            if ($action === 'edit') {
                $id = $query['id'] ?? '0';
                if ($id === '' || $id === '0') {
                    return new RedirectResponse($urls->generate('inventory_device_create'), 302, $headers);
                }
                $id = DeviceSelection::validateIds([$id])[0];
                return new RedirectResponse($urls->generate('inventory_device_edit', ['id' => $id]), 302, $headers);
            }
            $associations = ['gt_add' => 'graph','gt_remove' => 'graph','query_add' => 'query','query_remove' => 'query','query_change' => 'query'];
            $maintenance = ['reindex','query_reload','query_verbose','ping_host','enable_debug','disable_debug','repopulate'];
            if (isset($associations[$action]) || in_array($action, $maintenance, true)) {
                $id = DeviceSelection::validateIds([$query[$action === 'ping_host' ? 'id' : 'host_id'] ?? ''])[0];
                $route = isset($associations[$action]) ? 'inventory_device_associations' : 'inventory_device_maintenance';
                $parameters = ['id' => $id] + (isset($associations[$action]) ? ['kind' => $associations[$action]] : []);
                // Old mutation links only open a fresh CSRF-protected form.
                return new RedirectResponse($urls->generate($route, $parameters), 302, $headers);
            }
            if (!in_array($action, ['', 'export'], true)) {
                return new Response($translator->trans('Open Devices and use its current forms.', [], 'inventory'), 405, $headers + ['Allow' => 'GET, HEAD']);
            }
            $legacyStatus = $query['host_status'] ?? '-1';
            $status = ['-1' => 'all','-2' => 'disabled','-3' => 'all','-4' => 'not-up','0' => 'unknown','1' => 'down','2' => 'recovering','3' => 'up','4' => 'error'][$legacyStatus] ?? throw new \InvalidArgumentException();
            $filters = [
                'q' => $query['filter'] ?? '', 'state' => $legacyStatus === '-3' ? 'enabled' : 'all', 'status' => $status,
                'sort' => match ($query['sort_column'] ?? 'description') {
                    'description' => 'name', 'hostname' => 'hostname', default => throw new \InvalidArgumentException()
                },
                'direction' => strtolower($query['sort_direction'] ?? 'ASC'), 'page' => $query['page'] ?? '1',
                'size' => self::pageSize($query['rows'] ?? '-1'),
            ];
            foreach (['site_id' => 'site','host_template_id' => 'template','poller_id' => 'collector'] as $old => $new) {
                $filters[$new] = ($query[$old] ?? '-1') === '-1' ? '' : $query[$old];
            }
            if (($query['location'] ?? '-1') !== '-1') {
                $filters['location_mode'] = 'exact';
                $filters['location'] = in_array($query['location'], self::UNASSIGNED_LOCATIONS, true) ? '' : $query['location'];
            }
            $criteria = DeviceListParameters::parse($filters);
            return new RedirectResponse($urls->generate($action === 'export' ? 'inventory_devices_csv' : 'inventory_devices', DeviceListParameters::encode($criteria)), 302, $headers);
        } catch (\Kadupul\Inventory\Application\Query\InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device list filters.', [], 'inventory'), 400, $headers);
        }
    }
    private static function pageSize(string $rows): string
    {
        if ($rows === '-1') {
            return '25';
        }
        // Legacy menus allowed larger pages. Saved links use the nearest bounded size.
        if (!in_array($rows, ['10', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '30', '40', '44', '45', '50', '100', '250', '500', '750', '1000', '2000', '3000', '4000', '5000'], true)) {
            throw new \InvalidArgumentException('Invalid device list filters.');
        }
        return (int) $rows <= 25 ? '25' : ((int) $rows <= 50 ? '50' : '100');
    }

}
