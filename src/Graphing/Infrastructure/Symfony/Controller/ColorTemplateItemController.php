<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\Graphing\Application\Port\ColorTemplateAccess;
use Kadupul\Graphing\Application\Port\ColorTemplateStore;
use Kadupul\Graphing\Application\Query\ColorTemplateAccessDenied;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateItemDeletionType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateItemType;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateReorderType;
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

final class ColorTemplateItemController
{
    #[Route('/graphing/color-templates/{id}/items/new', name: 'color_template_item_create', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function create(int $id, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $actor = $access->authorize();
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to authorize Color Template access.', [], 'color_template'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, $id, null, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/color-templates/{id}/items/{itemId}/edit', name: 'color_template_item_edit', requirements: ['id' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, int $itemId, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $actor = $access->authorize();
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to authorize Color Template access.', [], 'color_template'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, $id, $itemId, $request, $store, $forms, $twig, $urls, $translator);
    }

    private function edit(int $actorId, int $id, ?int $itemId, Request $request, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $template = $store->find($id);
            $item = $template === null || $itemId === null ? null : $this->item($store, $id, $itemId);
            $colors = $store->colors();
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Template. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
        if ($template === null) {
            return new Response($translator->trans('Color Template not found.', [], 'color_template'), 404, $headers);
        }
        if ($itemId !== null && $item === null) {
            return new Response($translator->trans('Color item not found.', [], 'color_template'), 404, $headers);
        }
        $choices = [];
        foreach ($colors as $color) {
            $label = $color['name'] === '' ? $translator->trans('Color (%hex%)', ['%hex%' => $color['hex']], 'color_template') : $color['name'] . ' (' . $color['hex'] . ')';
            $choices[$label . ' #' . $color['id']] = $color['id'];
        }
        $form = $forms->create(ColorTemplateItemType::class, ['color_id' => $item?->colorId, 'revision' => $item?->revision ?? ''], [
            'action' => $urls->generate($itemId === null ? 'color_template_item_create' : 'color_template_item_edit', ['id' => $id] + ($itemId === null ? [] : ['itemId' => $itemId])),
            'colors' => $choices,
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'color_template')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                try {
                    $store->saveItem($actorId, $id, $itemId, (int) $data['color_id'], is_string($data['revision']) ? $data['revision'] : null);
                    return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id, 'saved' => 1]), 303, $headers);
                } catch (ColorTemplateAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $status = str_contains($error->getMessage(), 'changed since') || str_contains($error->getMessage(), 'not found') ? 409 : 422;
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'color_template')));
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'color_template')));
                }
            }
        }
        return new Response($twig->render('graphing/color_template_item_edit.html.twig', [
            'template' => $template,
            'item' => $item,
            'form' => $form->createView(),
        ]), $status, $headers);
    }

    #[Route('/graphing/color-templates/{id}/items/{itemId}/delete', name: 'color_template_item_delete', requirements: ['id' => '[1-9][0-9]{0,7}', 'itemId' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function delete(int $id, int $itemId, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $template = $store->find($id);
            $item = $template === null ? null : $this->item($store, $id, $itemId);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Template. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
        if ($template === null || $item === null) {
            return new Response($translator->trans('Color item not found.', [], 'color_template'), 404, $headers);
        }
        $form = $forms->create(ColorTemplateItemDeletionType::class, ['revision' => $item->revision], [
            'action' => $urls->generate('color_template_item_delete', ['id' => $id, 'itemId' => $itemId]),
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $store->removeItem($actor->id, $id, $itemId, (string) $form->get('revision')->getData());
                return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id, 'saved' => 1]), 303, $headers);
            } catch (ColorTemplateAccessDenied $error) {
                return $this->denied($error, $translator);
            } catch (\InvalidArgumentException $error) {
                $status = 409;
                $form->addError(new FormError($translator->trans($error->getMessage(), [], 'color_template')));
            } catch (\Throwable) {
                $status = 502;
                $form->addError(new FormError($translator->trans('Delete outcome is uncertain. Reload before retrying.', [], 'color_template')));
            }
        }
        return new Response($twig->render('graphing/color_template_item_delete.html.twig', [
            'template' => $template,
            'item' => $item,
            'form' => $form->createView(),
        ]), $status, $headers);
    }

    #[Route('/graphing/color-templates/{id}/items/order', name: 'color_template_item_order', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['POST'])]
    public function reorder(int $id, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, $headers);
        }
        try {
            $actor = $access->authorize();
            $form = $forms->create(ColorTemplateReorderType::class, null, [
                'action' => $urls->generate('color_template_item_order', ['id' => $id]),
            ]);
            $form->handleRequest($request);
            if (!$form->isSubmitted() || !$form->isValid() || $form->getExtraData() !== []) {
                return new Response($translator->trans('Invalid color item order.', [], 'color_template'), 422, $headers);
            }
            $data = $form->getData();
            // Decode without associative conversion so a JSON object with numeric
            // keys cannot pass as the list the form emits.
            $ids = json_decode((string) $data['items'], false, 4);
            if (!is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn(mixed $value): bool => !is_int($value) || $value < 1) !== []
                || (($data['moveUp'] ?? null) !== null && ($data['moveDown'] ?? null) !== null)) {
                return new Response($translator->trans('Invalid color item order.', [], 'color_template'), 400, $headers);
            }
            foreach (['moveUp' => -1, 'moveDown' => 1] as $field => $offset) {
                $moved = $data[$field] ?? null;
                if ($moved === null) {
                    continue;
                }
                $position = preg_match('/\A[1-9][0-9]{0,9}\z/D', (string) $moved) === 1 ? array_search((int) $moved, $ids, true) : false;
                if (!is_int($position) || !isset($ids[$position + $offset])) {
                    return new Response($translator->trans('Invalid color item order.', [], 'color_template'), 400, $headers);
                }
                [$ids[$position], $ids[$position + $offset]] = [$ids[$position + $offset], $ids[$position]];
            }
            $store->reorder($actor->id, $id, $ids, (string) $data['revision']);
            return new RedirectResponse($urls->generate('color_template_edit', ['id' => $id]), 303, $headers);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\InvalidArgumentException $error) {
            return new Response($translator->trans($error->getMessage(), [], 'color_template'), 409, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Reorder outcome is uncertain. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
    }

    private function item(ColorTemplateStore $store, int $id, int $itemId): ?ColorTemplateItem
    {
        return array_find($store->items($id), static fn(ColorTemplateItem $item): bool => $item->id === $itemId);
    }

    private function denied(ColorTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}
