<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Query\FindDeviceDetails;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final class DeviceDetailsController
{
    #[Route('/inventory/devices/{id}', name: 'inventory_device_details', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD'])]
    public function __invoke(int $id, Request $request, FindDeviceDetails $find, Environment $twig): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $details = $find($id);
        } catch (InventoryAccessDenied $error) {
            return new Response('Access denied.', $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($details === null) {
            return new Response('Device not found.', 404, $headers);
        }
        try {
            $filters = DeviceListParameters::context($request->query->all());
        } catch (\InvalidArgumentException) {
            return new Response('Invalid device list filters.', 400, $headers);
        }
        return new Response($twig->render('inventory/details.html.twig', ['details' => $details, 'filters' => $filters]), 200, $headers);
    }
}
