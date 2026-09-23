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

final class DevicePlacementController
{
    #[Route('/inventory/devices/place/{kind}', name: 'inventory_device_placement', requirements: ['kind' => 'tree|report'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(string $kind, Request $request, PrepareDeviceStateChange $prepare, \Kadupul\Inventory\Application\Query\ListDevicePlacementDestinations $targets, \Kadupul\Inventory\Application\Command\PlaceDevices $assign, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, \Kadupul\Inventory\Infrastructure\Symfony\DeviceAssignmentForm $validation): Response
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
            $defaults = $kind === 'report' ? $targets->reportDefaults() : [];
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
        $form = $forms->create(\Kadupul\Inventory\Infrastructure\Symfony\Form\DevicePlacementType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR)] + $defaults, ['targets' => $choices, 'kind' => $kind, 'action' => $urls->generate('inventory_device_placement', $parameters)]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== [] || !is_string($form->get('target')->getData()) || $form->get('target')->getData() === '') {
                $form->addError(new FormError($translator->trans('Select a valid placement destination.', [], 'inventory')));
            }
            if ($kind === 'report') {
                $validation->validate($form, 'timespan', 'Select valid report display settings.');
                $validation->validate($form, 'alignment', 'Select valid report display settings.');
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
                    $assign($selection, new \Kadupul\Inventory\Domain\DevicePlacement($kind, $data['target'], $data['timespan'] ?? 0, $data['alignment'] ?? 0));
                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['completed' => 'place-' . $kind]), 303, $headers);
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
        return new Response($twig->render('inventory/device_placement.html.twig', ['form' => $form->createView(), 'devices' => $devices, 'kind' => $kind, 'filters' => $filters]), $status, $headers);
    }
}
