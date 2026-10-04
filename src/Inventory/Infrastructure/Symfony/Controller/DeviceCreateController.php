<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Command\CreateDevice;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Application\Query\PrepareDeviceCreation;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceListParameters;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceCreateType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DeviceCreateController
{
    #[Route('/inventory/devices/new', name: 'inventory_device_create', methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $access, CreateDevice $create, PrepareDeviceCreation $prepare, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $access->consoleActor();
        if ($actor === null || !$access->canManageDevices($actor)) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $actor === null ? 401 : 403, $headers);
        }
        try {
            $filters = DeviceListParameters::context($request->query->all());
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device list filters.', [], 'inventory'), 400, $headers);
        }
        try {
            $choices = $prepare();
        } catch (InventoryAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
        }
        $defaults = $choices->defaults;
        if (!$request->isMethod('POST') && $request->query->has('host_template_id')) {
            try {
                $value = $request->query->all()['host_template_id'];
                $template = $value === '0' ? 0 : \Kadupul\Inventory\Domain\DeviceSelection::validateIds([$value])[0];
                if ($template !== 0 && !array_key_exists($template, $choices->templates)) {
                    throw new \InvalidArgumentException();
                }
                $defaults['host_template_id'] = $template;
            } catch (\InvalidArgumentException) {
                return new Response($translator->trans('Invalid device list filters.', [], 'inventory'), 400, $headers);
            }
        }
        $form = $forms->create(DeviceCreateType::class, $defaults, ['action' => $urls->generate('inventory_device_create', ['list' => $filters]), 'host_template_id' => [0 => $translator->trans('None', [], 'inventory')] + $choices->templates, 'site_id' => [0 => $translator->trans('Unassigned', [], 'inventory')] + $choices->sites, 'poller_id' => $choices->pollers]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'inventory')));
            }
            if ($form->isValid()) {
                try {
                    $data = $form->getData();
                    $create($data);

                    return new RedirectResponse($urls->generate('inventory_devices', $filters + ['created' => 1]), 303, $headers);
                } catch (InventoryAccessDenied $error) {
                    return new Response($translator->trans('Access denied.', [], 'inventory'), $error->unauthenticated ? 401 : 403, $headers);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\RuntimeException) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Device creation outcome is uncertain. Check the device list before retrying.', [], 'inventory')));
                }
            }
        }

        return new Response($twig->render('inventory/device_create.html.twig', ['form' => $form->createView(), 'filters' => $filters]), $status, $headers);
    }
}
