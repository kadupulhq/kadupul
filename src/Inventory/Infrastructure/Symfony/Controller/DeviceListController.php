<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\ListDevices;
use Kadupul\Inventory\Domain\DeviceListCriteria;
use Kadupul\Inventory\Infrastructure\Symfony\Export\DevicePageCsv;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DeviceListController
{
    #[Route('/inventory/devices', name: 'inventory_devices', methods: ['GET', 'HEAD'])]
    #[Route('/inventory/devices.json', name: 'inventory_devices_json', defaults: ['_format' => 'json'], methods: ['GET', 'HEAD'])]
    #[Route('/inventory/devices.csv', name: 'inventory_devices_csv', defaults: ['_format' => 'csv'], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, ListDevices $listDevices, Environment $twig, DevicePageCsv $csv): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->query->all();
            foreach (['q', 'state', 'status', 'sort', 'direction', 'page', 'size'] as $key) {
                if (isset($query[$key]) && !is_string($query[$key])) {
                    throw new \InvalidArgumentException('Invalid device list filters.');
                }
            }
            $page = $query['page'] ?? '1';
            $size = $query['size'] ?? '25';
            if (!ctype_digit($page) || !ctype_digit($size) || strlen($page) > 6 || strlen($size) > 3) {
                throw new \InvalidArgumentException('Invalid device list filters.');
            }
            $criteria = new DeviceListCriteria($query['q'] ?? '', $query['state'] ?? 'all', (int) $page, (int) $size, $query['status'] ?? 'all', $query['sort'] ?? 'name', $query['direction'] ?? 'asc');
            $result = $listDevices($criteria);
        } catch (InventoryAccessDenied $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new JsonResponse(['error' => 'Invalid device list filters.'], 400, $headers);
        }

        if ($request->getRequestFormat() === 'csv') {
            return new Response($csv->encode($result), 200, $headers + [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="devices-page-' . $criteria->page . '.csv"',
            ]);
        }

        if ($request->getRequestFormat() === 'json') {
            return new JsonResponse(['devices' => $result->devices, 'page' => $criteria->page,
                'pageSize' => $criteria->pageSize, 'hasNext' => $result->hasNext], 200, $headers);
        }

        return new Response($twig->render('inventory/devices.html.twig', ['result' => $result, 'criteria' => $criteria]), 200, $headers);
    }
}
