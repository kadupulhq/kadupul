<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\ChangeDeviceOptions;
use Kadupul\Inventory\Application\Command\ChangeDevicesSnmp;
use Kadupul\Inventory\Application\Command\ClearDeviceStatistics;
use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Command\SetDevicesEnabled;
use Kadupul\Inventory\Application\Command\SynchronizeDeviceTemplates;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\PrepareDeviceStateChange;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Domain\DeviceOptionsChange;
use Kadupul\Inventory\Domain\DeviceBulkSnmpChange;
use Kadupul\Inventory\Domain\DeviceSnmpConfiguration;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceSelectionForm;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceStateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceStateController
{
    #[Route('/inventory/devices/{operation}', name: 'inventory_device_state', requirements: ['operation' => 'enable|disable|clear-statistics|sync-template|options|snmp'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(
        string $operation,
        Request $request,
        PrepareDeviceStateChange $prepare,
        SetDevicesEnabled $setEnabled,
        ClearDeviceStatistics $clearStatistics,
        SynchronizeDeviceTemplates $synchronizeTemplates,
        ChangeDeviceOptions $changeOptions,
        ChangeDevicesSnmp $changeSnmp,
        FormFactoryInterface $forms,
        Environment $twig,
        UrlGeneratorInterface $urls,
        DeviceSelectionForm $selectionForm,
        TranslatorInterface $translator,
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
            ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR)]
                + ($operation === 'snmp' ? ['snmp' => ['keep_credentials' => true] + DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS] : [])
                + ($operation === 'options' ? ['options' => DeviceOptionsChange::DEFAULTS] : []),
            ['edit_snmp' => $operation === 'snmp', 'edit_options' => $operation === 'options', 'action' => $urls->generate('inventory_device_state', $parameters)],
        );
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $selectionForm->rejectExtraFields($form);
            foreach (['snmp', 'options'] as $section) {
                if ($form->has($section)) {
                    $selectionForm->rejectExtraFields($form->get($section));
                }
            }

            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $selection = $selectionForm->selection($data, $ids);
                    if ($operation === 'snmp') {
                        $changes = ['keep_credentials' => $data['snmp']['keep_credentials']];
                        foreach (DeviceSnmpConfiguration::PUBLIC_DEFAULTS + DeviceSnmpConfiguration::CREDENTIAL_DEFAULTS as $field => $default) {
                            if ($data['snmp']['apply_' . $field] ?? false) {
                                $changes[$field] = $data['snmp'][$field];
                            }
                        }
                        $changeSnmp($selection, new DeviceBulkSnmpChange($changes));
                    } elseif ($operation === 'options') {
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
                } catch (\InvalidArgumentException $error) {
                    if ($operation === 'snmp' && $error->getMessage() !== 'Invalid device selection.') {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                    } else {
                        $status = $selectionForm->failure($error, $form, 'Device operation outcome is uncertain. Check every selected device before retrying.');
                        if ($status instanceof Response) {
                            return $status;
                        }
                    }
                } catch (InventoryAccessDenied|DevicesNotFound|DeviceEditConflict|\JsonException|\RuntimeException $error) {
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
