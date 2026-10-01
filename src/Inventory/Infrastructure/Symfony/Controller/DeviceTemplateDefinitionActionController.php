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
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DeviceTemplateDefinitionActionController
{
    #[Route('/inventory/device-templates/action/{operation}', name: 'inventory_device_template_definition_action', requirements: ['operation' => 'delete|duplicate|sync'], methods: ['GET', 'HEAD', 'POST'])]
    public function __invoke(Request $request, ConsoleAccess $console, DeviceTemplateDefinitions $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, string $operation): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 401, ['Cache-Control' => 'private, no-store']);
        }
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $store->authorize($actor->id);
            $revisions = [];
            $rows = [];
            if (!$request->isMethod('POST')) {
                $ids = $request->query->all()['ids'] ?? [];
                if (!is_array($ids) || !$ids || count($ids) > 100) {
                    throw new \InvalidArgumentException();
                }
                foreach ($ids as $id) {
                    if ((!is_int($id) && !is_string($id)) || !preg_match('/^[1-9][0-9]{0,7}$/D', (string) $id) || isset($revisions[(int) $id])) {
                        throw new \InvalidArgumentException();
                    }
                    $row = $store->find((int) $id);
                    if (!$row) {
                        throw new DeviceEditConflict();
                    }
                    $revisions[$row->id] = $row->revision();
                    $rows[] = $row;
                }
            }
            $builder = $forms->createNamedBuilder('device_template_action', FormType::class, ['selection' => json_encode($revisions, JSON_THROW_ON_ERROR), 'title_format' => '<template_title> (1)', 'operation_id' => bin2hex(random_bytes(16))], ['csrf_token_id' => 'inventory_device_template_definition_action', 'translation_domain' => 'inventory', 'method' => 'POST']);
            $builder->add('selection', HiddenType::class)->add('operation_id', HiddenType::class);
            if ($operation === 'duplicate') {
                $builder->add('title_format', TextType::class, ['label' => 'Title format', 'trim' => false, 'attr' => ['maxlength' => 255]]);
            }
            $form = $builder->getForm();
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Invalid device template selection.', [], 'inventory')));
            }
            $status = $request->isMethod('POST') ? 422 : 200;
            $partial = false;
            if ($form->isSubmitted() && $form->isValid()) {
                try {
                    $data = $form->getData();
                    $selected = json_decode($data['selection'], true, 8, JSON_THROW_ON_ERROR);
                    if (!is_array($selected) || !$selected || count($selected) > 100) {
                        throw new \InvalidArgumentException();
                    }
                    foreach ($selected as $id => $revision) {
                        if (!preg_match('/^[1-9][0-9]{0,7}$/D', (string) $id) || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) {
                            throw new \InvalidArgumentException();
                        }
                    }
                    $result = $store->execute($actor->id, $operation, ['revisions' => $selected, 'title_format' => $data['title_format'] ?? '', 'operation_id' => $data['operation_id']]);
                    if (($result['status'] ?? '') === 'partial') {
                        $status = 502;
                        $partial = true;
                    } else {
                        return new RedirectResponse($urls->generate('inventory_device_templates'), 303, $headers);
                    }
                } catch (DeviceEditConflict) {
                    $status = 409;
                    $form->addError(new FormError($translator->trans('Device template changed. Reload before saving.', [], 'inventory')));
                } catch (\JsonException|\InvalidArgumentException) {
                    $form->addError(new FormError($translator->trans('Invalid device template selection.', [], 'inventory')));
                }
            }
            return new Response($twig->render('inventory/device_template_action.html.twig', ['operation' => $operation, 'rows' => $rows, 'form' => $form->createView(), 'partial' => $partial]), $status, $headers);
        } catch (InventoryAccessDenied) {
            return new Response($translator->trans('Access denied.', [], 'inventory'), 403, $headers);
        } catch (DeviceEditConflict) {
            return new Response($translator->trans('Device template changed. Reload before saving.', [], 'inventory'), 409, $headers);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid device template selection.', [], 'inventory'), 400, $headers);
        } catch (\RuntimeException) {
            return new Response($translator->trans('Device template outcome is unknown. Reload before retrying.', [], 'inventory'), 502, $headers);
        }
    }
}
