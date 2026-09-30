<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Controller;

use Kadupul\ColorTemplates\Application\Command\RemoveColorTemplateItem;
use Kadupul\ColorTemplates\Application\Command\ReorderColorTemplateItems;
use Kadupul\ColorTemplates\Application\Command\SaveColorTemplateItem;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateDeleteType;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateItemType;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateOrderType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ColorTemplateItemController
{
    #[Route('/graphing/color-templates/{id}/items/new', name: 'color_template_item_create', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function create(int $id, Request $request, ColorTemplateAccess $access, ColorTemplateStore $store, SaveColorTemplateItem $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        return $this->edit($id, null, $request, $access, $store, $save, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/color-templates/{id}/items/{itemId}/edit', name: 'color_template_item_edit', requirements: ['id' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, int $itemId, Request $request, ColorTemplateAccess $access, ColorTemplateStore $store, SaveColorTemplateItem $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        return $this->edit($id, $itemId, $request, $access, $store, $save, $forms, $twig, $urls, $translator);
    }

    private function edit(int $id, ?int $itemId, Request $request, ColorTemplateAccess $access, ColorTemplateStore $store, SaveColorTemplateItem $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
            $template = $store->find($id);
            if (!$template) {
                return new Response($translator->trans('Color template not found.', [], 'color_templates'), 404, $headers);
            }
            $item = $itemId === null ? null : current(array_filter($store->items($id), static fn($entry): bool => $entry->id === $itemId));
            if ($itemId !== null && !$item) {
                return new Response($translator->trans('Color template item not found.', [], 'color_templates'), 404, $headers);
            }
            $choices = [];
            foreach ($store->colors() as $color) {
                $choices[$color['name'] . ' (#' . $color['hex'] . ') [' . $color['id'] . ']'] = (string) $color['id'];
            }
            if ($choices === []) {
                return new Response($translator->trans('No colors are available.', [], 'color_templates'), 409, $headers);
            }
            $form = $forms->create(ColorTemplateItemType::class, ['color_id' => $item ? (string) $item->colorId : null, 'revision' => $item?->revision ?? ''], [
                'action' => $urls->generate($itemId === null ? 'color_template_item_create' : 'color_template_item_edit', ['id' => $id] + ($itemId === null ? [] : ['itemId' => $itemId])),
                'color_choices' => $choices,
            ]);
            $form->handleRequest($request);
            $status = 200;
            if ($form->isSubmitted()) {
                if ($form->getExtraData() !== []) {
                    $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'color_templates')));
                }
                if ($form->isValid()) {
                    $values = $form->getData();
                    $saved = $save($id, $itemId, (int) $values['color_id'], is_string($values['revision']) ? $values['revision'] : null);
                    return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id, 'saved' => 1, 'item' => $saved]), 303, $headers);
                }
                $status = 422;
            }
            return new Response($twig->render('color_templates/item_edit.html.twig', ['template' => $template, 'item' => $item, 'form' => $form->createView()]), $status, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'color_templates'), 409, $headers);
        } catch (\Throwable $error) {
            error_log('Color item form failed: ' . $error::class . ' in ' . basename($error->getFile()) . ':' . $error->getLine());
            return new Response($translator->trans('Unable to load color item form.', [], 'color_templates'), 502, $headers);
        }
    }

    #[Route('/graphing/color-templates/{id}/items/{itemId}/delete', name: 'color_template_item_delete', requirements: ['id' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $id, int $itemId, Request $request, ColorTemplateAccess $access, ColorTemplateStore $store, RemoveColorTemplateItem $remove, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
            $template = $store->find($id);
            $item = current(array_filter($store->items($id), static fn($entry): bool => $entry->id === $itemId));
            if (!$template || !$item) {
                return new Response($translator->trans('Color template item not found.', [], 'color_templates'), 404, $headers);
            }
            $form = $forms->create(ColorTemplateDeleteType::class, ['revision' => $item->revision], ['action' => $urls->generate('color_template_item_delete', ['id' => $id, 'itemId' => $itemId])]);
            $form->handleRequest($request);
            $status = $request->isMethod('POST') ? 422 : 200;
            if ($form->isSubmitted() && $form->isValid()) {
                $remove($id, $itemId, (string) $form->get('revision')->getData());
                return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id, 'saved' => 1]), 303, $headers);
            }
            return new Response($twig->render('color_templates/item_delete.html.twig', ['template' => $template, 'item' => $item, 'form' => $form->createView()]), $status, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'color_templates'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare color item removal.', [], 'color_templates'), 502, $headers);
        }
    }

    #[Route('/graphing/color-templates/{id}/items/order', name: 'color_template_item_order', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['POST'])]
    public function reorder(int $id, Request $request, ColorTemplateAccess $access, ReorderColorTemplateItems $reorder, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $access->authorize();
            $submittedForms = array_values(array_filter($request->request->keys(), static fn(string $name): bool => preg_match('/\Acolor_template_order_[1-9][0-9]{0,7}_[1-9][0-9]{0,9}_(?:up|down)\z/D', $name) === 1));
            if (count($submittedForms) !== 1 || !str_starts_with($submittedForms[0], 'color_template_order_' . $id . '_')) {
                return new Response($translator->trans('Invalid color template order.', [], 'color_templates'), 422, $headers);
            }
            $form = $forms->createNamed($submittedForms[0], ColorTemplateOrderType::class, [], ['action' => $urls->generate('color_template_item_order', ['id' => $id])]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid() || $form->getExtraData() !== []) {
                return new Response($translator->trans('Invalid color template order.', [], 'color_templates'), 422, $headers);
            }
            $raw = json_decode((string) $form->get('order')->getData(), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($raw) || !array_is_list($raw) || array_filter($raw, static fn($item): bool => !is_int($item)) !== []) {
                throw new \InvalidArgumentException('Invalid color template order.');
            }
            $revision = $form->get('revision')->getData();
            $reorder($id, $raw, is_string($revision) ? $revision : null);
            return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id]), 303, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'color_templates'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to reorder color items.', [], 'color_templates'), 502, $headers);
        }
    }

    private function denied(ColorTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}
