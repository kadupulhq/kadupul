<?php

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors.
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\ColorTemplates\Infrastructure\Symfony\Controller;

use Kadupul\ColorTemplates\Application\Command\SaveColorTemplate;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateAccess;
use Kadupul\ColorTemplates\Application\Port\ColorTemplateStore;
use Kadupul\ColorTemplates\Application\Query\ColorTemplateAccessDenied;
use Kadupul\ColorTemplates\Domain\ColorTemplate;
use Kadupul\ColorTemplates\Domain\ColorTemplateFilters;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateType;
use Kadupul\ColorTemplates\Infrastructure\Symfony\Form\ColorTemplateOrderType;
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

final class ColorTemplateEditController
{
    #[Route('/graphing/color-templates/new', name: 'color_template_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, SaveColorTemplate $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load color template form.', [], 'color_templates'), 502);
        }
        return $this->edit(null, null, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/color-templates/{id}/edit', name: 'color_template_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, SaveColorTemplate $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_templates'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
            $template = $store->find($id);
            if (!$template) {
                return new Response($translator->trans('Color template not found.', [], 'color_templates'), 404, ['Cache-Control' => 'private, no-store']);
            }
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load color template form.', [], 'color_templates'), 502);
        }
        return $this->edit($id, $template, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    private function edit(?int $id, ?ColorTemplate $template, Request $request, ColorTemplateStore $store, SaveColorTemplate $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $filters = $this->filters($request, $store, $translator);
        if ($filters instanceof Response) {
            return $filters;
        }
        $data = ['name' => $template?->name ?? '', 'revision' => $template?->revision ?? ''];
        $form = $forms->create(ColorTemplateType::class, $data, ['action' => $urls->generate($id === null ? 'color_template_create' : 'color_template_edit', ($id === null ? [] : ['id' => $id]) + $filters)]);
        $form->handleRequest($request);
        $status = 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'color_templates')));
            }
            if ($form->isValid()) {
                $values = $form->getData();
                try {
                    $saved = $save($id, (string) $values['name'], is_string($values['revision']) ? $values['revision'] : null);
                    return new RedirectResponse($urls->generate('color_template_edit', ['id' => $saved] + $filters + ['saved' => 1]), 303, $headers);
                } catch (ColorTemplateAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'color_templates')));
                    $status = $error->getMessage() === 'Color template not found.' ? 404 : 409;
                } catch (\Throwable) {
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'color_templates')));
                    $status = 502;
                }
            } else {
                $status = 422;
            }
        }
        $items = $template ? $store->items($id) : [];
        $orderForms = [];
        if ($template) {
            $orderedIds = array_map(static fn($item): int => $item->id, $items);
            $orderRevision = hash('sha256', json_encode($orderedIds, JSON_THROW_ON_ERROR));
            foreach ($items as $index => $item) {
                foreach (['up' => -1, 'down' => 1] as $direction => $delta) {
                    $target = $index + $delta;
                    if (!isset($orderedIds[$target])) {
                        continue;
                    }
                    $changed = $orderedIds;
                    [$changed[$index], $changed[$target]] = [$changed[$target], $changed[$index]];
                    $instanceId = 'color_template_order_' . $id . '_' . $item->id . '_' . $direction;
                    $orderForm = $forms->createNamed($instanceId, ColorTemplateOrderType::class, ['order' => json_encode($changed, JSON_THROW_ON_ERROR), 'revision' => $orderRevision], [
                        'action' => $urls->generate('color_template_item_order', ['id' => $id]),
                        'button_label' => $direction === 'up' ? 'Move up' : 'Move down',
                        'attr' => ['id' => $instanceId],
                    ]);
                    $orderForms[$item->id][$direction] = $orderForm->createView();
                }
            }
        }
        return new Response($twig->render('color_templates/edit.html.twig', [
            'template' => $template, 'items' => $items, 'orderForms' => $orderForms, 'form' => $form->createView(),
            'filters' => $filters, 'saved' => $request->query->get('saved') === '1', 'status' => $status,
        ]), $status, $headers);
    }

    private function filters(Request $request, ColorTemplateStore $store, TranslatorInterface $translator): array|Response
    {
        try {
            $query = $request->query->all();
            unset($query['saved'], $query['item']);
            return $query === [] ? [] : ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
        } catch (\Throwable) {
            return new Response($translator->trans('Invalid color template filters.', [], 'color_templates'), 400, ['Cache-Control' => 'private, no-store']);
        }
    }

    private function denied(ColorTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'color_templates'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}
