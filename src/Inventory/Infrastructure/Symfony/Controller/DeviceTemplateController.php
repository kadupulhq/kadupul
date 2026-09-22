<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\Inventory\Application\Command\AssignDeviceTemplate;
use Kadupul\Inventory\Application\Query\PrepareDeviceTemplateAssignment;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceFormFailure;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceTemplateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceFormPage;
use Symfony\Contracts\Translation\TranslatorInterface;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceAssignmentForm;

final class DeviceTemplateController
{
    #[Route('/inventory/devices/{id}/template', name: 'inventory_device_template', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, PrepareDeviceTemplateAssignment $prepare, AssignDeviceTemplate $assign, FormFactoryInterface $forms, DeviceFormPage $page, UrlGeneratorInterface $urls, TranslatorInterface $translator, DeviceAssignmentForm $validation, DeviceFormFailure $failures): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $view = $prepare($id);
            $device = $view['device'];
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        }
        if ($device === null) {
            return new Response($translator->trans('Device not found.', [], 'inventory'), 404, $headers);
        }
        $editParameters = $page->parameters($request, $id);
        if ($editParameters instanceof Response) {
            return $editParameters;
        }
        $form = $forms->create(DeviceTemplateType::class, ['template_id' => $device->templateId(), 'revision' => $device->revision()], ['action' => $urls->generate('inventory_device_template', $editParameters), 'templates' => $view['templates']]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            $validation->validate($form, 'template_id', 'Select a valid device template.');
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $assign($id, $data['template_id'], (string) $data['revision']);
                    return new RedirectResponse($urls->generate('inventory_device_template', $editParameters + ['saved' => 1]), 303, $headers);
                } catch (\RuntimeException|\InvalidArgumentException $error) {
                    $status = $failures->apply($form, $error);
                }
            }
        }
        return $page->render('inventory/template.html.twig', $device, $form, $request, $editParameters['list'], $status);
    }
}
