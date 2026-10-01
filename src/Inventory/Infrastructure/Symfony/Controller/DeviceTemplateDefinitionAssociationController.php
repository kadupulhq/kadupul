<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Inventory\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Inventory\Application\Port\DeviceTemplateDefinitions;
use Kadupul\Inventory\Application\Query\InventoryAccessDenied;
use Kadupul\Inventory\Domain\DeviceEditConflict;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceTemplateDefinitionAssociationController
{
    #[Route('/inventory/device-templates/{id}/association/{kind}/{operation}', name: 'inventory_device_template_definition_association', requirements: ['id' => '[1-9][0-9]{0,7}', 'kind' => 'graph|query', 'operation' => 'add|remove'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, DeviceTemplateDefinitions $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, int $id, string $kind, string $operation): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 401, ['Cache-Control' => 'private, no-store']);
        }
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $store->authorize($actor->id);
            $row = $store->find($id);
            if (!$row) {
                return new Response($translator->trans('Device template not found.', [], 'inventory'), 404, $headers);
            }
            $choices = $store->choices()[$kind === 'graph' ? ($operation === 'add' ? 'add_graphs' : 'graphs') : 'queries'];
            $attached = $kind === 'graph' ? $row->graphs : $row->queries;
            $choices = array_filter($choices, static fn($key): bool => in_array((int) $key, $attached, true) === ($operation === 'remove'), ARRAY_FILTER_USE_KEY);
            $selected = $request->query->all()['child'] ?? null;
            if ($selected !== null && ((!is_string($selected) && !is_int($selected)) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string) $selected) || !isset($choices[(int) $selected]))) {
                throw new \InvalidArgumentException();
            }
            $builder = $forms->createNamedBuilder('device_template_association', FormType::class, ['revision' => $row->revision(), 'child' => $selected === null ? null : (int) $selected], ['csrf_token_id' => 'inventory_device_template_definition_association', 'translation_domain' => 'inventory', 'method' => 'POST']);
            $builder->add('revision', HiddenType::class)->add('child', ChoiceType::class, ['label' => $kind === 'graph' ? 'Graph template' : 'Data query', 'choices' => array_keys($choices), 'choice_label' => static fn($value): string => (string) $choices[$value], 'choice_value' => static fn($value): string => $value === null ? '' : (string) $value]);
            $form = $builder->getForm();
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $data = $form->getData();
                    $child = $data['child'];
                    if (!isset($choices[$child])) {
                        throw new \InvalidArgumentException();
                    }
                    $store->execute($actor->id, 'association', ['id' => $id, 'kind' => $kind, 'operation' => $operation, 'child' => (int) $child, 'revision' => $data['revision']]);
                    return new RedirectResponse($urls->generate('inventory_device_template_definition_edit', ['id' => $id]), 303, $headers);
                } catch (DeviceEditConflict) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans('Device template changed. Reload before saving.', [], 'inventory')));
                } catch (\InvalidArgumentException) {
                    $form->addError(new FormError($translator->trans('Invalid device template selection.', [], 'inventory')));
                }
            }
            return new Response($twig->render('inventory/device_template_association.html.twig', ['row' => $row, 'kind' => $kind, 'operation' => $operation, 'form' => $form->createView()]), $status, $headers);
        } catch (InventoryAccessDenied) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 403, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device template selection.', [], 'inventory'), 400, $headers);
        } catch (\RuntimeException) {
            return new Response($translator->trans('Device template outcome is unknown. Reload before retrying.', [], 'inventory'), 502, $headers);
        }
    }
}
