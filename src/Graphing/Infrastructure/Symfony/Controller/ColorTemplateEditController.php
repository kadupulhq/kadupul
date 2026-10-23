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
use Kadupul\Graphing\Domain\ColorTemplate;
use Kadupul\Graphing\Domain\ColorTemplateFilters;
use Kadupul\Graphing\Domain\ColorTemplateItem;
use Kadupul\Graphing\Infrastructure\Symfony\Form\ColorTemplateType;
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
    public function create(Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
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
        return $this->edit($actor->id, null, null, $request, $store, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/color-templates/{id}/edit', name: 'color_template_edit', requirements: ['id' => '[1-9][0-9]{0,7}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, ColorTemplateAccess $access, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'color_template'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $actor = $access->authorize();
            $template = $store->find($id);
        } catch (ColorTemplateAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Template. Reload before retrying.', [], 'color_template'), 502, ['Cache-Control' => 'private, no-store']);
        }
        if ($template === null) {
            return new Response($translator->trans('Color Template not found.', [], 'color_template'), 404, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($actor->id, $id, $template, $request, $store, $forms, $twig, $urls, $translator);
    }

    private function edit(int $actorId, ?int $id, ?ColorTemplate $template, Request $request, ColorTemplateStore $store, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $filters = $this->filterContext($request, $store);
            $items = $id === null ? [] : $store->items($id);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color template filters.', [], 'color_template'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color Template. Reload before retrying.', [], 'color_template'), 502, $headers);
        }
        $form = $forms->create(ColorTemplateType::class, [
            'name' => $template?->name ?? '',
            'revision' => $template?->revision ?? '',
        ], [
            'action' => $urls->generate($id === null ? 'color_template_create' : 'color_template_edit', ($id === null ? [] : ['id' => $id]) + $filters),
            'csrf_token_id' => $id === null ? 'color_template_create' : 'color_template_edit',
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
                    ColorTemplate::validateName((string) $data['name']);
                    $saved = $store->save($actorId, $id, (string) $data['name'], is_string($data['revision']) ? $data['revision'] : null);
                    return new RedirectResponse($urls->generate('color_template_edit', ['id' => $saved] + $filters + ['saved' => 1]), 303, $headers);
                } catch (ColorTemplateAccessDenied $error) {
                    return $this->denied($error, $translator);
                } catch (\InvalidArgumentException $error) {
                    $form->addError(new FormError($translator->trans($error->getMessage(), [], 'color_template')));
                    if (str_contains($error->getMessage(), 'changed since')) {
                        $status = 409;
                    }
                } catch (\Throwable) {
                    $status = 502;
                    $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'color_template')));
                }
            }
        }
        return new Response($twig->render('graphing/color_template_edit.html.twig', [
            'template' => $template,
            'items' => $items,
            'order_revision' => ColorTemplateItem::orderRevision($items),
            'form' => $form->createView(),
            'saved' => ($request->query->all()['saved'] ?? null) === '1',
            'filters' => $filters,
        ]), $status, $headers);
    }

    private function filterContext(Request $request, ColorTemplateStore $store): array
    {
        $query = $request->query->all();
        unset($query['saved']);
        if ($query === []) {
            return [];
        }
        return ColorTemplateFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
    }

    private function denied(ColorTemplateAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'color_template'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}
