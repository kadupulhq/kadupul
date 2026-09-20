<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\EditDevice;
use Kadupul\Inventory\Application\Query\FindEditableDevice;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceEditType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class DeviceEditController
{
    #[Route('/inventory/devices/{id}/edit', name: 'inventory_device_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, FindEditableDevice $find, EditDevice $edit, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $device = $find($id);
        } catch (InventoryAccessDenied $error) {
            return new Response('Access denied.', $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($device === null) {
            return new Response('Device not found.', 404, $headers);
        }
        $form = $forms->create(DeviceEditType::class, ['description' => $device->description(), 'hostname' => $device->hostname(), 'notes' => $device->notes(), 'revision' => $device->revision()]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError('Unexpected fields were submitted.'));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $edit($id, (string) $data['description'], (string) $data['hostname'], (string) $data['notes'], (string) $data['revision']);
                    return new RedirectResponse($urls->generate('inventory_device_edit', ['id' => $id, 'saved' => 1]), 303, $headers);
                } catch (InventoryAccessDenied) {
                    return new Response('Access denied.', 403, $headers);
                } catch (DeviceEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($error->getMessage()));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($error->getMessage()));
                } catch (\RuntimeException $error) {
                    $status = 502;
                    $form->addError(new FormError('Save outcome is uncertain. Reload the device before retrying.'));
                }
            }
        }
        return new Response($twig->render('inventory/edit.html.twig', ['device' => $device, 'form' => $form->createView(), 'saved' => $request->query->get('saved') === '1']), $status, $headers);
    }
}
