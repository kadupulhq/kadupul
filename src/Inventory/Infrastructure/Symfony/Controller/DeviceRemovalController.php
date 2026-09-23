<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\RemoveDevices;
use Kadupul\Inventory\Application\Query\PrepareDeviceRemoval;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceRemovalType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class DeviceRemovalController
{
    #[Route('/inventory/devices/remove', name: 'inventory_device_remove', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, PrepareDeviceRemoval $prepare, RemoveDevices $remove, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, \Kadupul\Inventory\Infrastructure\Symfony\DeviceSelectionForm $selectionForm): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $prepared = $selectionForm->prepare($request, $prepare);
        if ($prepared instanceof Response) {
            return $prepared;
        }
        [$devices, $ids, $filters] = $prepared;
        $revisions = [];
        foreach ($devices as $device) {
            $revisions[$device->device->id] = $device->revision();
        }
        $parameters = ['ids' => $ids, 'list' => $filters];
        $form = $forms->create(DeviceRemovalType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR), 'policy' => 'retain'], [ 'action' => $urls->generate('inventory_device_remove', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $selectionForm->rejectExtraFields($form);
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $selection = $selectionForm->selection($data, $ids);
                    $remove($selection, DeviceRemovalPolicy::from($data['policy']));
                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['completed' => 'remove']), 303, $headers);
                } catch (\RuntimeException|\JsonException|\InvalidArgumentException $error) {
                    $status = $selectionForm->failure($error, $form, 'Device removal outcome is uncertain. Check selected devices, graphs and data sources before retrying.');
                    if ($status instanceof Response) {
                        return $status;
                    }
                }
            }
        }
        return new Response($twig->render('inventory/device_remove.html.twig', ['form' => $form->createView(), 'devices' => $devices, 'filters' => $filters]), $status, $headers);
    }
}
