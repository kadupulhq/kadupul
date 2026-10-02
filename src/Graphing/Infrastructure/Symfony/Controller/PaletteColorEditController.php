<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2026 The Kadupul project and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Kadupul\Graphing\Infrastructure\Symfony\Controller;

use Kadupul\IdentityAccess\Contract\ConsoleAccess;
use Kadupul\Graphing\Application\Command\SavePaletteColor;
use Kadupul\Graphing\Application\Port\PaletteColorAccess;
use Kadupul\Graphing\Application\Port\PaletteColorStore;
use Kadupul\Graphing\Application\Query\FindPaletteColor;
use Kadupul\Graphing\Application\Query\PaletteColorAccessDenied;
use Kadupul\Graphing\Domain\PaletteColor;
use Kadupul\Graphing\Domain\PaletteColorFilters;
use Kadupul\Graphing\Infrastructure\Symfony\Form\PaletteColorType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PaletteColorEditController
{
    #[Route('/graphing/colors/new', name: 'palette_color_create', methods: ['GET', 'HEAD', 'POST'])]
    public function create(Request $request, ConsoleAccess $console, PaletteColorAccess $access, PaletteColorStore $store, SavePaletteColor $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $access->authorize();
        } catch (PaletteColorAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to authorize Color access.', [], 'palette'), 502, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit(null, null, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    #[Route('/graphing/colors/{id}/edit', name: 'palette_color_edit', requirements: ['id' => '[1-9][0-9]{0,9}'], methods: ['GET', 'HEAD', 'POST'])]
    public function update(int $id, Request $request, ConsoleAccess $console, FindPaletteColor $find, PaletteColorStore $store, SavePaletteColor $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $actor = $console->consoleActor();
        if ($actor === null) {
            return new Response($translator->trans('Access denied.', [], 'palette'), 401, ['Cache-Control' => 'private, no-store']);
        }
        try {
            $preset = $find($id);
        } catch (PaletteColorAccessDenied $error) {
            return $this->denied($error, $translator);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to load Color. Reload before retrying.', [], 'palette'), 502, ['Cache-Control' => 'private, no-store']);
        }
        if ($preset === null) {
            return new Response($translator->trans('Color not found.', [], 'palette'), 404, ['Cache-Control' => 'private, no-store']);
        }
        return $this->edit($id, $preset, $request, $store, $save, $forms, $twig, $urls, $translator);
    }

    private function edit(?int $id, ?PaletteColor $preset, Request $request, PaletteColorStore $store, SavePaletteColor $save, FormFactoryInterface $forms, Environment $twig, UrlGeneratorInterface $urls, TranslatorInterface $translator): Response
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            $filters = $this->filterContext($request, $store);
        } catch (\InvalidArgumentException) {
            return new Response($translator->trans('Invalid color filters.', [], 'palette'), 400, $headers);
        } catch (\Throwable) {
            return new Response($translator->trans('Unable to prepare Color form.', [], 'palette'), 502, $headers);
        }
        $route = $id === null ? 'palette_color_create' : 'palette_color_edit';
        $form = $forms->create(PaletteColorType::class, [
            'name' => $preset?->name ?? '',
            'hex' => $preset?->hex ?? '',
            'revision' => $preset?->revision ?? '',
        ], [
            'action' => $urls->generate($route, ($id === null ? [] : ['id' => $id]) + $filters),
            'read_only' => $preset?->readOnly ?? false,
            'csrf_token_id' => $id === null ? 'palette_color_create' : 'palette_color_edit',
        ]);
        $form->handleRequest($request);
        $status = $request->isMethod('POST') ? 422 : 200;
        if ($form->isSubmitted()) {
            if ($form->getExtraData() !== []) {
                $form->addError(new FormError($translator->trans('Unexpected fields were submitted.', [], 'palette')));
            }
            if ($form->isValid()) {
                $data = $form->getData();
                $this->validate($form, $data, $translator);
                if ($form->isValid()) {
                    try {
                        $saved = $save($id, (string) $data['name'], (string) $data['hex'], is_string($data['revision']) ? $data['revision'] : null);
                        return new RedirectResponse($urls->generate('palette_color_edit', ['id' => $saved] + $filters + ['saved' => 1]), 303, $headers);
                    } catch (PaletteColorAccessDenied $error) {
                        return $this->denied($error, $translator);
                    } catch (\InvalidArgumentException $error) {
                        $form->addError(new FormError($translator->trans($error->getMessage(), [], 'palette')));
                        if (str_contains($error->getMessage(), 'changed since')) {
                            $status = 409;
                        }
                    } catch (\Throwable) {
                        $status = 502;
                        $form->addError(new FormError($translator->trans('Save outcome is uncertain. Reload before retrying.', [], 'palette')));
                    }
                }
            }
        }
        return new Response($twig->render('graphing/palette_color_edit.html.twig', [
            'preset' => $preset,
            'form' => $form->createView(),
            'saved' => $request->query->get('saved') === '1',
            'filters' => $filters,
        ]), $status, $headers);
    }

    private function filterContext(Request $request, PaletteColorStore $store): array
    {
        $query = $request->query->all();
        unset($query['saved']);
        if ($query === []) {
            return [];
        }
        return PaletteColorFilters::fromQuery($query, $store->defaultRows(), $store->defaultHasGraphs())->query();
    }

    private function validate(\Symfony\Component\Form\FormInterface $form, array $data, TranslatorInterface $translator): void
    {
        try {
            PaletteColor::validate((string) ($data['name'] ?? ''), (string) ($data['hex'] ?? ''));
        } catch (\InvalidArgumentException $error) {
            $form->addError(new FormError($translator->trans($error->getMessage(), [], 'palette')));
        }
    }

    private function denied(PaletteColorAccessDenied $error, TranslatorInterface $translator): Response
    {
        return new Response($translator->trans('Access denied.', [], 'palette'), $error->unauthenticated ? 401 : 403, ['Cache-Control' => 'private, no-store']);
    }
}
