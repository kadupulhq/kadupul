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
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceTemplateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceTemplateController
{
    #[Route('/inventory/devices/{id}/template', name: 'inventory_device_template', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(int $id, Request $request, PrepareDeviceTemplateAssignment $prepare, AssignDeviceTemplate $assign, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, DeviceFormFailure $failures): Response
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
        $query = $request->query->all();
        try {
            $filters = DeviceListParameters::context($query);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device list filters.', [], 'inventory'), 400, $headers);
        }
        $editParameters = ['id' => $id, 'list' => $filters];
        $form = $forms->create(DeviceTemplateType::class, ['template_id' => $device->templateId(), 'revision' => $device->revision()], ['action' => $urls->generate('inventory_device_template', $editParameters), 'templates' => $view['templates']]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
            if ($form->get('template_id')->isSynchronized() && !is_int($form->get('template_id')->getData())) {
                $form->get('template_id')->addError(new FormError($translator->trans('Select a valid device template.', [], 'inventory')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $assign($id, $data['template_id'], (string) $data['revision']);
                    return new RedirectResponse($urls->generate('inventory_device_template', $editParameters + ['saved' => 1]), 303, $headers);
                } catch (\RuntimeException|\InvalidArgumentException $error) {
                    $failure = $failures->apply($form, $error);
                    if ($failure instanceof Response) {
                        return $failure;
                    }
                    $status = $failure;
                }
            }
        }
        return new Response($twig->render('inventory/template.html.twig', ['device' => $device, 'form' => $form->createView(), 'saved' => ($query['saved'] ?? null) === '1', 'filters' => $filters]), $status, $headers);
    }
}
