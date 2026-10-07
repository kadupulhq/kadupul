<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\MaintainDevice;
use Kadupul\Inventory\Application\Query\PrepareDeviceMaintenance;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceFormFailure;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceMaintenanceType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceAssignmentForm;

final class DeviceMaintenanceController
{
    #[Route('/inventory/devices/{id}/maintenance', name: 'inventory_device_maintenance', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, PrepareDeviceMaintenance $prepare, MaintainDevice $assign, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, DeviceAssignmentForm $validation, DeviceFormFailure $failures): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $device = $prepare($id);
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($device === null) {
            return new Response($translator->trans('Device not found.', [], 'inventory'), 404, $headers);
        }
        $query = $request->query->all();
        try {
            $filters = DeviceListParameters::context($query);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device list filters.', [], 'inventory'), 400, $headers);
        }
        $editParameters = ['id' => $id, 'list' => $filters];
        $result = null;
        $form = $forms->create(DeviceMaintenanceType::class, ['revision' => $device->revision(), 'query' => 0], ['action' => $urls->generate('inventory_device_maintenance', $editParameters), 'queries' => $device->queries]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $validation->validate($form, 'query', 'Select an associated data query.');
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $result = $assign($id, new \Kadupul\Inventory\Domain\DeviceMaintenanceRequest((string) $data['operation'], $data['query']), (string) $data['revision']);
                    $status = 200;
                    // Keep diagnostics visible and refresh the confirmation after state changes.
                    $device = $prepare($id) ?? $device;
                    $form = $forms->create(DeviceMaintenanceType::class, ['revision' => $device->revision(), 'query' => 0], ['action' => $urls->generate('inventory_device_maintenance', $editParameters), 'queries' => $device->queries]);
                } catch (\RuntimeException|\InvalidArgumentException $error) {
                    $status = $failures->apply($form, $error, $status);
                    if ($status instanceof Response) {
                        return $status;
                    }
                }
            }
        }
        return new Response($twig->render('inventory/maintenance.html.twig', ['result' => $result, 'device' => $device, 'form' => $form->createView(), 'filters' => $filters]), $status, $headers);
    }
}
