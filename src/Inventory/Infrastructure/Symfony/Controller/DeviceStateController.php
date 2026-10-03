<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\ChangeDeviceOptions;
use Kadupul\Inventory\Application\Command\ClearDeviceStatistics;
use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Command\SetDevicesEnabled;
use Kadupul\Inventory\Application\Command\SynchronizeDeviceTemplates;
use Kadupul\Inventory\Application\Query\PrepareDeviceStateChange;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Domain\DeviceOptionsChange;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceSelectionForm;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceStateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class DeviceStateController
{
    #[Route('/inventory/devices/{operation}', name: 'inventory_device_state', requirements: ['operation' => 'enable|disable|clear-statistics|sync-template|options'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(
        string $operation,
        Request $request,
        PrepareDeviceStateChange $prepare,
        SetDevicesEnabled $setEnabled,
        ClearDeviceStatistics $clearStatistics,
        SynchronizeDeviceTemplates $synchronizeTemplates,
        ChangeDeviceOptions $changeOptions,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        DeviceSelectionForm $selectionForm,
    ): Response {
        $headers = ['Cache-Control' => 'private, no-store'];
        $prepared = $selectionForm->prepare($request, $prepare);
        if ($prepared instanceof Response) {
            return $prepared;
        }
        [$devices, $ids, $filters] = $prepared;
        $revisions = [];
        foreach ($devices as $device) {
            $revisions[$device->id] = $device->revision();
        }
        $parameters = ['operation' => $operation, 'ids' => $ids, 'list' => $filters];
        $form = $forms->create(
            DeviceStateType::class,
            ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR)] + ($operation === 'options' ? ['options' => DeviceOptionsChange::DEFAULTS] : []),
            ['edit_options' => $operation === 'options', 'action' => $urls->generate('inventory_device_state', $parameters)],
        );
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $selectionForm->rejectExtraFields($form);
            if ($form->has('options')) {
                $selectionForm->rejectExtraFields($form->get('options'));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $selection = $selectionForm->selection($data, $ids);
                    if ($operation === 'options') {
                        $changes = [];
                        foreach (DeviceOptionsChange::DEFAULTS as $field => $default) {
                            if ($data['options']['apply_' . $field] ?? false) {
                                $changes[$field] = $data['options'][$field];
                            }
                        }
                        $changeOptions($selection, new DeviceOptionsChange($changes));
                    } elseif ($operation === 'sync-template') {
                        $synchronizeTemplates($selection);
                    } elseif ($operation === 'clear-statistics') {
                        $clearStatistics($selection);
                    } else {
                        $setEnabled($selection, $operation === 'enable');
                    }
                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['completed' => $operation]), 303, $headers);
                } catch (InventoryAccessDenied|DevicesNotFound|DeviceEditConflict|\JsonException|\InvalidArgumentException|\RuntimeException $error) {
                    $status = $selectionForm->failure($error, $form, 'Device operation outcome is uncertain. Check every selected device before retrying.');
                    if ($status instanceof Response) {
                        return $status;
                    }
                }
            }
        }

        return new Response($twig->render('inventory/device_state.html.twig', ['form' => $form->createView(), 'devices' => $devices, 'operation' => $operation, 'filters' => $filters]), $status, $headers);
    }
}
