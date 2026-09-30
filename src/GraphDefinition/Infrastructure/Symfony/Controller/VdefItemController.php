<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\VdefEditor;
use Kadupul\GraphDefinition\Application\Port\VdefCatalog;
use Kadupul\GraphDefinition\Application\Query\VdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\VdefAuthorization;
use Kadupul\GraphDefinition\Domain\VdefFunctions;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\VdefItemType as VdefItemForm;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\VdefReorderType;
use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class VdefItemController
{
    #[Route('/graph-definitions/vdefs/{vdefId<\d+>}/items/{itemId<\d+>}', name: 'graph_vdef_item_edit', requirements: ['vdefId' => '[1-9][0-9]{0,7}', 'itemId' => '0|[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(int $vdefId, int $itemId, Request $request, VdefAuthorization $authorization, VdefCatalog $catalog, VdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $vdef = $catalog->find($vdefId);
            if ($vdef === null) {
                return new Response($translator->trans('VDEF not found.', [], 'graph_definition'), 404, $headers);
            }
            $item = ['id' => 0, 'vdef_id' => $vdefId, 'type' => 1, 'value' => ''];
            if ($itemId > 0) {
                foreach ($vdef['items'] as $candidate) {
                    if ($candidate['id'] === $itemId) {
                        $item = ['id' => $itemId, 'vdef_id' => $vdefId, 'type' => $candidate['type'], 'value' => $candidate['value']];
                        break;
                    }
                }
                if ($item['id'] === 0) {
                    return new Response($translator->trans('VDEF item not found.', [], 'graph_definition'), 404, $headers);
                }
            }
            $rawSubmit = $request->request->all();
            $submittedItem = $rawSubmit['vdef_item'] ?? null;
            if ($request->isMethod('POST') && !is_array($submittedItem)) {
                return new Response($translator->trans('Invalid VDEF item type.', [], 'graph_definition'), 400, $headers);
            }
            $submittedType = $request->isMethod('POST')
                ? ($submittedItem['type'] ?? null)
                : $request->query->get('type', $item['type']);
            if ((!is_string($submittedType) && !is_int($submittedType))
                || ($request->isMethod('POST') && !in_array((string) $submittedType, ['1', '4', '6'], true))) {
                return new Response($translator->trans('Invalid VDEF item type.', [], 'graph_definition'), 400, $headers);
            }
            $itemType = in_array((string) $submittedType, ['1', '4', '6'], true) ? (string) $submittedType : '1';
            $item['revision'] = $vdef['revision'];
            if (!$request->isMethod('POST') && (int) $itemType !== $item['type']) {
                $item['type'] = (int) $itemType;
                $item['value'] = match ($itemType) {
                    '1' => '1',
                    '4' => 'CURRENT_DATA_SOURCE',
                    default => '',
                };
            }
            $form = $forms->create(VdefItemForm::class, $item, [
                'item_type' => $itemType,
                'action' => $urls->generate('graph_vdef_item_edit', ['vdefId' => $vdefId, 'itemId' => $itemId]),
            ]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'graph_definition')));
                }
                if ($form->isValid()) {
                    $data = $form->getData();
                    if ((int) $data['id'] !== $itemId || (int) $data['vdef_id'] !== $vdefId) {
                        $form->addError(new FormError($translator->trans('The VDEF item selection changed. Reload the form.', [], 'graph_definition')));
                    } else {
                        try {
                            $editor->saveItem($actor, $vdefId, $itemId, (int) $data['type'], (string) $data['value'], (string) $data['revision']);
                            return new RedirectResponse($urls->generate('graph_vdef_edit', ['id' => $vdefId]), 303, $headers);
                        } catch (\InvalidArgumentException $error) {
                            $form->addError(new FormError($translator->trans($error->getMessage(), [], 'graph_definition')));
                            $status = 409;
                        }
                    }
                }
            }
            return new Response($twig->render('graph_definition/vdef_item_edit.html.twig', [
                'form' => $form->createView(), 'vdef' => $vdef,
                'preview' => $catalog->preview($vdefId),
                'item_type' => $itemType,
            ]), $status, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('VDEF item save failed. Check the VDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    #[Route('/graph-definitions/vdefs/{vdefId<\d+>}/items/{itemId<\d+>}/delete', name: 'graph_vdef_item_delete', requirements: ['vdefId' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $vdefId, int $itemId, Request $request, VdefAuthorization $authorization, VdefCatalog $catalog, VdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $vdef = $catalog->find($vdefId);
            $item = $vdef === null ? null : array_find($vdef['items'], static fn(array $row): bool => $row['id'] === $itemId);
            if ($vdef === null || $item === null) {
                return new Response($translator->trans('VDEF item not found.', [], 'graph_definition'), 404, $headers);
            }
            $formBuilder = $forms->createNamedBuilder('confirm', \Symfony\Component\Form\Extension\Core\Type\FormType::class, ['revision' => $vdef['revision']], [
                'action' => $urls->generate('graph_vdef_item_delete', ['vdefId' => $vdefId, 'itemId' => $itemId]),
                'method' => 'POST', 'csrf_protection' => true, 'csrf_token_id' => 'graph_vdef_edit',
            ]);
            $formBuilder->add('revision', \Symfony\Component\Form\Extension\Core\Type\HiddenType::class);
            $form = $formBuilder->getForm();
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $editor->deleteItem($actor, $vdefId, $itemId, (string) $form->getData()['revision']);
                return new RedirectResponse($urls->generate('graph_vdef_edit', ['id' => $vdefId]), 303, $headers);
            }
            return new Response($twig->render('graph_definition/vdef_item_delete.html.twig', [
                'form' => $form->createView(), 'vdef' => $vdef, 'item' => $item,
                'item_type' => VdefFunctions::TYPES[(string) $item['type']] ?? 'VDEF item',
            ]), $request->isMethod('POST') ? 422 : 200, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('VDEF item removal failed. Check the VDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    #[Route('/graph-definitions/vdefs/{vdefId<\d+>}/items/reorder', name: 'graph_vdef_item_reorder', requirements: ['vdefId' => '[1-9][0-9]{0,7}'], methods: ['POST'])]
    public function reorder(int $vdefId, Request $request, VdefAuthorization $authorization, VdefEditor $editor, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator, ConsoleAccess $consoleAccess): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $consoleAccess->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), 401, $headers);
        }
        try {
            $actor = $authorization->actor();
            $form = $forms->createNamed('order', VdefReorderType::class, ['items' => '', 'revision' => ''], [
                'action' => $urls->generate('graph_vdef_item_reorder', ['vdefId' => $vdefId]),
                'method' => 'POST',
            ]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return new Response($translator->trans('Invalid VDEF item order.', [], 'graph_definition'), 422, $headers);
            }
            $order = $request->request->all('order');
            $raw = $order['items'] ?? null;
            $ids = is_string($raw) ? json_decode($raw, true, 8) : null;
            if (!is_array($ids) || array_filter($ids, static fn(mixed $id): bool => !is_int($id) || $id < 1) !== []) {
                return new Response($translator->trans('Invalid VDEF item order.', [], 'graph_definition'), 400, $headers);
            }
            foreach (['moveUp' => -1, 'moveDown' => 1] as $field => $offset) {
                if (isset($order[$field])) {
                    $position = array_search((int) $order[$field], $ids, true);
                    $destination = is_int($position) ? $position + $offset : -1;
                    if (!is_int($position) || !isset($ids[$destination])) {
                        return new Response($translator->trans('Invalid VDEF item order.', [], 'graph_definition'), 409, $headers);
                    }
                    [$ids[$position], $ids[$destination]] = [$ids[$destination], $ids[$position]];
                }
            }
            $editor->reorder($actor, $vdefId, $ids, (string) $form->getData()['revision']);
            return new RedirectResponse($urls->generate('graph_vdef_edit', ['id' => $vdefId]), 303, $headers);
        } catch (VdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('VDEF item reorder failed. Check the VDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }
}
