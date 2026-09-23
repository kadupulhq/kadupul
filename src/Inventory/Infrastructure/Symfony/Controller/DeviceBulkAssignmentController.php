<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Query\PrepareDeviceStateChange;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceBulkAssignmentController
{
    #[Route('/inventory/devices/assign/{kind}', name: 'inventory_device_bulk_assignment', requirements: ['kind' => 'site|template|collector'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $kind, Request $request, PrepareDeviceStateChange $prepare, \Kadupul\Inventory\Application\Query\ListDeviceAssignmentTargets $targets, \Kadupul\Inventory\Application\Command\AssignDevices $assign, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, \Kadupul\Inventory\Infrastructure\Symfony\DeviceAssignmentForm $validation): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $query = $request->query->all();
            $rawIds = $query['ids'] ?? [];
            if (!is_array($rawIds)) {
                throw new \InvalidArgumentException('Invalid device selection.');
            }
            // The query authorizes before any repository access or selection read.
            $devices = $prepare($rawIds);
            $choices = $targets($kind);
            $ids = DeviceSelection::validateIds($rawIds);
            $filters = DeviceListParameters::context($query);
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (DevicesNotFound) {
            return new Response($translator->trans('Selected devices were not found.', [], 'inventory'), 404, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'inventory'), 400, $headers);
        }
        $revisions = [];
        foreach ($devices as $device) {
            $revisions[$device->id] = $device->revision();
        }
        $parameters = ['kind' => $kind, 'ids' => $ids, 'list' => $filters];
        $form = $forms->create(\Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceBulkAssignmentType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR)], ['targets' => $choices, 'kind' => $kind, 'action' => $urls->generate('inventory_device_bulk_assignment', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $validation->validate($form, 'target', 'Select a valid assignment target.');
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $raw = json_decode((string) $data['selection'], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($raw)) {
                        throw new \InvalidArgumentException('Invalid device selection.');
                    }
                    $selection = new DeviceSelection($raw);
                    if (array_keys($selection->revisions) !== $ids) {
                        throw new \InvalidArgumentException('Invalid device selection.');
                    }
                    $assign($selection, new \Kadupul\Inventory\Domain\DeviceBulkAssignment($kind, $data['target']));
                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['completed' => 'assign-' . $kind]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (DevicesNotFound) {
                    return new Response($translator->trans('Selected devices were not found.', [], 'inventory'), 404, $headers);
                } catch (DeviceEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\JsonException) {
                    $form->addError(new FormError($translator->trans('Invalid device selection.', [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Device operation outcome is uncertain. Check every selected device before retrying.', [], 'inventory')));
                }
            }
        }
        return new Response($twig->render('inventory/device_bulk_assignment.html.twig', ['form' => $form->createView(), 'devices' => $devices, 'kind' => $kind, 'filters' => $filters]), $status, $headers);
    }
}
