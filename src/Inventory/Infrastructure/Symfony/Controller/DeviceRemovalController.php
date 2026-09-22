<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\RemoveDevices;
use Kadupul\Inventory\Application\Command\DevicesNotFound;
use Kadupul\Inventory\Application\Query\PrepareDeviceRemoval;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceSelection;
use Kadupul\Inventory\Domain\DeviceRemovalPolicy;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceRemovalType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceRemovalController
{
    #[Route('/inventory/devices/remove', name: 'inventory_device_remove', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, PrepareDeviceRemoval $prepare, RemoveDevices $remove, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
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
            $revisions[$device->device->id] = $device->revision();
        }
        $parameters = ['ids' => $ids, 'list' => $filters];
        $form = $forms->create(DeviceRemovalType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR), 'policy' => 'retain'], [ 'action' => $urls->generate('inventory_device_remove', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
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
                    $remove($selection, DeviceRemovalPolicy::from($data['policy']));
                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['completed' => 'remove']), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (DevicesNotFound) {
                    return new Response($translator->trans('Selected devices were not found.', [], 'inventory'), 404, $headers);
                } catch (DeviceEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\JsonException|\InvalidArgumentException) {
                    $form->addError(new FormError($translator->trans('Invalid device selection.', [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Device removal outcome is uncertain. Check selected devices, graphs and data sources before retrying.', [], 'inventory')));
                }
            }
        }
        return new Response($twig->render('inventory/device_remove.html.twig', ['form' => $form->createView(), 'devices' => $devices, 'filters' => $filters]), $status, $headers);
    }
}
