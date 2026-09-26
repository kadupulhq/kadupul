<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\ChangeDeviceAssociation;
use Kadupul\Inventory\Application\Query\PrepareDeviceAssociations;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceAssociationType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceAssignmentForm;

final class DeviceAssociationController
{
    #[Route('/inventory/devices/{id}/associations/{kind}', name: 'inventory_device_associations', requirements: ['id' => '[1-9][0-9]{0,7}', 'kind' => 'graph|query'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, string $kind, Request $request, PrepareDeviceAssociations $prepare, ChangeDeviceAssociation $assign, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, DeviceAssignmentForm $validation): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $view = $prepare($id, $kind);
            $device = $view['device'];
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
        $editParameters = ['id' => $id, 'kind' => $kind, 'list' => $filters];
        $form = $forms->create(DeviceAssociationType::class, ['revision' => $device->revision()] + ($kind === 'query' ? ['reindex' => $view['default_reindex']] : []), ['kind' => $kind, 'snmp_enabled' => $device->snmpVersion !== 0, 'action' => $urls->generate('inventory_device_associations', $editParameters), 'targets' => $device->items + $view['available']]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $validation->validate($form, 'target', 'Select a valid association change.');
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    if ($kind === 'query' && !is_int($data['reindex'])) {
                        throw new \InvalidArgumentException('Select a valid association change.');
                    }
                    $assign($id, new \Kadupul\Inventory\Domain\DeviceAssociationChange($kind, (string) $data['operation'], $data['target'], $data['reindex'] ?? 0), (string) $data['revision']);
                    return new RedirectResponse($urls->generate('inventory_device_associations', $editParameters + ['saved' => 1]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (DeviceEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\RuntimeException $error) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload the device before retrying.', [], 'inventory')));
                }
            }
        }
        return new Response($twig->render('inventory/associations.html.twig', ['kind' => $kind, 'device' => $device, 'form' => $form->createView(), 'saved' => ($query['saved'] ?? null) === '1', 'filters' => $filters]), $status, $headers);
    }
}
