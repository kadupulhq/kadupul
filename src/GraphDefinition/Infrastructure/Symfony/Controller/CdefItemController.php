<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-2.0-or-later
 */

namespace Kadupul\GraphDefinition\Infrastructure\Symfony\Controller;

use Kadupul\GraphDefinition\Application\Port\CdefCatalog;
use Kadupul\GraphDefinition\Application\Port\CdefEditor;
use Kadupul\GraphDefinition\Application\Query\CdefAccessDenied;
use Kadupul\GraphDefinition\Application\Query\CdefAuthorization;
use Kadupul\GraphDefinition\Domain\CdefFunctions;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefItemType as CdefItemForm;
use Kadupul\GraphDefinition\Infrastructure\Symfony\Form\CdefReorderType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CdefItemController
{
    #[Route('/graph-definitions/cdefs/{cdefId<\d+>}/items/{itemId<\d+>}', name: 'graph_cdef_item_edit', requirements: ['cdefId' => '[1-9][0-9]{0,7}', 'itemId' => '0|[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function edit(int $cdefId, int $itemId, Request $request, CdefAuthorization $authorization, CdefCatalog $catalog, CdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $actor = $authorization->actor();
            $cdef = $catalog->find($cdefId);
            if ($cdef === null) {
                return new Response($translator->trans('CDEF not found.', [], 'graph_definition'), 404, $headers);
            }
            $references = array_values(array_filter($catalog->references(), static fn(array $row): bool => $row['id'] !== $cdefId));
            $item = ['id' => 0, 'cdef_id' => $cdefId, 'type' => 1, 'value' => '1'];
            if ($itemId > 0) {
                foreach ($cdef['items'] as $candidate) {
                    if ($candidate['id'] === $itemId) {
                        $item = ['id' => $itemId, 'cdef_id' => $cdefId, 'type' => $candidate['type'], 'value' => $candidate['value']];
                        break;
                    }
                }
                if ($item['id'] === 0) {
                    return new Response($translator->trans('CDEF item not found.', [], 'graph_definition'), 404, $headers);
                }
            }
            $queryType = $request->query->all()['type'] ?? null;
            $postData = $request->request->all()['cdef_item'] ?? [];
            $candidateType = $request->isMethod('POST') && is_array($postData) ? ($postData['type'] ?? $item['type']) : ($queryType ?? $item['type']);
            $itemType = is_scalar($candidateType) && isset(CdefFunctions::TYPES[(int) $candidateType]) ? (string) (int) $candidateType : (string) $item['type'];
            if (!$request->isMethod('POST') && (int) $itemType !== $item['type']) {
                $item['type'] = (int) $itemType;
                $item['value'] = match ($itemType) {
                    '1' => '1', '2' => '1', '4' => 'CURRENT_DATA_SOURCE',
                    '5' => (string) ($references[0]['id'] ?? ''), default => '',
                };
            }
            $form = $forms->create(CdefItemForm::class, $item, [
                'item_type' => $itemType,
                'cdef_choices' => $references,
                'functions' => $catalog->functions(),
                'action' => $urls->generate('graph_cdef_item_edit', ['cdefId' => $cdefId, 'itemId' => $itemId]),
            ]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'graph_definition')));
                }
                if ($form->isValid()) {
                    $data = $form->getData();
                    if ((int) $data['id'] !== $itemId || (int) $data['cdef_id'] !== $cdefId) {
                        $form->addError(new FormError($translator->trans('The CDEF item selection changed. Reload the form.', [], 'graph_definition')));
                    } else {
                        try {
                            $editor->saveItem($actor, $cdefId, $itemId, (int) $data['type'], (string) $data['value']);
                            return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, $headers);
                        } catch (\InvalidArgumentException $error) {
                            $form->addError(new FormError($translator->trans($error->getMessage(), [], 'graph_definition')));
                        }
                    }
                }
            }
            return new Response($twig->render('graph_definition/cdef_item_edit.html.twig', [
                'form' => $form->createView(), 'cdef' => $cdef, 'item' => $item,
                'preview' => $catalog->preview($cdefId), 'itemType' => $itemType, 'types' => CdefFunctions::TYPES,
            ]), $status, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF item save failed. Check the CDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    #[Route('/graph-definitions/cdefs/{cdefId<\d+>}/items/{itemId<\d+>}/delete', name: 'graph_cdef_item_delete', requirements: ['cdefId' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $cdefId, int $itemId, Request $request, CdefAuthorization $authorization, CdefCatalog $catalog, CdefEditor $editor, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $actor = $authorization->actor();
            $cdef = $catalog->find($cdefId);
            $item = $cdef === null ? null : array_find($cdef['items'], static fn(array $row): bool => $row['id'] === $itemId);
            if ($cdef === null || $item === null) {
                return new Response($translator->trans('CDEF item not found.', [], 'graph_definition'), 404, $headers);
            }
            $form = $forms->createNamed('confirm', \Symfony\Component\Form\Extension\Core\Type\FormType::class, [], [
                'action' => $urls->generate('graph_cdef_item_delete', ['cdefId' => $cdefId, 'itemId' => $itemId]),
                'method' => 'POST', 'csrf_protection' => true, 'csrf_token_id' => 'graph_cdef_edit',
            ]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $editor->deleteItem($actor, $cdefId, $itemId);
                return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, $headers);
            }
            return new Response($twig->render('graph_definition/cdef_item_delete.html.twig', [
                'form' => $form->createView(), 'cdef' => $cdef, 'item' => $item,
            ]), $request->isMethod('POST') ? 422 : 200, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF item removal failed. Check the CDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }

    #[Route('/graph-definitions/cdefs/{cdefId<\d+>}/items/reorder', name: 'graph_cdef_item_reorder', requirements: ['cdefId' => '[1-9][0-9]{0,7}'], methods: ['POST'])]
    public function reorder(int $cdefId, Request $request, CdefAuthorization $authorization, CdefEditor $editor, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $actor = $authorization->actor();
            $form = $forms->createNamed('order', CdefReorderType::class, ['items' => ''], [
                'action' => $urls->generate('graph_cdef_item_reorder', ['cdefId' => $cdefId]), 'method' => 'POST',
            ]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid()) {
                return new Response($translator->trans('Invalid CDEF item order.', [], 'graph_definition'), 422, $headers);
            }
            $order = $request->request->all('order');
            $raw = $order['items'] ?? null;
            $ids = is_string($raw) ? json_decode($raw, true, 8) : null;
            if (!is_array($ids) || array_is_list($ids) === false || array_filter($ids, static fn(mixed $itemId): bool => !is_int($itemId) || $itemId < 1) !== []) {
                return new Response($translator->trans('Invalid CDEF item order.', [], 'graph_definition'), 400, $headers);
            }
            $expectedIds = $ids;
            if (isset($order['moveUp'], $order['moveDown'])) {
                return new Response($translator->trans('Invalid CDEF item order.', [], 'graph_definition'), 400, $headers);
            }
            foreach (['moveUp' => -1, 'moveDown' => 1] as $field => $offset) {
                if (isset($order[$field])) {
                    $moving = filter_var($order[$field], FILTER_VALIDATE_INT);
                    $position = $moving === false ? false : array_search($moving, $ids, true);
                    $destination = is_int($position) ? $position + $offset : -1;
                    if (!is_int($position) || !isset($ids[$destination])) {
                        return new Response($translator->trans('Invalid CDEF item order.', [], 'graph_definition'), 409, $headers);
                    }
                    [$ids[$position], $ids[$destination]] = [$ids[$destination], $ids[$position]];
                }
            }
            $editor->reorder($actor, $cdefId, $ids, $expectedIds);
            return new RedirectResponse($urls->generate('graph_cdef_edit', ['id' => $cdefId]), 303, $headers);
        } catch (CdefAccessDenied $error) {
            return new Response($translator->trans('Access denied.', [], 'graph_definition'), $error->unauthenticated ? 401 : 403, $headers);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'graph_definition'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('CDEF item reorder failed. Check the CDEF before retrying.', [], 'graph_definition'), 502, $headers);
        }
    }
}
