<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Platform\Contract\LegacyConfiguration;
use Kadupul\Inventory\Application\Port\DeviceTemplateDefinitions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceTemplateDefinition;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Kadupul\Inventory\Infrastructure\Symfony\DeviceTemplateFilters;
use Kadupul\Inventory\Infrastructure\Symfony\TrustedDeviceTemplatePluginHtml;
use Kadupul\Inventory\Infrastructure\Symfony\Form\DeviceTemplateDefinitionType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceTemplateDefinitionController
{
    #[Route('/inventory/device-templates', name: 'inventory_device_templates', methods: ['GET', 'HEAD'])]
    public function list(Request $request, ConsoleAccess $console, DeviceTemplateDefinitions $store, Environment $twig, TranslatorInterface $translator, LegacyConfiguration $configuration): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $store->authorize($actor->id);
            $query = $request->query->all();
            if (isset($query['reset']) && $query['reset'] !== '1') {
                throw new \InvalidArgumentException();
            }
            $defaults = $store->defaults($actor->id, isset($query['reset']));
            $filters = DeviceTemplateFilters::parse($query, $defaults);
            if (!isset($query['page']) && array_intersect_key($filters, array_flip(['q','class','graph','has_hosts','size'])) !== array_intersect_key($defaults, array_flip(['q','class','graph','has_hosts','size']))) {
                $filters['page'] = 1;
            }
            $store->remember($actor->id, $filters);
            return new Response($twig->render('inventory/device_templates.html.twig', ['filters' => $filters, 'result' => $store->list($filters), 'choices' => ['graphs' => $store->graphChoices()], 'classes' => DeviceTemplateDefinition::CLASSES, 'sizes' => DeviceTemplateFilters::SIZES, 'legacyDevices' => self::legacyDevicesUrl($configuration), 'hooks' => TrustedDeviceTemplatePluginHtml::capturedHooks($store->hooks($actor->id, 0))]), 200, ['Cache-Control' => 'private, no-store']);
        } catch (InventoryAccessDenied) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 403, ['Cache-Control' => 'private, no-store']);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device template filters.', [], 'inventory'), 400, ['Cache-Control' => 'private, no-store']);
        } catch (\RuntimeException) {
            return new Response($translator->trans('Device template outcome is unknown. Reload before retrying.', [], 'inventory'), 502, ['Cache-Control' => 'private, no-store']);
        }
    }
    private static function legacyDevicesUrl(LegacyConfiguration $configuration): string
    {
        $path = $configuration->values()['url_path'] ?? '/';
        if (!is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20?#]/', $path)) {
            throw new \RuntimeException('Invalid installation path.');
        }
        return rtrim($path, '/') . '/host.php';
    }
    #[Route('/inventory/device-templates/new', name: 'inventory_device_template_definition_create', methods: ['GET', 'HEAD', 'POST'])]
    #[Route('/inventory/device-templates/{id}/edit', name: 'inventory_device_template_definition_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(Request $request, ConsoleAccess $console, DeviceTemplateDefinitions $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, int $id = 0): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 401, ['Cache-Control' => 'private, no-store']);
        }
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $store->authorize($actor->id);
            $row = $id > 0 ? $store->find($id) : new DeviceTemplateDefinition(0, '', '');
            if ($row === null) {
                return new Response($translator->trans('Device template not found.', [], 'inventory'), 404, $headers);
            }
            $form = $forms->create(DeviceTemplateDefinitionType::class, ['name' => $row->name, 'class' => $row->class, 'revision' => $id > 0 ? $row->revision() : 'new']);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Invalid device template fields.', [], 'inventory')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $data = $form->getData();
                    DeviceTemplateDefinition::validate($data);
                    $result = $store->execute($actor->id, 'save', ['id' => $id, 'revision' => $data['revision'], 'data' => ['name' => $data['name'], 'class' => $data['class']]]);
                    return new RedirectResponse($urls->generate('inventory_device_template_definition_edit', ['id' => $result['ids'][0]]), 303, $headers);
                } catch (DeviceEditConflict $error) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'inventory')));
                }
            }
            return new Response($twig->render('inventory/device_template_edit.html.twig', ['row' => $row, 'form' => $form->createView(), 'choices' => $store->choices(), 'hooks' => TrustedDeviceTemplatePluginHtml::capturedHooks($store->hooks($actor->id, $id))]), $status, $headers);
        } catch (InventoryAccessDenied) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 403, $headers);
        } catch (\RuntimeException) {
            return new Response($translator->trans('Device template outcome is unknown. Reload before retrying.', [], 'inventory'), 502, $headers);
        }
    }
}
